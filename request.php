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
global $CFG, $_SESSION, $USER, $DB;

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$plugininstance = new enrol_nextpay_plugin();
if (!empty($_POST['multi'])) {
    $instance_array = unserialize($_POST['instances']);
    $ids_array = unserialize($_POST['ids']);
    $_SESSION['idlist']  =implode(',', $ids_array);
    $_SESSION['inslist']  =implode(',', $instance_array);
    $_SESSION['multi'] = $_POST['multi'];
 } else {
    $_SESSION['courseid'] = $_POST['course_id'];
    $_SESSION['instanceid'] = $_POST['instance_id'];
}
$_SESSION['totalcost']= $_POST['amount'];
$_SESSION['userid'] = $USER->id;
$amount = $_POST['amount'];

$api_key = $plugininstance->get_config('api_key');
$testing = $plugininstance->get_config('checkproductionmode');
$ReturnPath = $CFG->wwwroot.'/enrol/nextpay/verify.php';
$ResNumber = date('YmdHis');// Order Id In Your System
$Description = 'پرداخت شهریه ' . $_POST['item_name'];
$Paymenter = $USER->firstname. ' ' .$USER->lastname;
$Email = $USER->email;
$Mobile = $USER->phone1;

$order_id = time();
$currencies = $plugininstance->get_config('currency');
if ($currencies == "IRR"){
    $amount = $amount/10;
}
$params = array(
    "api_key"=>$api_key,
    "amount"=>$amount,
    "order_id"=>$order_id,
    "callback_uri"=>$ReturnPath
);
$nextpay = new Nextpay_Payment($params);
$result = $nextpay->token();
$trans_id = $result->trans_id;
$code = $result->code;
if (intval($code) == -1) $nextpay->send($trans_id);
else echo $nextpay->code_error(intval($code));
