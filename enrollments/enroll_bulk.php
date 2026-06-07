<?php
/**
 * Bulk Enroll Students in a Class
 * Endpoint: POST /enrollments/enroll_bulk.php
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

$database = new Database();
$db = $database->getConnection();

// ── Auth (same pattern as enroll.php) ────────────────────────────────────────
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

// ── Parse body ────────────────────────────────────────────────────────────────
$body     = json_decode(file_get_contents('php://input'), true);
$students = $body['students'] ?? [];

if (empty($students) || !is_array($students)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No students provided']);
    exit();
}

// ── Verify class belongs to this instructor (use class_id from first row) ─────
$classId = $students[0]['class_id'] ?? null;
if (!$classId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'class_id is missing']);
    exit();
}

$verifyStmt = $db->prepare('SELECT id, class_name FROM classes WHERE id = ? AND instructor_id = ?');
$verifyStmt->execute([$classId, $userId]);
$class = $verifyStmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to this class']);
    exit();
}

// ── Process each student ──────────────────────────────────────────────────────
$enrolled    = 0;
$failed      = 0;
$failed_rows = [];

$allowedGenders = ['Male', 'Female', 'Other'];

foreach ($students as $s) {
    $student_id     = trim($s['student_id']     ?? '');
    $first_name     = trim($s['first_name']      ?? '');
    $last_name      = trim($s['last_name']       ?? '');
    $middle_initial = trim($s['middle_initial']  ?? '');
    $gender         = trim($s['gender']          ?? '');
    $year_level     = trim($s['year_level']      ?? '');
    $program        = trim($s['program']         ?? '');
    $email          = trim($s['email']           ?? '');
    $parent_name    = trim($s['parent_name']     ?? '');
    $parent_email   = trim($s['parent_email']    ?? '');
    $mobile_number  = trim($s['mobile_number']   ?? '');

    // Validate required fields
    if (!$student_id || !$first_name || !$last_name) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id ?: '(empty)',
            'reason'     => 'Missing required field: student_id, first_name, or last_name',
        ];
        continue;
    }

    // Validate gender if provided
    if (!empty($gender) && !in_array($gender, $allowedGenders)) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id,
            'reason'     => "Invalid gender value: \"$gender\"",
        ];
        continue;
    }

    // Validate emails if provided
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id,
            'reason'     => 'Invalid student email format',
        ];
        continue;
    }
    if (!empty($parent_email) && !filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id,
            'reason'     => 'Invalid parent email format',
        ];
        continue;
    }

    try {
        // Check if student already exists by student_id
        $checkStudent = $db->prepare('SELECT id FROM students WHERE student_id = ? LIMIT 1');
        $checkStudent->execute([$student_id]);
        $existingStudent = $checkStudent->fetch(PDO::FETCH_ASSOC);

        // Fallback: check by email
        if (!$existingStudent && !empty($email)) {
            $checkByEmail = $db->prepare('SELECT id FROM students WHERE email = ? LIMIT 1');
            $checkByEmail->execute([$email]);
            $existingStudent = $checkByEmail->fetch(PDO::FETCH_ASSOC);
        }

        if ($existingStudent) {
            $studentDbId = (int) $existingStudent['id'];

            // Check if already enrolled in this class
            $checkEnrollment = $db->prepare(
                'SELECT id FROM enrollments WHERE student_id = ? AND class_id = ? LIMIT 1'
            );
            $checkEnrollment->execute([$studentDbId, $classId]);
            if ($checkEnrollment->fetch(PDO::FETCH_ASSOC)) {
                $failed++;
                $failed_rows[] = [
                    'student_id' => $student_id,
                    'reason'     => 'Already enrolled in this class',
                ];
                continue;
            }

            // Update existing student info
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
                $parent_email,
                $parent_name,
                $mobile_number,
                $program,
                $email,
                $gender,
                $year_level,
                $studentDbId,
            ]);

        } else {
            // Insert new student
            $insertStudent = $db->prepare(
                'INSERT INTO students
                    (student_id, first_name, middle_initial, last_name, gender, year_level,
                     email, parent_email, parent_name, phone, program, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $insertStudent->execute([
                $student_id,
                $first_name,
                !empty($middle_initial) ? $middle_initial : null,
                $last_name,
                !empty($gender)         ? $gender         : null,
                !empty($year_level)     ? $year_level     : null,
                !empty($email)          ? $email          : null,
                !empty($parent_email)   ? $parent_email   : null,
                !empty($parent_name)    ? $parent_name    : null,
                !empty($mobile_number)  ? $mobile_number  : null,
                $program,
            ]);
            $studentDbId = (int) $db->lastInsertId();
        }

        // Enroll in class
        $enrollStmt = $db->prepare(
            'INSERT INTO enrollments (student_id, class_id, enrolled_date, status)
             VALUES (?, ?, NOW(), "active")'
        );
        $enrollStmt->execute([$studentDbId, $classId]);
        $enrolled++;

    } catch (PDOException $e) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id,
            'reason'     => $e->getCode() === '23000'
                ? 'Duplicate record detected'
                : $e->getMessage(),
        ];
    } catch (Exception $e) {
        $failed++;
        $failed_rows[] = [
            'student_id' => $student_id,
            'reason'     => $e->getMessage(),
        ];
    }
}

echo json_encode([
    'success'     => true,
    'enrolled'    => $enrolled,
    'failed'      => $failed,
    'failed_rows' => $failed_rows,
]);
?>
