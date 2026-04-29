<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT, OPTIONS");
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
            $stmt = $db->prepare("SELECT id, name, email, role, department, employee_id, created_at FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'User not found']);
                exit();
            }

            echo json_encode(['success' => true, 'data' => $user]);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents("php://input"));

            // ── Password change request ──────────────────────────────
            // Detected when 'password' field is present in the payload
            if (!empty($data->password)) {

                // current_password is REQUIRED for password change
                if (empty($data->current_password)) {
                    http_response_code(400);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Current password is required.'
                    ]);
                    exit();
                }

                // Fetch the stored hashed password from DB
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$row) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'User not found.']);
                    exit();
                }

                // ✅ Verify current password against stored hash
                if (!password_verify($data->current_password, $row['password'])) {
                    // ❌ Wrong current password — return 401 so frontend can show inline error
                    http_response_code(401);
                    echo json_encode([
                        'success' => false,
                        'message' => 'Incorrect current password.'
                    ]);
                    exit();
                }

                // Current password is correct — update to new password
                $newHash = password_hash($data->password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->execute([$newHash, $userId]);

                echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
                exit();
            }

            // ── Profile info update (name, department, employee_id) ──
            $updates = [];
            $params  = [];

            if (!empty($data->name)) {
                $updates[] = "name = ?";
                $params[]  = $data->name;
            }
            if (!empty($data->department)) {
                $updates[] = "department = ?";
                $params[]  = $data->department;
            }
            if (isset($data->employee_id)) {
                $updates[] = "employee_id = ?";
                $params[]  = $data->employee_id;
            }

            if (empty($updates)) {
                echo json_encode(['success' => true, 'message' => 'Nothing to update.']);
                exit();
            }

            $params[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);

            echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>