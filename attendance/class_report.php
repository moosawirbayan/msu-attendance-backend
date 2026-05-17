<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

// SIMPLE DEBUG — walang database needed
if (isset($_GET['debug'])) {
    echo json_encode(['debug' => 'OK', 'time' => date('Y-m-d H:i:s'), 'file' => __FILE__]);
    exit();
}

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
if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'class_id is required']); exit();
}

try {
    // Verify ownership
    $chk = $db->prepare("
        SELECT id, class_name, class_code, section 
        FROM classes 
        WHERE id = ? AND instructor_id = ?
    ");
    $chk->execute([$classId, $userId]);
    $class = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']); exit();
    }

    // Get all ACTIVE enrolled students
    // ── FIXED: added email, phone, and gender (COALESCE handles both 'gender' and 'sex' column names)
    $stmtStudents = $db->prepare("
        SELECT
            s.id,
            s.student_id,
            s.first_name,
            s.middle_initial,
            s.last_name,
            COALESCE(s.email, '')  AS email,
            COALESCE(s.phone, '')  AS phone,
            COALESCE(s.gender, s.sex, '') AS gender
        FROM students s
        INNER JOIN enrollments e ON e.student_id = s.id
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY s.last_name, s.first_name
    ");
    $stmtStudents->execute([$classId]);
    $students = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

    // Get all attendance records
    $stmtAtt = $db->prepare("
        SELECT
            a.student_id,
            DATE(a.check_in_time) AS date,
            a.status
        FROM attendance a
        WHERE a.class_id = ?
        ORDER BY a.check_in_time ASC
    ");
    $stmtAtt->execute([$classId]);
    $allAttendance = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique session dates
    $stmtDates = $db->prepare("
        SELECT DISTINCT DATE(check_in_time) AS date
        FROM attendance
        WHERE class_id = ?
        ORDER BY date ASC
    ");
    $stmtDates->execute([$classId]);
    $sessionDates = array_column($stmtDates->fetchAll(PDO::FETCH_ASSOC), 'date');

    // Group attendance by student id => date => status
    $attByStudent = [];
    foreach ($allAttendance as $rec) {
        $attByStudent[$rec['student_id']][$rec['date']] = $rec['status'];
    }

    // Build student records with day_records
    $result = [];
    foreach ($students as $student) {
        $dayRecords   = [];
        $presentCount = 0;
        $absentCount  = 0;
        $lateCount    = 0;

        foreach ($sessionDates as $date) {
            $status = $attByStudent[$student['id']][$date] ?? 'absent';
            $dayRecords[] = ['date' => $date, 'status' => $status];
            if ($status === 'present') {
                $presentCount++;
            } elseif ($status === 'late') {
                $presentCount++; // late counts as present for attendance rate
                $lateCount++;
            } else {
                $absentCount++;
            }
        }

        $totalSessions  = count($sessionDates);
        $attendanceRate = $totalSessions > 0
            ? round(($presentCount / $totalSessions) * 100, 1)
            : 0;

        $result[] = [
            'id'              => $student['id'],
            'student_id'      => $student['student_id'],
            'first_name'      => $student['first_name'],
            'middle_initial'  => $student['middle_initial'],
            'last_name'       => $student['last_name'],
            'email'           => $student['email'],   // ← ADDED
            'phone'           => $student['phone'],   // ← ADDED
            'gender'          => $student['gender'],  // ← ADDED
            'day_records'     => $dayRecords,
            'present_count'   => $presentCount,
            'absent_count'    => $absentCount,
            'late_count'      => $lateCount,          // ← ADDED (bonus)
            'total_sessions'  => $totalSessions,
            'attendance_rate' => $attendanceRate,
        ];
    }

    echo json_encode([
        'success'      => true,
        'generated_at' => date('Y-m-d H:i:s'),
        'summary'      => [
            'total_enrolled' => count($students),
            'total_sessions' => count($sessionDates),
            'session_dates'  => $sessionDates,
        ],
        'students' => $result,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>