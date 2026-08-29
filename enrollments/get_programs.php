<?php
/**
 * Get Programs (distinct courses/programs from the instructor's own students)
 * Endpoint: GET /enrollments/get_programs.php
 *
 * Used by the "Manual" enroll tab: lets the instructor pick from programs
 * already used by students they've taught, instead of retyping the same
 * course name every time.
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

try {
    // Distinct, non-empty programs from students the instructor has taught
    // (in ANY of their own classes) — same "belongs to this instructor"
    // scoping used in search_students.php.
    $stmt = $db->prepare("
        SELECT DISTINCT s.program
        FROM students s
        INNER JOIN enrollments e ON e.student_id = s.id
        INNER JOIN classes c ON c.id = e.class_id
        WHERE c.instructor_id = ?
          AND s.program IS NOT NULL
          AND TRIM(s.program) <> ''
        ORDER BY s.program ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $programs = array_map(function ($row) {
        return $row['program'];
    }, $rows);

    echo json_encode([
        'success'  => true,
        'programs' => $programs,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>