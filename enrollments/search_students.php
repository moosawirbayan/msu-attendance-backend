<?php
/**
 * Search Students (across the instructor's own classes)
 * Endpoint: GET /enrollments/search_students.php?query={text}&class_id={id}
 *
 * Used by the "Existing Student" enroll tab: lets an instructor find a
 * student they've already taught (in ANY of their classes) so that student
 * can be enrolled into ANOTHER class without retyping their details.
 *
 * Read-only — does not insert, update, or delete anything.
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

// Get query params
$query   = trim($_GET['query'] ?? '');
$classId = $_GET['class_id'] ?? null;

if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit();
}

// Require at least 2 characters so we don't return everything on every keystroke
if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit();
}

try {
    // Verify the target class belongs to this instructor
    $verifyStmt = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $verifyStmt->execute([$classId, $userId]);

    if ($verifyStmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
        exit();
    }

    $like = '%' . $query . '%';

    // Students the instructor has taught before (in ANY of their own classes),
    // matching the search term, excluding students already ACTIVELY enrolled
    // in this specific target class.
    $stmt = $db->prepare("
        SELECT DISTINCT
            s.id, s.student_id, s.first_name, s.middle_initial, s.last_name,
            s.gender, s.year_level, s.program, s.email, s.parent_name,
            s.parent_email, s.phone
        FROM students s
        INNER JOIN enrollments e ON e.student_id = s.id
        INNER JOIN classes c ON c.id = e.class_id
        WHERE c.instructor_id = ?
          AND (
                s.student_id LIKE ?
             OR s.first_name LIKE ?
             OR s.last_name LIKE ?
             OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?
          )
          AND NOT EXISTS (
                SELECT 1 FROM enrollments e2
                WHERE e2.student_id = s.id
                  AND e2.class_id = ?
                  AND e2.status = 'active'
          )
        ORDER BY s.last_name, s.first_name
        LIMIT 20
    ");
    $stmt->execute([$userId, $like, $like, $like, $like, $classId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'students' => $students,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
