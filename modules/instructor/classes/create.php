<?php
/**
 * Instructor Classes Module
 * Create new class endpoint
 */

require_once '../../core/cors.php';
require_once '../../core/Database.php';
require_once '../../core/Response.php';
require_once '../../core/Validator.php';

// Get request data
$data = json_decode(file_get_contents("php://input"));

// Validate input
$validator = new Validator();

$validator->required($data->class_name ?? '', 'class_name');
$validator->required($data->class_code ?? '', 'class_code');
$validator->required($data->section ?? '', 'section');
$validator->required($data->instructor_id ?? '', 'instructor_id');
$validator->required($data->start_time ?? '', 'start_time');
$validator->required($data->end_time ?? '', 'end_time');
$validator->required($data->days ?? '', 'days');

if (!$validator->passes()) {
    Response::validationError($validator->getErrors());
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        Response::serverError("Database connection failed");
    }

    // Check if class code already exists for this instructor
    $checkQuery = "SELECT id FROM classes 
                   WHERE class_code = :class_code 
                   AND section = :section 
                   AND instructor_id = :instructor_id";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":class_code", $data->class_code);
    $checkStmt->bindParam(":section", $data->section);
    $checkStmt->bindParam(":instructor_id", $data->instructor_id);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        Response::error("You already have a class with this code and section", 409);
    }

    // Insert new class
    $query = "INSERT INTO classes 
             (instructor_id, class_name, class_code, section, description, 
              start_time, end_time, days, created_at) 
             VALUES 
             (:instructor_id, :class_name, :class_code, :section, :description,
              :start_time, :end_time, :days, NOW())";

    $stmt = $db->prepare($query);

    // Bind values
    $stmt->bindParam(":instructor_id", $data->instructor_id);
    $stmt->bindParam(":class_name", $data->class_name);
    $stmt->bindParam(":class_code", $data->class_code);
    $stmt->bindParam(":section", $data->section);
    
    $description = $data->description ?? null;
    $stmt->bindParam(":description", $description);
    
    $stmt->bindParam(":start_time", $data->start_time);
    $stmt->bindParam(":end_time", $data->end_time);
    $stmt->bindParam(":days", $data->days);

    if ($stmt->execute()) {
        $classId = $db->lastInsertId();
        
        // Return the created class
        $getQuery = "SELECT * FROM classes WHERE id = :id";
        $getStmt = $db->prepare($getQuery);
        $getStmt->bindParam(":id", $classId);
        $getStmt->execute();
        $class = $getStmt->fetch(PDO::FETCH_ASSOC);
        
        Response::success([
            'class' => $class
        ], "Class created successfully", 201);
    } else {
        Response::serverError("Unable to create class");
    }
} catch (Exception $e) {
    error_log("Create class error: " . $e->getMessage());
    Response::serverError("An error occurred while creating class");
}
