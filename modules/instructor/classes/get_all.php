<?php
/**
 * Instructor Classes Module
 * Get all classes for instructor
 */

require_once '../../core/cors.php';
require_once '../../core/Database.php';
require_once '../../core/Response.php';

// Get instructor ID from query parameter
$instructorId = $_GET['instructor_id'] ?? null;

if (!$instructorId) {
    Response::error("Instructor ID is required", 400);
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        Response::serverError("Database connection failed");
    }

    // Query classes
    $query = "SELECT 
                c.id,
                c.class_name,
                c.class_code,
                c.section,
                c.description,
                c.start_time,
                c.end_time,
                c.days,
                c.is_active,
                c.created_at,
                COUNT(DISTINCT e.student_id) as total_students,
                COUNT(DISTINCT a.id) as total_attendance
              FROM classes c
              LEFT JOIN enrollments e ON c.id = e.class_id
              LEFT JOIN attendance a ON c.id = a.class_id
              WHERE c.instructor_id = :instructor_id
              GROUP BY c.id
              ORDER BY c.created_at DESC";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":instructor_id", $instructorId);
    $stmt->execute();

    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    Response::success([
        'classes' => $classes,
        'total' => count($classes)
    ]);
} catch (Exception $e) {
    error_log("Get classes error: " . $e->getMessage());
    Response::serverError("An error occurred while fetching classes");
}
