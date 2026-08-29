<?php
/**
 * Send QR Codes to All Enrolled Students' Emails
 * Endpoint: POST /attendance/send_qr_emails.php
 * Body: { "class_id": 123 }
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';
require_once '../core/NotificationService.php';

$database = new Database();
$db = $database->getConnection();

// ── Auth: same token pattern as get_students.php ──
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

// ── Get class_id from request body ──
$data = json_decode(file_get_contents('php://input'), true);
$classId = $data['class_id'] ?? null;

if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'class_id is required']);
    exit();
}

try {
    // ── Verify the class belongs to the instructor (same as get_students.php) ──
    $verifyStmt = $db->prepare("SELECT id, class_name, class_code FROM classes WHERE id = ? AND instructor_id = ?");
    $verifyStmt->execute([$classId, $userId]);
    $classInfo = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$classInfo) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
        exit();
    }

    // ── Get enrolled + active students ──
    $stmt = $db->prepare("
        SELECT s.id, s.student_id, s.first_name, s.middle_initial, s.last_name, s.email
        FROM students s
        INNER JOIN enrollments e ON s.id = e.student_id
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifier = new NotificationService();
    $sentCount = 0;
    $skipped = [];

    foreach ($students as $student) {
        $fullName = trim($student['first_name'] . ' ' .
            ($student['middle_initial'] ? $student['middle_initial'] . '. ' : '') .
            $student['last_name']);

        if (empty($student['email'])) {
            $skipped[] = $fullName . ' (no email on file)';
            continue;
        }

        // ── SAME QR format gaya ng app: id|student_id|fullName ──
        $qrValue = $student['id'] . '|' . $student['student_id'] . '|' . $fullName;

        $sent = $notifier->sendQrCodeEmail(
            $student['email'],
            $fullName,
            $student['student_id'],
            $classInfo['class_name'],
            $qrValue
        );

        if ($sent) {
            $sentCount++;
        } else {
            $skipped[] = $fullName . ' (send failed)';
        }
    }

    echo json_encode([
        'success'    => true,
        'sent_count' => $sentCount,
        'total'      => count($students),
        'skipped'    => $skipped,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>