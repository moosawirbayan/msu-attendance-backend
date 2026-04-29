<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: DELETE, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';
require_once '../core/Response.php';

$database = new Database();
$db = $database->getConnection();

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
$token = str_replace('Bearer ', '', $authHeader);

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit();
}

$decoded = explode(':', base64_decode($token));
$userId = $decoded[0] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

// ✅ Works for both DELETE and POST
$data = json_decode(file_get_contents('php://input'));

$student_id = $data->student_id ?? null;
$class_id   = $data->class_id   ?? null;

if (!$student_id || !$class_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing student_id or class_id']);
    exit();
}

try {
    $verifyStmt = $db->prepare('SELECT id FROM classes WHERE id = ? AND instructor_id = ?');
    $verifyStmt->execute([$class_id, $userId]);
    if (!$verifyStmt->fetch(PDO::FETCH_ASSOC)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $stmt = $db->prepare('DELETE FROM enrollments WHERE student_id = ? AND class_id = ?');
    $stmt->execute([$student_id, $class_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Student removed successfully']);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Enrollment not found']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}
?>