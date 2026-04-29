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

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));

if (!$data || empty($data->email) || empty($data->password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password required']);
    exit();
}

$email = trim($data->email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit();
}

try {
    $stmt = $db->prepare("SELECT id, name, email, password, role, department, employee_id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!password_verify($data->password, $user['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid password']);
        exit();
    }
    
    // Success
    unset($user['password']);
    $token = base64_encode($user['id'] . ':' . time());
    
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'data' => [
            'user' => $user,
            'token' => $token
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
