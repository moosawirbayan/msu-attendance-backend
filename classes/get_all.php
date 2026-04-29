<?php
// backend/classes/get_all.php
// Get all classes for an instructor

include_once '../config/cors.php';
include_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$instructor_id = isset($_GET['instructor_id']) ? $_GET['instructor_id'] : null;

$response = array();

try {
    if ($instructor_id) {
        $query = "SELECT 
                    c.id,
                    c.class_code,
                    c.class_name,
                    c.section,
                    c.start_time,
                    c.end_time,
                    c.days,
                    c.room,
                    c.is_active,
                    COUNT(DISTINCT e.student_id) as enrolled_students
                  FROM classes c
                  LEFT JOIN enrollments e ON c.id = e.class_id
                  WHERE c.instructor_id = :instructor_id
                  GROUP BY c.id, c.class_code, c.class_name, c.section, c.start_time, c.end_time, c.days, c.room, c.is_active
                  ORDER BY c.class_code";

        $stmt = $db->prepare($query);
        $stmt->bindParam(":instructor_id", $instructor_id);
        $stmt->execute();

        $classes = array();
        $today = date('Y-m-d');

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            // Get present count for today
            $present_query = "SELECT COUNT(*) as total 
                             FROM attendance 
                             WHERE class_id = :class_id 
                             AND DATE(check_in_time) = :today 
                             AND status = 'present'";
            $present_stmt = $db->prepare($present_query);
            $present_stmt->bindParam(":class_id", $row['id']);
            $present_stmt->bindParam(":today", $today);
            $present_stmt->execute();
            $present_row = $present_stmt->fetch(PDO::FETCH_ASSOC);

            $enrolled = $row['enrolled_students'];
            $present = $present_row['total'];
            $attendance_rate = $enrolled > 0 ? round(($present / $enrolled) * 100) : 0;

            $class_item = array(
                'id' => $row['id'],
                'class_code' => $row['class_code'],
                'class_name' => $row['class_name'],
                'section' => $row['section'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'days' => $row['days'],
                'room' => $row['room'],
                'is_active' => (bool)$row['is_active'],
                'enrolled_students' => $enrolled,
                'present_today' => $present,
                'attendance_rate' => $attendance_rate
            );

            array_push($classes, $class_item);
        }

        http_response_code(200);
        $response['success'] = true;
        $response['classes'] = $classes;
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
