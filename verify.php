<?php
/*
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Landing page of Organization Manager View (Approvels)
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

require_once(dirname(__FILE__) . '/../../config.php');
require_once("lib.php");
require_once("nextpay_payment.php");
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/filelib.php');
global $CFG, $_SESSION, $USER, $DB, $OUTPUT;
$systemcontext = context_system::instance();
$plugininstance = new enrol_nextpay_plugin();
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_url('/enrol/nextpay/verify.php');
echo $OUTPUT->header();
$api_key = $plugininstance->get_config('api_key');
$testing = $plugininstance->get_config('checkproductionmode');
$amount = $_SESSION['totalcost'];

$data = new stdClass();
$plugin = enrol_get_plugin('nextpay');
$today = date('Y-m-d');
$Refnumber = $_POST["trans_id"];
$Resnumber = $_POST["order_id"];
$currencies = $plugininstance->get_config('currency');
if ($currencies == "IRR"){
    $amount = $amount/10;
}
$params = array(
    "api_key"=>$api_key,
    "amount"=>$amount,
    "order_id"=>$_POST["order_id"],
    "trans_id"=>$_POST["trans_id"]
);
$nextpay = new Nextpay_Payment();
$result = $nextpay->verify_request($params);
    $Status = $result;
    if ($Status == 0) { // Do business logic here for enrolment
        $coursename = $DB->get_field('course', 'fullname', ['id' => $_SESSION['courseid']]);
        $data->userid = $_SESSION['userid'];
        $data->courseid = $_SESSION['courseid'];
        $data->instanceid = $_SESSION['instanceid'];
        $coursecost = $DB->get_record('enrol', ['enrol' => 'nextpay', 'courseid' => $data->courseid]);
        $time = strtotime($today);
        $paidprice = $coursecost->cost;
        $data->amount = $paidprice;
        $data->refnumber = $Refnumber;
        $data->orderid = $Resnumber;
        $data->payment_status = $Status;
        $data->timeupdated = time();
        $data->item_name = $coursename;
        $data->receiver_email = $USER->email;
        $data->receiver_id = $_SESSION['userid'];

        if (!$user = $DB->get_record("user", ["id" => $data->userid])) {
            message_nextpay_error_to_admin("Not a valid user id", $data);
            die;
        }
        if (!$course = $DB->get_record("course", ["id" => $data->courseid])) {
            message_nextpay_error_to_admin("Not a valid course id", $data);
            die;
        }
        if (!$context = context_course::instance($course->id, IGNORE_MISSING)) {
            message_nextpay_error_to_admin("Not a valid context id", $data);
            die;
        }
        if (!$plugin_instance = $DB->get_record("enrol", ["id" => $data->instanceid, "status" => 0])) {
            message_nextpay_error_to_admin("Not a valid instance id", $data);
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

        // Use the queried course's full name for the item_name field.
        $data->item_name = $course->fullname;

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

            $eventdata = new \core\message\message();
            $eventdata->courseid          = $course->id;
            $eventdata->modulename        = 'moodle';
            $eventdata->component         = 'enrol_nextpay';
            $eventdata->name              = 'nextpay_enrolment';
            $eventdata->userfrom          = empty($teacher) ? core_user::get_noreply_user() : $teacher;
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

            $eventdata = new \core\message\message();
            $eventdata->courseid          = $course->id;
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
                $eventdata = new \core\message\message();
                $eventdata->courseid          = $course->id;
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
        echo '<h3 style="text-align:center; color: green;">با تشکر از شما، پرداخت شما با موفقیت انجام شد و به  درس انتخاب شده افزوده شدید.</h3>';
        echo '<div class="single_button" style="text-align:center;"><a href="' . $CFG->wwwroot . '/course/view.php?id=' . $course->id . '"><button>ورود به درس خریداری شده</button></a></div>';
    } else {
        echo '<div style="color:green; font-family:tahoma; direction:rtl; text-align:left">Error in the processing of payment operations , resulting in payment:' . $Status . ' <br /></div>';
    }


//----------------------------------------------------- HELPER FUNCTIONS --------------------------------------------------------------------------


function message_nextpay_error_to_admin($subject, $data)
{
    echo $subject;
    $admin = get_admin();
    $site = get_site();

    $message = "$site->fullname:  Transaction failed.\n\n$subject\n\n";

    foreach ($data as $key => $value) {
        $message .= "$key => $value\n";
    }

    $eventdata = new stdClass();
    $eventdata->modulename = 'moodle';
    $eventdata->component = 'enrol_nextpay';
    $eventdata->name = 'nextpay_enrolment';
    $eventdata->userfrom = $admin;
    $eventdata->userto = $admin;
    $eventdata->subject = "nextpay ERROR: " . $subject;
    $eventdata->fullmessage = $message;
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml = '';
    $eventdata->smallmessage = '';
    message_send($eventdata);
}

echo $OUTPUT->footer();
