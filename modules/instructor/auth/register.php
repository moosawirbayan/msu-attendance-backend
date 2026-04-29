<?php
/**
 * Instructor Authentication Module
 * Register endpoint for instructors
 */

// CORS Headers - MUST be first
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../../core/Database.php';
require_once '../../core/Response.php';
require_once '../../core/Validator.php';

// Get request data
$data = json_decode(file_get_contents("php://input"));

// Validate input
$validator = new Validator();

$validator->required($data->name ?? '', 'name');
$validator->required($data->email ?? '', 'email');
$validator->email($data->email ?? '', 'email');
$validator->msuEmail($data->email ?? '', 'email');
$validator->required($data->department ?? '', 'department');
$validator->required($data->employee_id ?? '', 'employee_id');
$validator->required($data->password ?? '', 'password');
$validator->minLength($data->password ?? '', 6, 'password');

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

    // Check if email already exists
    $checkQuery = "SELECT id FROM users WHERE email = :email";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(":email", $data->email);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        Response::error("Email already registered", 409);
    }

    // Check if employee ID already exists
    $checkEmpQuery = "SELECT id FROM users WHERE employee_id = :employee_id";
    $checkEmpStmt = $db->prepare($checkEmpQuery);
    $checkEmpStmt->bindParam(":employee_id", $data->employee_id);
    $checkEmpStmt->execute();

    if ($checkEmpStmt->rowCount() > 0) {
        Response::error("Employee ID already registered", 409);
    }

    // Insert new user
    $query = "INSERT INTO users 
             (name, email, password, role, department, employee_id, phone, created_at) 
             VALUES 
             (:name, :email, :password, 'instructor', :department, :employee_id, :phone, NOW())";

    $stmt = $db->prepare($query);

    // Hash password
    $hashedPassword = password_hash($data->password, PASSWORD_BCRYPT);

    // Bind values
    $stmt->bindParam(":name", $data->name);
    $stmt->bindParam(":email", $data->email);
    $stmt->bindParam(":password", $hashedPassword);
    $stmt->bindParam(":department", $data->department);
    $stmt->bindParam(":employee_id", $data->employee_id);
    
    $phone = $data->phone ?? null;
    $stmt->bindParam(":phone", $phone);

    if ($stmt->execute()) {
        $userId = $db->lastInsertId();
        
        Response::success([
            'user_id' => $userId
        ], "Registration successful", 201);
    } else {
        Response::serverError("Unable to register user");
    }
} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    Response::serverError("An error occurred during registration");
}
