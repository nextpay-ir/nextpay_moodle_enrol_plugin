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
 * nextpay enrolments plugin settings and presets.
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

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    //--- settings ------------------------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_nextpay_settings', '', get_string('pluginname_desc', 'enrol_nextpay')));

    $settings->add(new admin_setting_configtext('enrol_nextpay/api_key',
                   get_string('api_key', 'enrol_nextpay'),
                   'Copy API Login ID from merchant account & paste here', '', PARAM_RAW));;
    $settings->add(new admin_setting_configcheckbox('enrol_nextpay/checkproductionmode',
                   get_string('checkproductionmode', 'enrol_nextpay'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_nextpay/mailstudents', get_string('mailstudents', 'enrol_nextpay'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_nextpay/mailteachers', get_string('mailteachers', 'enrol_nextpay'), '', 0));

    $settings->add(new admin_setting_configcheckbox('enrol_nextpay/mailadmins', get_string('mailadmins', 'enrol_nextpay'), '', 0));

    // Note: let's reuse the ext sync constants and strings here, internally it is very similar,
    //       it describes what should happen when users are not supposed to be enrolled any more.
    $options = array(
        ENROL_EXT_REMOVED_KEEP           => get_string('extremovedkeep', 'enrol'),
        ENROL_EXT_REMOVED_SUSPENDNOROLES => get_string('extremovedsuspendnoroles', 'enrol'),
        ENROL_EXT_REMOVED_UNENROL        => get_string('extremovedunenrol', 'enrol'),
    );
    $settings->add(new admin_setting_configselect('enrol_nextpay/expiredaction', get_string('expiredaction', 'enrol_nextpay'), get_string('expiredaction_help', 'enrol_nextpay'), ENROL_EXT_REMOVED_SUSPENDNOROLES, $options));

    //--- enrol instance defaults ----------------------------------------------------------------------------
    $settings->add(new admin_setting_heading('enrol_nextpay_defaults',
        get_string('enrolinstancedefaults', 'admin'), get_string('enrolinstancedefaults_desc', 'admin')));

    $options = array(ENROL_INSTANCE_ENABLED  => get_string('yes'),
                     ENROL_INSTANCE_DISABLED => get_string('no'));
    $settings->add(new admin_setting_configselect('enrol_nextpay/status',
        get_string('status', 'enrol_nextpay'), get_string('status_desc', 'enrol_nextpay'), ENROL_INSTANCE_DISABLED, $options));

    $settings->add(new admin_setting_configtext('enrol_nextpay/cost', get_string('cost', 'enrol_nextpay'), '', 0, PARAM_FLOAT, 4));

    $nextpaycurrencies = enrol_get_plugin('nextpay')->get_currencies();
    $settings->add(new admin_setting_configselect('enrol_nextpay/currency', get_string('currency', 'enrol_nextpay'), '', 'TOM', $nextpaycurrencies));

    if (!during_initial_install()) {
        $options = get_default_enrol_roles(context_system::instance());
        $student = get_archetype_roles('student');
        $student = reset($student);
        $settings->add(new admin_setting_configselect('enrol_nextpay/roleid',
            get_string('defaultrole', 'enrol_nextpay'), get_string('defaultrole_desc', 'enrol_nextpay'), $student->id, $options));
    }

    $settings->add(new admin_setting_configduration('enrol_nextpay/enrolperiod',
        get_string('enrolperiod', 'enrol_nextpay'), get_string('enrolperiod_desc', 'enrol_nextpay'), 0));
}
