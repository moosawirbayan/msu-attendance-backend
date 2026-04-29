<?php
// backend/index.php
// API Entry Point and Documentation

header("Content-Type: application/json");

$response = array(
    "api_name" => "MSU Attendance API",
    "version" => "1.0.0",
    "status" => "active",
    "endpoints" => array(
        "authentication" => array(
            "login" => "POST /auth/login.php",
            "register" => "POST /auth/register.php"
        ),
        "attendance" => array(
            "mark" => "POST /attendance/mark.php",
            "stats" => "GET /attendance/stats.php?instructor_id={id}"
        ),
        "classes" => array(
            "get_all" => "GET /classes/get_all.php?instructor_id={id}"
        )
    ),
    "database" => array(
        "host" => "localhost",
        "database" => "msu_attendance_db",
        "status" => "Check connection below"
    )
);

// Test database connection
try {
    include_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        $response["database"]["status"] = "Connected successfully";
        $response["database"]["connection"] = "OK";
    } else {
        $response["database"]["status"] = "Connection failed";
        $response["database"]["connection"] = "ERROR";
    }
} catch (Exception $e) {
    $response["database"]["status"] = "Error: " . $e->getMessage();
    $response["database"]["connection"] = "ERROR";
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
