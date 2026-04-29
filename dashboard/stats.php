<?php
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

// ✅ MySQL timezone sa PH
$db->exec("SET time_zone = '+08:00'");

// ✅ PHP timezone sa PH — para consistent lahat
date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
$token = str_replace('Bearer ', '', $authHeader);

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit();
}

$decoded = explode(':', base64_decode($token));
$userId  = $decoded[0] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

try {
    // Get instructor info
    $userStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // Get total CURRENTLY enrolled students across all classes
    $enrolledStmt = $db->prepare("
        SELECT COUNT(DISTINCT e.student_id) as total 
        FROM enrollments e 
        JOIN classes c ON e.class_id = c.id 
        WHERE c.instructor_id = ?
    ");
    $enrolledStmt->execute([$userId]);
    $enrolled = $enrolledStmt->fetch(PDO::FETCH_ASSOC);

    // Get total classes
    $classesStmt = $db->prepare("SELECT COUNT(*) as total FROM classes WHERE instructor_id = ?");
    $classesStmt->execute([$userId]);
    $classCount = $classesStmt->fetch(PDO::FETCH_ASSOC);

    // ✅ Present today — PH time, enrolled students only
    $presentStmt = $db->prepare("
        SELECT COUNT(*) as total 
        FROM attendance a 
        JOIN classes c ON a.class_id = c.id 
        JOIN enrollments e 
            ON e.student_id = a.student_id 
            AND e.class_id = a.class_id
        WHERE c.instructor_id = ? 
        AND DATE(a.check_in_time) = ?
        AND a.status = 'present'
    ");
    $presentStmt->execute([$userId, $today]);
    $present = $presentStmt->fetch(PDO::FETCH_ASSOC);

    $totalEnrolled  = (int)($enrolled['total'] ?? 0);
    $presentCount   = (int)($present['total']  ?? 0);
    $absentCount    = max(0, $totalEnrolled - $presentCount);
    $attendanceRate = $totalEnrolled > 0
        ? min(100, round(($presentCount / $totalEnrolled) * 100))
        : 0;

    // ✅ Recent attendance — PH time
    $recentStmt = $db->prepare("
        SELECT
            CONCAT(
                s.first_name,
                CASE WHEN s.middle_initial IS NOT NULL 
                     THEN CONCAT(' ', s.middle_initial, '. ') 
                     ELSE ' ' END,
                s.last_name
            ) AS student_name,
            c.class_name,
            c.class_code,
            DATE_FORMAT(a.check_in_time, '%Y-%m-%d %H:%i:%s') AS checkin_time,
            a.status
        FROM attendance a
        JOIN students s ON a.student_id = s.id
        JOIN classes  c ON a.class_id   = c.id
        JOIN enrollments e
            ON e.student_id = a.student_id
            AND e.class_id = a.class_id
        WHERE c.instructor_id = ?
        ORDER BY a.check_in_time DESC
        LIMIT 5
    ");
    $recentStmt->execute([$userId]);
    $recentAttendance = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // ✅ Class breakdown — per subject attendance today
    $breakdownStmt = $db->prepare("
        SELECT
            c.class_code,
            c.class_name,
            COUNT(DISTINCT e.student_id) AS total,
            COUNT(DISTINCT CASE 
                WHEN a.status IN ('present', 'late') 
                AND DATE(a.check_in_time) = ?
                THEN a.student_id 
            END) AS present
        FROM classes c
        JOIN enrollments e ON e.class_id = c.id
        LEFT JOIN attendance a 
            ON a.student_id = e.student_id 
            AND a.class_id = c.id
        WHERE c.instructor_id = ?
        GROUP BY c.id, c.class_code, c.class_name
        ORDER BY c.class_code ASC
    ");
    $breakdownStmt->execute([$today, $userId]);
    $classBreakdownRaw = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format breakdown — cast to int
    $classBreakdown = array_map(function($row) {
        return [
            'class_code' => $row['class_code'],
            'class_name' => $row['class_name'],
            'present'    => (int)$row['present'],
            'total'      => (int)$row['total'],
        ];
    }, $classBreakdownRaw);

    // ✅ Active Classes — is_active = 1 lang
    $activeStmt = $db->prepare("
        SELECT
            c.id,
            c.class_code,
            c.class_name,
            c.room,
            COUNT(e.id) AS total_students
        FROM classes c
        LEFT JOIN enrollments e 
            ON e.class_id = c.id 
            AND e.status = 'active'
        WHERE c.instructor_id = ?
          AND c.is_active = 1
        GROUP BY c.id, c.class_code, c.class_name, c.room
        ORDER BY c.class_name ASC
    ");
    $activeStmt->execute([$userId]);
    $activeClassesRaw = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

    $activeClasses = array_map(function($row) {
        return [
            'id'             => (int)$row['id'],
            'class_code'     => $row['class_code'],
            'class_name'     => $row['class_name'],
            'room'           => $row['room'] ?? null,
            'total_students' => (int)$row['total_students'],
        ];
    }, $activeClassesRaw);

    // ✅ Display date — PH time galing sa PHP
    $displayDate = date('l, F j, Y');

    echo json_encode([
        'success' => true,
        'data'    => [
            'instructorName'   => $user['name'] ?? 'Instructor',
            'date'             => $displayDate,
            'enrolledStudents' => $totalEnrolled,
            'enrolledClasses'  => (int)($classCount['total'] ?? 0),
            'presentToday'     => $presentCount,
            'absentToday'      => $absentCount,
            'attendanceRate'   => $attendanceRate,
            'recentAttendance' => $recentAttendance,
            'classBreakdown'   => $classBreakdown,
            'activeClasses'    => $activeClasses,  // ✅ Bagong field
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>