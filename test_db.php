<?php
// PANSAMANTALA LANG - burahin pagkatapos!
$host = "bgdjd7pnoftx1p4yq4bj-mysql.services.clever-cloud.com";
$db   = "bgdjd7pnoftx1p4yq4bj";
$user = "u3miutjfjda1dnby";
$pass = "mZgVKFLZ31Dm4i7GbWQS";
$port = "3306";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db;port=$port", $user, $pass);
    echo json_encode(["status" => "SUCCESS", "message" => "Connected to database!"]);
} catch(PDOException $e) {
    echo json_encode(["status" => "FAILED", "error" => $e->getMessage()]);
}