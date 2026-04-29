<?php
// backend/attendance/stats.php
// Get attendance statistics for instructor dashboard

include_once '../config/cors.php';
include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$instructor_id = isset($_GET['instructor_id']) ? $_GET['instructor_id'] : null;

$response = array();

try {
    if ($instructor_id) {
        $today = date('Y-m-d');

        // Get total enrolled students across all classes
        $enrolled_query = "SELECT COUNT(DISTINCT e.student_id) as total
                          FROM enrollments e
                          INNER JOIN classes c ON e.class_id = c.id
                          WHERE c.instructor_id = :instructor_id";
        $enrolled_stmt = $db->prepare($enrolled_query);
        $enrolled_stmt->bindParam(":instructor_id", $instructor_id);
        $enrolled_stmt->execute();
        $enrolled_row = $enrolled_stmt->fetch(PDO::FETCH_ASSOC);

        // Get present today count
        $present_query = "SELECT COUNT(DISTINCT a.student_id) as total
                         FROM attendance a
                         INNER JOIN classes c ON a.class_id = c.id
                         WHERE c.instructor_id = :instructor_id
                         AND DATE(a.check_in_time) = :today
                         AND a.status = 'present'";
        $present_stmt = $db->prepare($present_query);
        $present_stmt->bindParam(":instructor_id", $instructor_id);
        $present_stmt->bindParam(":today", $today);
        $present_stmt->execute();
        $present_row = $present_stmt->fetch(PDO::FETCH_ASSOC);

        // Get total number of classes
        $classes_query = "SELECT COUNT(*) as total FROM classes WHERE instructor_id = :instructor_id";
        $classes_stmt = $db->prepare($classes_query);
        $classes_stmt->bindParam(":instructor_id", $instructor_id);
        $classes_stmt->execute();
        $classes_row = $classes_stmt->fetch(PDO::FETCH_ASSOC);

        // Calculate attendance rate
        $total_enrolled = $enrolled_row['total'];
        $total_present = $present_row['total'];
        $absent_today = $total_enrolled - $total_present;
        $attendance_rate = $total_enrolled > 0 ? round(($total_present / $total_enrolled) * 100) : 0;

        http_response_code(200);
        $response['success'] = true;
        $response['stats'] = array(
            'enrolled_students' => $total_enrolled,
            'enrolled_classes' => $classes_row['total'],
            'present_today' => $total_present,
            'absent_today' => $absent_today,
            'attendance_rate' => $attendance_rate,
            'date' => date('l, F j, Y')
        );
    } else {
        http_response_code(400);
        $response['success'] = false;
        $response['message'] = "Instructor ID is required";
    }
} catch (Exception $e) {
    http_response_code(500);
    $response['success'] = false;
    $response['message'] = "Server error: " . $e->getMessage();
}

echo json_encode($response);
?>
