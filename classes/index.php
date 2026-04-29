<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Get all classes for instructor
            $stmt = $db->prepare("SELECT c.*, 
                (SELECT COUNT(*) FROM enrollments WHERE class_id = c.id) as enrolled,
                (SELECT COUNT(*) FROM attendance WHERE class_id = c.id AND DATE(check_in_time) = CURDATE() AND status = 'present') as present_today
                FROM classes c WHERE c.instructor_id = ? ORDER BY c.created_at DESC");
            $stmt->execute([$userId]);
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate attendance rate for each class
            foreach ($classes as &$class) {
                $class['attendanceRate'] = $class['enrolled'] > 0 
                    ? round(($class['present_today'] / $class['enrolled']) * 100) 
                    : 0;
            }
            
            echo json_encode(['success' => true, 'data' => $classes]);
            break;
            
        case 'POST':
            // Create new class
            $data = json_decode(file_get_contents("php://input"));
            
            if (empty($data->class_name) || empty($data->class_code)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Class name and code required']);
                exit();
            }
            
            if (empty($data->start_time) || empty($data->end_time) || empty($data->days)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Start time, end time, and days are required']);
                exit();
            }
            
            // Check if class code exists
            $check = $db->prepare("SELECT id FROM classes WHERE class_code = ? AND section = ? AND instructor_id = ?");
            $check->execute([$data->class_code, $data->section ?? '', $userId]);
            if ($check->rowCount() > 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Class code with this section already exists']);
                exit();
            }
            
            $stmt = $db->prepare("INSERT INTO classes (instructor_id, class_name, class_code, section, description, start_time, end_time, days, room, is_active, notify_parents) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $userId,
                $data->class_name,
                $data->class_code,
                $data->section ?? null,
                $data->description ?? null,
                $data->start_time,
                $data->end_time,
                $data->days,
                $data->room ?? null,
                $data->is_active ?? 1,
                isset($data->notify_parents) ? ((bool)$data->notify_parents ? 1 : 0) : 1
            ]);
            
            $classId = $db->lastInsertId();
            
            // Get the created class
            $getStmt = $db->prepare("SELECT * FROM classes WHERE id = ?");
            $getStmt->execute([$classId]);
            $class = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'message' => 'Class created successfully',
                'data' => $class
            ]);
            break;
            
        case 'PUT':
            // Update class
            $data = json_decode(file_get_contents("php://input"));
            
            if (empty($data->id)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Class ID required']);
                exit();
            }
            
            // Build dynamic update query based on provided fields
            $updates = [];
            $params = [];
            
            if (isset($data->class_name)) {
                $updates[] = "class_name = ?";
                $params[] = $data->class_name;
            }
            if (isset($data->section)) {
                $updates[] = "section = ?";
                $params[] = $data->section;
            }
            if (isset($data->description)) {
                $updates[] = "description = ?";
                $params[] = $data->description;
            }
            if (isset($data->start_time)) {
                $updates[] = "start_time = ?";
                $params[] = $data->start_time;
            }
            if (isset($data->end_time)) {
                $updates[] = "end_time = ?";
                $params[] = $data->end_time;
            }
            if (isset($data->days)) {
                $updates[] = "days = ?";
                $params[] = $data->days;
            }
            if (isset($data->room)) {
                $updates[] = "room = ?";
                $params[] = $data->room;
            }
            if (isset($data->is_active)) {
                $updates[] = "is_active = ?";
                $params[] = $data->is_active ? 1 : 0;
            }
            if (isset($data->notify_parents)) {
                $updates[] = "notify_parents = ?";
                $params[] = $data->notify_parents ? 1 : 0;
            }
            
            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'No fields to update']);
                exit();
            }
            
            $params[] = $data->id;
            $params[] = $userId;
            
            $sql = "UPDATE classes SET " . implode(", ", $updates) . " WHERE id = ? AND instructor_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Get updated class
            $getStmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND instructor_id = ?");
            $getStmt->execute([$data->id, $userId]);
            $class = $getStmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'message' => 'Class updated successfully', 'data' => $class]);
            break;
            
        case 'DELETE':
            $classId = $_GET['id'] ?? null;
            
            if (!$classId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Class ID required']);
                exit();
            }
            
            $stmt = $db->prepare("DELETE FROM classes WHERE id = ? AND instructor_id = ?");
            $stmt->execute([$classId, $userId]);
            
            echo json_encode(['success' => true, 'message' => 'Class deleted successfully']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
