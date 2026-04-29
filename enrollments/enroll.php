<?php
/**
 * Enroll Student in a Class
 * Endpoint: POST /enrollments/enroll.php
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';
require_once '../core/Response.php';
require_once '../core/Validator.php';

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

// Get request data
$data = json_decode(file_get_contents('php://input'));

// Validate input
$validator = new Validator();
$validator->required($data->class_id      ?? '', 'class_id');
$validator->required($data->student_id    ?? '', 'student_id');
$validator->required($data->first_name    ?? '', 'first_name');
$validator->required($data->last_name     ?? '', 'last_name');
$validator->required($data->gender        ?? '', 'gender');
$validator->required($data->year_level    ?? '', 'year_level');
$validator->required($data->program       ?? '', 'program');
$validator->required($data->mobile_number ?? '', 'mobile_number');

$parentEmail  = trim($data->parent_email ?? '');
$studentEmail = trim($data->email        ?? '');
$gender       = trim($data->gender       ?? '');
$yearLevel    = trim($data->year_level   ?? '');

// Validate gender value
$allowedGenders = ['Male', 'Female', 'Other'];
if (!empty($gender) && !in_array($gender, $allowedGenders)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid gender value']);
    exit();
}

if (!empty($parentEmail) && !filter_var($parentEmail, FILTER_VALIDATE_EMAIL)) {
    Response::validationError(['parent_email' => 'Invalid parent email format']);
}
if (!empty($studentEmail) && !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
    Response::validationError(['email' => 'Invalid email format']);
}

if (!$validator->passes()) {
    Response::validationError($validator->getErrors());
}

try {
    // Verify the class belongs to the instructor
    $verifyStmt = $db->prepare('SELECT id, class_name FROM classes WHERE id = ? AND instructor_id = ?');
    $verifyStmt->execute([$data->class_id, $userId]);
    $class = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$class) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
        exit();
    }

    // Find existing student by student_id first
    $checkStudent = $db->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
    $checkStudent->execute([$data->student_id]);
    $existingStudent = $checkStudent->fetch(PDO::FETCH_ASSOC);

    // If not found by student_id, fallback to email match (when provided)
    if (!$existingStudent && !empty($studentEmail)) {
        $checkByEmail = $db->prepare('SELECT id FROM students WHERE email = ? LIMIT 1');
        $checkByEmail->execute([$studentEmail]);
        $existingStudent = $checkByEmail->fetch(PDO::FETCH_ASSOC);
    }

    if ($existingStudent) {
        $studentDbId = (int)$existingStudent['id'];

        // Check if already enrolled in this class
        $checkEnrollment = $db->prepare('SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? LIMIT 1');
        $checkEnrollment->execute([$studentDbId, $data->class_id]);
        if ($checkEnrollment->fetch(PDO::FETCH_ASSOC)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Student is already enrolled in this class']);
            exit();
        }

        // ✅ Update existing student — includes gender and year_level now
        $updateStudent = $db->prepare(
            'UPDATE students
             SET parent_email = COALESCE(NULLIF(?, ""), parent_email),
                 parent_name  = COALESCE(NULLIF(?, ""), parent_name),
                 phone        = COALESCE(NULLIF(?, ""), phone),
                 program      = COALESCE(NULLIF(?, ""), program),
                 email        = COALESCE(NULLIF(?, ""), email),
                 gender       = COALESCE(NULLIF(?, ""), gender),
                 year_level   = COALESCE(NULLIF(?, ""), year_level)
             WHERE id = ?'
        );
        $updateStudent->execute([
            $parentEmail,
            trim($data->parent_name   ?? ''),
            trim($data->mobile_number ?? ''),
            trim($data->program       ?? ''),
            $studentEmail,
            $gender,
            $yearLevel,
            $studentDbId,
        ]);

    } else {
        // ✅ Create new student — includes gender and year_level now
        $insertStudent = $db->prepare(
            'INSERT INTO students
                (student_id, first_name, middle_initial, last_name, gender, year_level,
                 email, parent_email, parent_name, phone, program, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $insertStudent->execute([
            trim($data->student_id),
            trim($data->first_name),
            !empty(trim($data->middle_initial ?? '')) ? trim($data->middle_initial) : null,
            trim($data->last_name),
            !empty($gender)       ? $gender     : null,
            !empty($yearLevel)    ? $yearLevel   : null,
            !empty($studentEmail) ? $studentEmail : null,
            !empty($parentEmail)  ? $parentEmail  : null,
            !empty(trim($data->parent_name ?? '')) ? trim($data->parent_name) : null,
            trim($data->mobile_number),
            trim($data->program),
        ]);
        $studentDbId = (int)$db->lastInsertId();
    }

    // Enroll student in class
    $enrollStmt = $db->prepare(
        'INSERT INTO enrollments (student_id, class_id, enrolled_date, status)
         VALUES (?, ?, NOW(), "active")'
    );
    $enrollStmt->execute([$studentDbId, $data->class_id]);

    // Get the complete student record
    $getStudent = $db->prepare('SELECT * FROM students WHERE id = ?');
    $getStudent->execute([$studentDbId]);
    $student = $getStudent->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'    => true,
        'message'    => 'Student enrolled successfully',
        'student'    => $student,
        'class_name' => $class['class_name'],
    ]);

} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'message' => 'Duplicate record detected for student ID or email.',
        ]);
        exit();
    }

    error_log('Enroll student PDO error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while enrolling student: ' . $e->getMessage(),
    ]);
} catch (Exception $e) {
    error_log('Enroll student error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while enrolling student: ' . $e->getMessage(),
    ]);
}
?>