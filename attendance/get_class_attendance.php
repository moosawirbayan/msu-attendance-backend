<?php
/**
 * GET /attendance/get_class_attendance.php?class_id={id}&date={YYYY-MM-DD}
 * Returns all attendance records for a class on a given date.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../core/Database.php';

$database = new Database();
$db = $database->getConnection();

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
$token = str_replace('Bearer ', '', $authHeader);
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']); exit();
}
$decoded = explode(':', base64_decode($token));
$userId  = $decoded[0] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']); exit();
}

$classId = $_GET['class_id'] ?? null;
$date    = $_GET['date']     ?? date('Y-m-d');

if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'class_id is required']); exit();
}

try {
    // Verify ownership
    $chk = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $chk->execute([$classId, $userId]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
    }

    $stmt = $db->prepare("
        SELECT a.id, a.student_id, a.status,
               DATE_FORMAT(a.check_in_time, '%h:%i %p') AS time_in
        FROM attendance a
        WHERE a.class_id = ? AND DATE(a.check_in_time) = ?
    ");
    $stmt->execute([$classId, $date]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'records' => $records]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
