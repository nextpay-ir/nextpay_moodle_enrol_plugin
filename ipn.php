<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Listens for Instant Payment Notification from nextpay
 *
 * This script waits for Payment notification from nextpay,
 * then double checks that data by sending it back to nextpay.
 * If nextpay verifies this then it sets up the enrolment for that
 * user.
 *
 * @package    enrol_nextpay
 * Created by NextPay.ir
 * author: Nextpay Company
 * ID: @nextpay
 * Date: 2018/10/27
 * Time: 5:05 PM
 * Website: NextPay.ir
 * Email: info@nextpay.ir
 * @copyright 2018
 */

// Disable moodle specific debug messages and any errors in output,
// comment out when debugging or better look into error log!
define('NO_DEBUG_DISPLAY', true);

require("../../config.php");
require_once("lib.php");
require_once($CFG->libdir.'/eventslib.php');
require_once($CFG->libdir.'/enrollib.php');
require_once($CFG->libdir . '/filelib.php');

// nextpay does not like when we return error messages here,
// the custom handler just logs exceptions and stops.
set_exception_handler('enrol_nextpay_ipn_exception_handler');

/// Keep out casual intruders
if (empty($_POST) or !empty($_GET)) {
    print_error("Sorry, you can not use the script that way.");
}

/// Read all the data from nextpay and get it ready for later;
/// we expect only valid UTF-8 encoding, it is the responsibility
/// of user to set it up properly in nextpay business account,
/// it is documented in docs wiki.

$req = 'cmd=_notify-validate';

$data = new stdClass();

foreach ($_POST as $key => $value) {
    $req .= "&$key=".urlencode($value);
    $data->$key = $value;
}

$custom = explode('-', $data->custom);
$data->userid           = (int)$custom[0];
$data->courseid         = (int)$custom[1];
$data->instanceid       = (int)$custom[2];
$data->payment_gross    = $data->mc_gross;
$data->payment_currency = $data->mc_currency;
$data->timeupdated      = time();


/// get the user and course records

if (! $user = $DB->get_record("user", array("id"=>$data->userid))) {
    message_nextpay_error_to_admin("Not a valid user id", $data);
    die;
}

if (! $course = $DB->get_record("course", array("id"=>$data->courseid))) {
    message_nextpay_error_to_admin("Not a valid course id", $data);
    die;
}

if (! $context = context_course::instance($course->id, IGNORE_MISSING)) {
    message_nextpay_error_to_admin("Not a valid context id", $data);
    die;
}

if (! $plugin_instance = $DB->get_record("enrol", array("id"=>$data->instanceid, "status"=>0))) {
    message_nextpay_error_to_admin("Not a valid instance id", $data);
    die;
}

$plugin = enrol_get_plugin('nextpay');

/// Open a connection back to nextpay to validate the data
$nextpayaddr = empty($CFG->usenextpaysandbox) ? 'www.nextpay.com' : 'www.sandbox.nextpay.com';
$c = new curl();
$options = array(
    'returntransfer' => true,
    'httpheader' => array('application/x-www-form-urlencoded', "Host: $nextpayaddr"),
    'timeout' => 30,
    'CURLOPT_HTTP_VERSION' => CURL_HTTP_VERSION_1_1,
);
$location = "https://$nextpayaddr/cgi-bin/webscr";
$result = $c->post($location, $req, $options);

if (!$result) {  /// Could not connect to nextpay - FAIL
    echo "<p>Error: could not access nextpay.com</p>";
    message_nextpay_error_to_admin("Could not access nextpay.com to verify payment", $data);
    die;
}

/// Connection is OK, so now we post the data to validate it

/// Now read the response and check if everything is OK.

