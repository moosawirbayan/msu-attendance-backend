<?php
/**
 * Instructor Dashboard Module
 * Get statistics for instructor dashboard
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

    // Get total classes
    $classQuery = "SELECT COUNT(*) as total FROM classes WHERE instructor_id = :instructor_id AND is_active = 1";
    $classStmt = $db->prepare($classQuery);
    $classStmt->bindParam(":instructor_id", $instructorId);
    $classStmt->execute();
    $totalClasses = $classStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total enrolled students
    $studentQuery = "SELECT COUNT(DISTINCT e.student_id) as total 
                     FROM enrollments e 
                     INNER JOIN classes c ON e.class_id = c.id 
                     WHERE c.instructor_id = :instructor_id";
    $studentStmt = $db->prepare($studentQuery);
    $studentStmt->bindParam(":instructor_id", $instructorId);
    $studentStmt->execute();
    $totalStudents = $studentStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's attendance stats
    $today = date('Y-m-d');
    $attendanceQuery = "SELECT 
                          COUNT(DISTINCT CASE WHEN a.status = 'present' THEN a.student_id END) as present,
                          COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.student_id END) as absent
                        FROM attendance a
                        INNER JOIN classes c ON a.class_id = c.id
                        WHERE c.instructor_id = :instructor_id 
                        AND DATE(a.check_in_time) = :today";
    $attendanceStmt = $db->prepare($attendanceQuery);
    $attendanceStmt->bindParam(":instructor_id", $instructorId);
    $attendanceStmt->bindParam(":today", $today);
    $attendanceStmt->execute();
    $attendanceStats = $attendanceStmt->fetch(PDO::FETCH_ASSOC);

    // Calculate attendance rate
    $attendanceRate = $totalStudents > 0 
        ? round(($attendanceStats['present'] / $totalStudents) * 100, 1) 
        : 0;

    Response::success([
        'total_classes' => (int)$totalClasses,
        'total_students' => (int)$totalStudents,
        'present_today' => (int)$attendanceStats['present'],
        'absent_today' => (int)$attendanceStats['absent'],
        'attendance_rate' => $attendanceRate
    ]);
} catch (Exception $e) {
    error_log("Get stats error: " . $e->getMessage());
    Response::serverError("An error occurred while fetching statistics");
}
