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
 * nextpay enrolment plugin version specification.
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

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2018102700;        // The current plugin version (Date: YYYYMMDDXX)
$plugin->requires  = 2018050800;        // Requires this Moodle version
$plugin->component = 'enrol_nextpay';    // Full name of the plugin (used for diagnostics)
$plugin->cron      = 60;