if (strlen($result) > 0) {
    if (strcmp($result, "VERIFIED") == 0) {          // VALID PAYMENT!


        // check the payment_status and payment_reason

        // If status is not completed or pending then unenrol the student if already enrolled
        // and notify admin

        if ($data->payment_status != "Completed" and $data->payment_status != "Pending") {
            $plugin->unenrol_user($plugin_instance, $data->userid);
            message_nextpay_error_to_admin("Status not completed or pending. User unenrolled from course", $data);
            die;
        }

        // If currency is incorrectly set then someone maybe trying to cheat the system

        if ($data->mc_currency != $plugin_instance->currency) {
            message_nextpay_error_to_admin("Currency does not match course settings, received: ".$data->mc_currency, $data);
            die;
        }

        // If status is pending and reason is other than echeck then we are on hold until further notice
        // Email user to let them know. Email admin.

        if ($data->payment_status == "Pending" and $data->pending_reason != "echeck") {
            $eventdata = new stdClass();
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_nextpay';
            $eventdata->name              = 'nextpay_enrolment';
            $eventdata->userfrom          = get_admin();
            $eventdata->userto            = $user;
            $eventdata->subject           = "Moodle: nextpay payment";
            $eventdata->fullmessage       = "Your nextpay payment is pending.";
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);

            message_nextpay_error_to_admin("Payment pending", $data);
            die;
        }

        // If our status is not completed or not pending on an echeck clearance then ignore and die
        // This check is redundant at present but may be useful if nextpay extend the return codes in the future

        if (! ( $data->payment_status == "Completed" or
               ($data->payment_status == "Pending" and $data->pending_reason == "echeck") ) ) {
            die;
        }

        // At this point we only proceed with a status of completed or pending with a reason of echeck



        if ($existing = $DB->get_record("enrol_nextpay", array("txn_id"=>$data->txn_id))) {   // Make sure this transaction doesn't exist already
            message_nextpay_error_to_admin("Transaction $data->txn_id is being repeated!", $data);
            die;

        }

        if (core_text::strtolower($data->business) !== core_text::strtolower($plugin->get_config('nextpaybusiness'))) {   // Check that the email is the one we want it to be
            message_nextpay_error_to_admin("Business email is {$data->business} (not ".
                    $plugin->get_config('nextpaybusiness').")", $data);
            die;

        }

        if (!$user = $DB->get_record('user', array('id'=>$data->userid))) {   // Check that user exists
            message_nextpay_error_to_admin("User $data->userid doesn't exist", $data);
            die;
        }

        if (!$course = $DB->get_record('course', array('id'=>$data->courseid))) { // Check that course exists
            message_nextpay_error_to_admin("Course $data->courseid doesn't exist", $data);
            die;
        }

        $coursecontext = context_course::instance($course->id, IGNORE_MISSING);

        // Check that amount paid is the correct amount
        if ( (float) $plugin_instance->cost <= 0 ) {
            $cost = (float) $plugin->get_config('cost');
        } else {
            $cost = (float) $plugin_instance->cost;
        }

        // Use the same rounding of floats as on the enrol form.
        $cost = format_float($cost, 2, false);

        if ($data->payment_gross < $cost) {
            message_nextpay_error_to_admin("Amount paid is not enough ($data->payment_gross < $cost))", $data);
            die;

        }

        // ALL CLEAR !

        $DB->insert_record("enrol_nextpay", $data);

        if ($plugin_instance->enrolperiod) {
            $timestart = time();
            $timeend   = $timestart + $plugin_instance->enrolperiod;
        } else {
            $timestart = 0;
            $timeend   = 0;
        }

        // Enrol user
        $plugin->enrol_user($plugin_instance, $user->id, $plugin_instance->roleid, $timestart, $timeend);

        // Pass $view=true to filter hidden caps if the user cannot see them
        if ($users = get_users_by_capability($context, 'moodle/course:update', 'u.*', 'u.id ASC',
                                             '', '', '', '', false, true)) {
            $users = sort_by_roleassignment_authority($users, $context);
            $teacher = array_shift($users);
        } else {
            $teacher = false;
        }

        $mailstudents = $plugin->get_config('mailstudents');
        $mailteachers = $plugin->get_config('mailteachers');
        $mailadmins   = $plugin->get_config('mailadmins');
        $shortname = format_string($course->shortname, true, array('context' => $context));


        if (!empty($mailstudents)) {
            $a = new stdClass();
            $a->coursename = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id";

            $eventdata = new stdClass();
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_nextpay';
            $eventdata->name              = 'nextpay_enrolment';
            $eventdata->userfrom          = empty($teacher) ? core_user::get_support_user() : $teacher;
            $eventdata->userto            = $user;
            $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
            $eventdata->fullmessage       = get_string('welcometocoursetext', '', $a);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);

        }

        if (!empty($mailteachers) && !empty($teacher)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);

            $eventdata = new stdClass();
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_nextpay';
            $eventdata->name              = 'nextpay_enrolment';
            $eventdata->userfrom          = $user;
            $eventdata->userto            = $teacher;
            $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
            $eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
            $eventdata->fullmessageformat = FORMAT_PLAIN;
            $eventdata->fullmessagehtml   = '';
            $eventdata->smallmessage      = '';
            message_send($eventdata);
        }

        if (!empty($mailadmins)) {
            $a->course = format_string($course->fullname, true, array('context' => $coursecontext));
            $a->user = fullname($user);
            $admins = get_admins();
            foreach ($admins as $admin) {
                $eventdata = new stdClass();
                $eventdata->modulename        = 'moodle';
                $eventdata->component         = 'enrol_nextpay';
                $eventdata->name              = 'nextpay_enrolment';
                $eventdata->userfrom          = $user;
                $eventdata->userto            = $admin;
                $eventdata->subject           = get_string("enrolmentnew", 'enrol', $shortname);
                $eventdata->fullmessage       = get_string('enrolmentnewuser', 'enrol', $a);
                $eventdata->fullmessageformat = FORMAT_PLAIN;
                $eventdata->fullmessagehtml   = '';
                $eventdata->smallmessage      = '';
                message_send($eventdata);
            }
        }

    } else if (strcmp ($result, "INVALID") == 0) { // ERROR
        $DB->insert_record("enrol_nextpay", $data, false);
        message_nextpay_error_to_admin("Received an invalid payment notification!! (Fake payment?)", $data);
    }
}

exit;


//--- HELPER FUNCTIONS --------------------------------------------------------------------------------------


function message_nextpay_error_to_admin($subject, $data) {
    echo $subject;
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

    foreach ($data as $key => $value) {
        $message .= "$key => $value\n";
    }

    $eventdata = new stdClass();
    $eventdata->modulename        = 'moodle';
    $eventdata->component         = 'enrol_nextpay';
    $eventdata->name              = 'nextpay_enrolment';
    $eventdata->userfrom          = $admin;
    $eventdata->userto            = $admin;
    $eventdata->subject           = "nextpay ERROR: ".$subject;
    $eventdata->fullmessage       = $message;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';
    $eventdata->smallmessage      = '';
    message_send($eventdata);
}

/**
 * Silent exception handler.
 *
 * @param Exception $ex
 * @return void - does not return. Terminates execution!
 */
function enrol_nextpay_ipn_exception_handler($ex) {
    $info = get_exception_info($ex);

    $logerrmsg = "enrol_nextpay IPN exception handler: ".$info->message;
    if (debugging('', DEBUG_NORMAL)) {
        $logerrmsg .= ' Debug: '.$info->debuginfo."\n".format_backtrace($info->backtrace, true);
    }
    error_log($logerrmsg);

    exit(0);
}
