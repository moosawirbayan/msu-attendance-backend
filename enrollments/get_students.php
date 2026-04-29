<?php
/**
 * Get Students Enrolled in a Class
 * Endpoint: GET /enrollments/get_students.php?class_id={id}
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

// Get user ID from token
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

// Get class_id parameter
$classId = $_GET['class_id'] ?? null;

if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit();
}

try {
    // Verify the class belongs to the instructor
    $verifyStmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $verifyStmt->execute([$classId, $userId]);
    
    if ($verifyStmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
        exit();
    }

    // Get enrolled students
    $stmt = $db->prepare("
        SELECT 
            s.*,
            e.enrolled_date,
            e.status as enrollment_status,
            (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.class_id = e.class_id AND a.status = 'present') as total_present,
            (SELECT COUNT(*) FROM attendance a WHERE a.student_id = s.id AND a.class_id = e.class_id) as total_sessions
        FROM students s
        INNER JOIN enrollments e ON s.id = e.student_id
        WHERE e.class_id = ? AND e.status = 'active'
        ORDER BY s.last_name, s.first_name
    ");
    $stmt->execute([$classId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate attendance rate for each student
    foreach ($students as &$student) {
        $student['attendance_rate'] = $student['total_sessions'] > 0 
            ? round(($student['total_present'] / $student['total_sessions']) * 100) 
            : 0;
    }

    echo json_encode([
        'success' => true,
        'students' => $students,
        'total' => count($students)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
