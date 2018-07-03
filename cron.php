<?php
date_default_timezone_set('UTC');

require_once __DIR__ . "/vendor/autoload.php";

$crawl = new \Eduid\Rsd\Crawler("/etc/eduid/database.ini");
$crawl->checkServices();
?>
