<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

// Debug — what time does the server think it is?
$utc    = new DateTime('now', new DateTimeZone('UTC'));
$ph     = clone $utc;
$ph->modify('+8 hours');

echo json_encode([
    'server_utc'     => $utc->format('Y-m-d H:i:s'),
    'computed_ph'    => $ph->format('Y-m-d H:i:s'),
    'php_date'       => date('Y-m-d H:i:s'),
    'php_timezone'   => date_default_timezone_get(),
]);
?>
