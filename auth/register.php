<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed: ' . $e->getMessage()]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit();
}

// Simple validation
if (empty($data->name) || empty($data->email) || empty($data->password) || empty($data->department) || empty($data->employee_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields required']);
    exit();
}

$email = trim($data->email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit();
}

try {
    // Check if email exists
    $check = $db->prepare("SELECT id FROM users WHERE email = ?");
    $check->execute([$email]);
    
    if ($check->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit();
    }

    // Check if employee_id exists
    $checkEmp = $db->prepare("SELECT id FROM users WHERE employee_id = ?");
    $checkEmp->execute([$data->employee_id]);

    if ($checkEmp->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Employee ID already registered']);
        exit();
    }
    
    // Insert user
    $stmt = $db->prepare("INSERT INTO users (name, email, password, role, department, employee_id, created_at) VALUES (?, ?, ?, 'instructor', ?, ?, NOW())");
    
    $hashed = password_hash($data->password, PASSWORD_BCRYPT);
    
    if ($stmt->execute([$data->name, $email, $hashed, $data->department, $data->employee_id])) {
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Registration successful',
            'user_id' => $db->lastInsertId()
        ]);
    } else {
        throw new Exception('Database insert failed');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
