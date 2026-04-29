<?php
/**
 * Get Class Attendance Report
 * Endpoint: GET /reports/class_report.php?class_id={id}
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';

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

$classId = $_GET['class_id'] ?? null;

if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit();
}

try {
    // Verify the class belongs to the instructor
    $verifyStmt = $db->prepare("SELECT id, class_name, class_code, section FROM classes WHERE id = ? AND instructor_id = ?");
    $verifyStmt->execute([$classId, $userId]);
    $class = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
        exit();
    }

    // Get total enrolled students
    $enrolledStmt = $db->prepare("SELECT COUNT(*) as total FROM enrollments WHERE class_id = ? AND status = 'active'");
    $enrolledStmt->execute([$classId]);
    $enrolled = $enrolledStmt->fetch(PDO::FETCH_ASSOC);

    // Get total unique session days
    $sessionsStmt = $db->prepare("SELECT COUNT(DISTINCT DATE(check_in_time)) as sessions FROM attendance WHERE class_id = ?");
    $sessionsStmt->execute([$classId]);
    $sessions = $sessionsStmt->fetch(PDO::FETCH_ASSOC);

    // Get today's attendance count
    $today = date('Y-m-d');
    $todayPresentStmt = $db->prepare("SELECT COUNT(*) as present FROM attendance WHERE class_id = ? AND DATE(check_in_time) = ? AND status = 'present'");
    $todayPresentStmt->execute([$classId, $today]);
    $todayPresent = $todayPresentStmt->fetch(PDO::FETCH_ASSOC);

    // Get per-student basic info (without counts — will recompute from day_records)
    $studentsStmt = $db->prepare("
        SELECT 
            s.id,
            s.student_id,
            s.first_name,
            s.middle_initial,
            s.last_name,
            s.email,
            s.phone
        FROM students s
        INNER JOIN enrollments e ON s.id = e.student_id AND e.class_id = ? AND e.status = 'active'
        ORDER BY s.last_name, s.first_name
    ");
    $studentsStmt->execute([$classId]);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all unique session dates for this class
    $sessionDatesStmt = $db->prepare("
        SELECT DISTINCT DATE(check_in_time) as session_date
        FROM attendance
        WHERE class_id = ?
        ORDER BY session_date DESC
    ");
    $sessionDatesStmt->execute([$classId]);
    $sessionDates = $sessionDatesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get all attendance records for this class
    $attendanceByDayStmt = $db->prepare("
        SELECT student_id, DATE(check_in_time) as day, status
        FROM attendance
        WHERE class_id = ?
    ");
    $attendanceByDayStmt->execute([$classId]);
    $attendanceByDayRows = $attendanceByDayStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build a map: student_id => [ date => status ]
    $studentDayStatusMap = [];
    foreach ($attendanceByDayRows as $row) {
        $sid = (string)$row['student_id'];
        if (!isset($studentDayStatusMap[$sid])) {
            $studentDayStatusMap[$sid] = [];
        }
        $studentDayStatusMap[$sid][$row['day']] = $row['status'];
    }

    // Build day_records for each student and recompute counts from it
    foreach ($students as &$student) {
        $sid = (string)$student['id'];
        $student['day_records'] = [];

        foreach ($sessionDates as $day) {
            $status = $studentDayStatusMap[$sid][$day] ?? 'absent';
            $student['day_records'][] = [
                'date'   => $day,
                'status' => $status,
            ];
        }

        // ✅ Recompute counts based on ALL class sessions (including pre-enrollment)
        $presentCount  = count(array_filter($student['day_records'], fn($r) => $r['status'] === 'present' || $r['status'] === 'late'));
        $absentCount   = count(array_filter($student['day_records'], fn($r) => $r['status'] === 'absent'));
        $totalSessions = count($student['day_records']);

        $student['present_count']   = $presentCount;
        $student['absent_count']    = $absentCount;
        $student['late_count']      = count(array_filter($student['day_records'], fn($r) => $r['status'] === 'late'));
        $student['total_sessions']  = $totalSessions;
        $student['attendance_rate'] = $totalSessions > 0
            ? round(($presentCount / $totalSessions) * 100, 1)
            : 0;
    }
    unset($student);

    // ✅ Overall rate based on recomputed values
    $totalPresent = array_sum(array_column($students, 'present_count'));
    $totalRecords = array_sum(array_column($students, 'total_sessions'));
    $overallRate  = $totalRecords > 0 ? round(($totalPresent / $totalRecords) * 100, 1) : 0;

    echo json_encode([
        'success'      => true,
        'class'        => $class,
        'summary'      => [
            'total_enrolled'          => (int)($enrolled['total'] ?? 0),
            'total_sessions'          => (int)($sessions['sessions'] ?? 0),
            'present_today'           => (int)($todayPresent['present'] ?? 0),
            'overall_attendance_rate' => $overallRate,
        ],
        'session_dates' => $sessionDates,
        'students'      => $students,
        'generated_at'  => date('Y-m-d H:i:s'),
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>