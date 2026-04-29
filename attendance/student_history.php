<?php
/**
 * GET /attendance/student_history.php?student_id={id}&class_id={id}
 * Returns per-date attendance history for one student in one class.
 * No record on a session date = absent automatically.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../core/Database.php';

$database = new Database();
$db = $database->getConnection();

// ✅ PHP timezone lang — PH time na ang stored sa DB
date_default_timezone_set('Asia/Manila');

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

$studentId = $_GET['student_id'] ?? null;
$classId   = $_GET['class_id']   ?? null;

if (!$studentId || !$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'student_id and class_id are required']); exit();
}

try {
    // Verify class belongs to this instructor
    $chk = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $chk->execute([$classId, $userId]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
    }

    // ✅ Get ALL unique session dates for this class
    $datesStmt = $db->prepare("
        SELECT DISTINCT
            DATE_FORMAT(a.check_in_time, '%Y-%m-%d') AS date
        FROM attendance a
        WHERE a.class_id = ?
        ORDER BY date DESC
    ");
    $datesStmt->execute([$classId]);
    $sessionDates = array_column($datesStmt->fetchAll(PDO::FETCH_ASSOC), 'date');

    // ✅ Get actual attendance records for THIS student only
    $stmt = $db->prepare("
        SELECT
            DATE_FORMAT(a.check_in_time, '%Y-%m-%d') AS date,
            DATE_FORMAT(a.check_in_time, '%h:%i %p')  AS time_in,
            a.status
        FROM attendance a
        WHERE a.student_id = ? AND a.class_id = ?
    ");
    $stmt->execute([$studentId, $classId]);
    $rawRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Map records by date
    $recordsByDate = [];
    foreach ($rawRecords as $rec) {
        $recordsByDate[$rec['date']] = $rec;
    }

    // ✅ Build complete history — lahat ng session dates
    $records = [];
    foreach ($sessionDates as $date) {
        if (isset($recordsByDate[$date])) {
            $rec = $recordsByDate[$date];
            $records[] = [
                'date'           => $date,
                'date_formatted' => date('F d, Y', strtotime($date)),
                'time_in'        => $rec['time_in'],
                'status'         => $rec['status'],
            ];
        } else {
            // ✅ Walang record = absent automatically
            $records[] = [
                'date'           => $date,
                'date_formatted' => date('F d, Y', strtotime($date)),
                'time_in'        => '—',
                'status'         => 'absent',
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'records' => $records,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>