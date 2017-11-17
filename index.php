<?php

include "classes/_HTTPRequest.php";
include "classes/RAE.php";
include "classes/Telegram.php";
require 'cfg/db.php';
require 'lib/Model.php';

try {
	Telegram::ManageRequest();
}catch (Exception $e){
	Telegram::Debug($e->getMessage());
	file_put_contents(dirname(__FILE__)."/error.txt", date("Y-m-d H:i:s") . "\r\n" . $e->getMessage() . "\r\n", FILE_APPEND);
}
