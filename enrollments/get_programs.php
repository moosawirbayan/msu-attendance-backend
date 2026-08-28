<?php
/**
 * get_programs.php
 *
 * Returns the distinct list of "program" (course) values already used by
 * students in the system, so the client can show them as suggestions when
 * enrolling a new student.
 *
 * IMPORTANT: This file assumes the same project structure as your other
 * /enrollments/*.php endpoints (enroll.php, enroll_bulk.php,
 * search_students.php). Adjust the two marked sections below
 * (DB include + auth check) to match exactly what those files do — I don't
 * have access to your actual db.php / auth helper, so these are written to
 * match the common pattern implied by your existing endpoints.
 *
 * Expected request:  GET /enrollments/get_programs.php
 * Header:             Authorization: Bearer <token>
 *
 * Response:
 *   { "success": true,  "programs": ["BS Computer Science", "BS Information Technology", ...] }
 *   { "success": false, "message": "..." }
 */

header('Content-Type: application/json');

// ── 1) DB connection ─────────────────────────────────────────────────────
// Replace this with whatever your other endpoints use, e.g.:
//   require_once __DIR__ . '/../config/db.php';
// which should define a PDO instance in $pdo (or adjust the query section
// below to match your existing mysqli/PDO setup).
require_once __DIR__ . '/../config/db.php';

// ── 2) Auth check ────────────────────────────────────────────────────────
// Replace this with the same token-verification logic used in enroll.php /
// search_students.php (e.g. a verifyToken($headers) helper). Left as a
// simple presence check so the file is at least runnable as-is.
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if (stripos($authHeader, 'Bearer ') !== 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Missing or invalid Authorization header.']);
    exit;
}

$token = trim(substr($authHeader, 7));

// TODO: swap this for your real token check, e.g.:
//   $userId = verifyToken($token);
//   if (!$userId) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Invalid or expired token.']); exit; }
if ($token === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or expired token.']);
    exit;
}

// ── 3) Fetch distinct, non-empty program values ─────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT DISTINCT program
         FROM students
         WHERE program IS NOT NULL AND TRIM(program) <> ''
         ORDER BY program ASC"
    );
    $stmt->execute();

    $programs = array_map(
        fn($row) => $row['program'],
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    echo json_encode([
        'success'  => true,
        'programs' => $programs,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch programs.',
        // Remove 'error' from the response in production — kept here only
        // to help you debug the DB include/query while wiring this up.
        'error'   => $e->getMessage(),
    ]);
}
