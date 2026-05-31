<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';

$notificationServiceAvailable =
    file_exists(__DIR__ . '/../core/NotificationService.php') &&
    file_exists(__DIR__ . '/../vendor/autoload.php');
if ($notificationServiceAvailable) {
    require_once '../core/NotificationService.php';
}

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));

$studentDbId = $data->studentId ?? null;
$classId     = $data->classId   ?? null;

if (!$studentDbId || !$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'studentId and classId are required']);
    exit();
}

try {
    // Get student info
    $studentStmt = $db->prepare("SELECT id, first_name, middle_initial, last_name, student_id FROM students WHERE id = ?");
    $studentStmt->execute([$studentDbId]);
    $student = $studentStmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Student not found']);
        exit();
    }

    // Check class status
    $classStatusStmt = $db->prepare("SELECT is_active FROM classes WHERE id = ? LIMIT 1");
    $classStatusStmt->execute([$classId]);
    $classStatus = $classStatusStmt->fetch(PDO::FETCH_ASSOC);

    if (!$classStatus || (int)$classStatus['is_active'] !== 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This class is inactive. Attendance marking is disabled.']);
        exit();
    }

    // Check enrollment
    $enrollStmt = $db->prepare("SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? AND status = 'active'");
    $enrollStmt->execute([$studentDbId, $classId]);
    if ($enrollStmt->rowCount() === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Student is not enrolled in this class']);
        exit();
    }

    // Check if may existing attendance record ngayon (absent o present)
    $today = (new DateTime('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $dupStmt = $db->prepare("SELECT id, status FROM attendance WHERE student_id = ? AND class_id = ? AND DATE(check_in_time) = ?");
    $dupStmt->execute([$studentDbId, $classId, $today]);
    $existing = $dupStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        if ($existing['status'] === 'present') {
            // Naka-present na — block na, ayaw nating double scan
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Attendance already marked as present for today']);
            exit();
        }

        // Status ay 'absent' — i-UPDATE to present via QR scan
        $updateStmt = $db->prepare("UPDATE attendance SET status = 'present', check_in_time = NOW() WHERE id = ?");
        $updateStmt->execute([$existing['id']]);
        $attendanceId = $existing['id'];
        $action = 'updated';

    } else {
        // Walang record pa — INSERT as present
        $markStmt = $db->prepare("INSERT INTO attendance (student_id, class_id, check_in_time, status) VALUES (?, ?, NOW(), 'present')");
        $markStmt->execute([$studentDbId, $classId]);
        $attendanceId = $db->lastInsertId();
        $action = 'inserted';
    }

    $nameParts = array_filter([$student['first_name'], $student['middle_initial'] ? $student['middle_initial'].'.' : null, $student['last_name']]);
    $fullName  = implode(' ', $nameParts);

    // Send email notification to parent/guardian if enabled for this class
    $notificationSent = false;
    $classStmt = $db->prepare("SELECT class_name, notify_parents FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);

    if ($class && (int)$class['notify_parents'] === 1) {
        $contactStmt = $db->prepare("SELECT parent_email, parent_name FROM students WHERE id = ? LIMIT 1");
        $contactStmt->execute([$studentDbId]);
        $contact = $contactStmt->fetch(PDO::FETCH_ASSOC);

        if (!empty($contact['parent_email']) && $notificationServiceAvailable && class_exists('NotificationService')) {
            $notifier = new NotificationService();
            $notificationSent = $notifier->sendAttendanceEmail(
                $contact['parent_email'],
                $contact['parent_name'] ?? '',
                $fullName,
                $class['class_name'] ?? 'Class',
                'present',
                date('Y-m-d H:i:s')
            );
        }
    }

    echo json_encode([
        'success'         => true,
        'message'         => 'Attendance marked successfully',
        'student_name'    => $fullName,
        'student_number'  => $student['student_id'],
        'attendance_id'   => $attendanceId,
        'action'          => $action,       // 'updated' or 'inserted'
        'parent_notified' => $notificationSent,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>