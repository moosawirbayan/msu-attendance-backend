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
$db       = $database->getConnection();

// ✅ PHP timezone — PH time
date_default_timezone_set('Asia/Manila');

// ✅ MySQL timezone — PH time din para consistent ang NOW()
$db->exec("SET time_zone = '+08:00'");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$data     = json_decode(file_get_contents("php://input"));
$class_id = isset($data->class_id) ? intval($data->class_id) : 0;

if (!$class_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'class_id is required']);
    exit();
}

try {
    // Get class info
    $classStmt = $db->prepare("SELECT id, class_name, end_time, notify_parents FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([$class_id]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Class not found']);
        exit();
    }

    // ✅ PH time na dahil date_default_timezone_set('Asia/Manila')
    $now      = date('H:i:s');
    $end_time = $class['end_time'];
    $today    = date('Y-m-d');

    if ($now < $end_time) {
        echo json_encode([
            'success' => false,
            'marked'  => 0,
            'message' => 'Class has not ended yet.',
        ]);
        exit();
    }

    // Get all active enrolled students
    $enrolledStmt = $db->prepare(
        "SELECT e.student_id, s.first_name, s.middle_initial, s.last_name,
                s.parent_email, s.parent_name
         FROM enrollments e
         JOIN students s ON s.id = e.student_id
         WHERE e.class_id = ? AND e.status = 'active'"
    );
    $enrolledStmt->execute([$class_id]);
    $enrolled = $enrolledStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($enrolled)) {
        echo json_encode(['success' => true, 'marked' => 0, 'message' => 'No enrolled students']);
        exit();
    }

    // ✅ Find students who already have a record today
    // Walang CONVERT_TZ — PH time na ang stored
    $studentIds   = array_column($enrolled, 'student_id');
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

    $existingStmt = $db->prepare(
        "SELECT DISTINCT student_id FROM attendance
         WHERE class_id = ?
           AND DATE(check_in_time) = ?
           AND student_id IN ($placeholders)"
    );
    $existingStmt->execute(array_merge([$class_id, $today], $studentIds));
    $alreadyRecorded = $existingStmt->fetchAll(PDO::FETCH_COLUMN);

    // ✅ NOW() ay PH time na dahil SET time_zone = '+08:00'
    $insertStmt = $db->prepare(
        "INSERT INTO attendance (student_id, class_id, check_in_time, status)
         VALUES (?, ?, NOW(), 'absent')"
    );

    $notifier = null;
    if ($notificationServiceAvailable && class_exists('NotificationService') && (int)$class['notify_parents'] === 1) {
        $notifier = new NotificationService();
    }

    $markedCount = 0;
    foreach ($enrolled as $row) {
        $sid = $row['student_id'];

        if (in_array($sid, $alreadyRecorded)) continue;

        $insertStmt->execute([$sid, $class_id]);
        $markedCount++;

        // Send parent email (non-blocking)
        if ($notifier && !empty($row['parent_email'])) {
            $nameParts = array_filter([
                $row['first_name'],
                $row['middle_initial'] ? $row['middle_initial'] . '.' : null,
                $row['last_name'],
            ]);
            $fullName = implode(' ', $nameParts);

            try {
                $notifier->sendAttendanceEmail(
                    $row['parent_email'],
                    $row['parent_name'] ?? '',
                    $fullName,
                    $class['class_name'] ?? 'Class',
                    'absent',
                    date('Y-m-d H:i:s') // ✅ PH time na ito
                );
            } catch (Exception $notifErr) {
                error_log("Auto-absent email error for student $sid: " . $notifErr->getMessage());
            }
        }
    }

    echo json_encode([
        'success' => true,
        'marked'  => $markedCount,
        'message' => $markedCount > 0
            ? "{$markedCount} student(s) automatically marked absent"
            : 'All students already have attendance records for today',
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>