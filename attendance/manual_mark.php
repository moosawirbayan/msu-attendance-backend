<?php
/**
 * POST /attendance/manual_mark.php
 * Body: { classId: int, date: "YYYY-MM-DD", records: [ { studentId: int, status: "present"|"absent"|"late"|"excused" }, ... ] }
 * Upserts attendance records for the given date.
 */
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../core/Database.php';

$notificationServiceAvailable =
    file_exists(__DIR__ . '/../core/NotificationService.php') &&
    file_exists(__DIR__ . '/../vendor/autoload.php');
if ($notificationServiceAvailable) {
    require_once '../core/NotificationService.php';
}

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

$body    = json_decode(file_get_contents('php://input'), true);
$classId = $body['classId'] ?? null;
$date    = $body['date']    ?? date('Y-m-d');
$records = $body['records'] ?? [];

if (!$classId || empty($records)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'classId and records are required']); exit();
}

$validStatuses = ['present', 'absent', 'late', 'excused'];

try {
    // Verify the class belongs to this instructor
    $chk = $db->prepare("SELECT id, class_name, notify_parents FROM classes WHERE id = ? AND instructor_id = ?");
    $chk->execute([$classId, $userId]);
    $class = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
    }

    $db->beginTransaction();

    $saved = 0;
    $notificationsSent = 0;
    $notifier = null;
    $notifyEnabled = ((int)($class['notify_parents'] ?? 1) === 1);

    if ($notifyEnabled && $notificationServiceAvailable && class_exists('NotificationService')) {
        $notifier = new NotificationService();
    }

    foreach ($records as $rec) {
        $studentId = (int)($rec['studentId'] ?? 0);
        $status    = $rec['status'] ?? 'absent';

        if ($studentId <= 0 || !in_array($status, $validStatuses)) continue;

        // Check if a record already exists for this student/class/date
        $existing = $db->prepare("
            SELECT id FROM attendance
            WHERE student_id = ? AND class_id = ? AND DATE(check_in_time) = ?
            LIMIT 1
        ");
        $existing->execute([$studentId, $classId, $date]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            // Update existing record
            $upd = $db->prepare("UPDATE attendance SET status = ? WHERE id = ?");
            $upd->execute([$status, $row['id']]);
        } else {
            // Insert new record — use date + current time for the timestamp
            $checkInTime = $date . ' ' . date('H:i:s');
            $ins = $db->prepare("
                INSERT INTO attendance (student_id, class_id, check_in_time, status)
                VALUES (?, ?, ?, ?)
            ");
            $ins->execute([$studentId, $classId, $checkInTime, $status]);
        }
        $saved++;

        // Send parent email only for positive attendance statuses
        if ($notifyEnabled && in_array($status, ['present', 'late'], true)) {
            $studentStmt = $db->prepare("\n                SELECT parent_email, parent_name, first_name, middle_initial, last_name\n                FROM students\n                WHERE id = ?\n                LIMIT 1\n            ");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

            if (!empty($student['parent_email'])) {
                $nameParts = array_filter([
                    $student['first_name'] ?? null,
                    !empty($student['middle_initial']) ? $student['middle_initial'] . '.' : null,
                    $student['last_name'] ?? null,
                ]);
                $fullName = implode(' ', $nameParts);

                if ($notifier && $notifier->sendAttendanceEmail(
                    $student['parent_email'],
                    $student['parent_name'] ?? '',
                    $fullName,
                    $class['class_name'] ?? 'Class',
                    $status,
                    $date . ' ' . date('H:i:s')
                )) {
                    $notificationsSent++;
                }
            }
        }
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => "Attendance saved for {$saved} student(s).",
        'saved'   => $saved,
        'parent_notifications_sent' => $notificationsSent,
    ]);
} catch (Exception $e) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
