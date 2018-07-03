<?php

date_default_timezone_set('UTC');

require_once __DIR__ . "/vendor/autoload.php";

$service = new RESTling\OpenApi();
$service->loadConfigFile(__DIR__ . "/api/rsdDiscovery.json");

$service->run(new \Eduid\Rsd\Discovery("/etc/eduid/database.ini"));
?>
