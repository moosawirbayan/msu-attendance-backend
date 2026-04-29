<?php
/**
 * Update Student Information
 * Endpoint: PUT /enrollments/update_student.php
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: PUT, OPTIONS");
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

$data = json_decode(file_get_contents("php://input"));

$studentDbId   = $data->id          ?? null;
$studentNumber = trim($data->student_id     ?? '');
$firstName     = trim($data->first_name     ?? '');
$middleInitial = trim($data->middle_initial ?? '');
$lastName      = trim($data->last_name      ?? '');
$program       = trim($data->program        ?? '');
$email         = trim($data->email          ?? '');
$parentEmail   = trim($data->parent_email   ?? '');
$parentName    = trim($data->parent_name    ?? '');
$phone         = trim($data->phone          ?? '');

if (!$studentDbId || !$studentNumber || !$firstName || !$lastName || !$program) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'id, student_id, first_name, last_name, and program are required']);
    exit();
}

try {
    // Verify the student is enrolled in at least one class that belongs to this instructor
    $authStmt = $db->prepare("\n        SELECT s.id FROM students s\n        INNER JOIN enrollments e ON s.id = e.student_id\n        INNER JOIN classes c ON e.class_id = c.id\n        WHERE s.id = ? AND c.instructor_id = ?\n        LIMIT 1\n    ");
    $authStmt->execute([$studentDbId, $userId]);

    if (!$authStmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to edit this student']);
        exit();
    }

    // Check for duplicate student_id (excluding current student)
    $dupStmt = $db->prepare("SELECT id FROM students WHERE student_id = ? AND id != ?");
    $dupStmt->execute([$studentNumber, $studentDbId]);
    if ($dupStmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Student ID already exists for another student']);
        exit();
    }

    // Check for duplicate email (excluding current student, only if email provided)
    if (!empty($email)) {
        $emailDupStmt = $db->prepare("SELECT id FROM students WHERE email = ? AND id != ?");
        $emailDupStmt->execute([$email, $studentDbId]);
        if ($emailDupStmt->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Email already exists for another student']);
            exit();
        }
    }

    if (!empty($parentEmail) && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid parent email format']);
        exit();
    }

    $updateStmt = $db->prepare("\n        UPDATE students\n        SET student_id = ?, first_name = ?, middle_initial = ?, last_name = ?, program = ?, email = ?, parent_email = ?, parent_name = ?, phone = ?\n        WHERE id = ?\n    ");
    $updateStmt->execute([
        $studentNumber,
        $firstName,
        !empty($middleInitial) ? $middleInitial : null,
        $lastName,
        $program,
        !empty($email) ? $email : null,
        !empty($parentEmail) ? $parentEmail : null,
        !empty($parentName) ? $parentName : null,
        !empty($phone) ? $phone : null,
        $studentDbId,
    ]);

    echo json_encode(['success' => true, 'message' => 'Student updated successfully']);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
