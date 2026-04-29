<?php
/**
 * Instructor Authentication Module
 * Login endpoint for instructors
 */

require_once '../../core/cors.php';
require_once '../../core/Database.php';
require_once '../../core/Response.php';
require_once '../../core/Validator.php';

// Get request data
$data = json_decode(file_get_contents("php://input"));

// Validate input
$validator = new Validator();

if (!isset($data->email) || !$validator->required($data->email, 'email')) {
    Response::validationError($validator->getErrors());
}

if (!isset($data->password) || !$validator->required($data->password, 'password')) {
    Response::validationError($validator->getErrors());
}

try {
    // Get database connection
    $database = new Database();
    $db = $database->getConnection();

    if (!$db) {
        Response::serverError("Database connection failed");
    }

    // Query user
    $query = "SELECT id, name, email, password, role, department, employee_id, phone 
             FROM users 
             WHERE email = :email AND role = 'instructor' AND is_active = 1";

    $stmt = $db->prepare($query);
    $stmt->bindParam(":email", $data->email);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify password
        if (password_verify($data->password, $user['password'])) {
            // Generate session token (simple version - use JWT in production)
            $token = base64_encode($user['id'] . ":" . time() . ":" . bin2hex(random_bytes(16)));

            // Remove password from response
            unset($user['password']);

            Response::success([
                'user' => $user,
                'token' => $token
            ], "Login successful");
        } else {
            Response::error("Invalid email or password", 401);
        }
    } else {
        Response::error("Invalid email or password", 401);
    }
} catch (Exception $e) {
    error_log("Login error: " . $e->getMessage());
    Response::serverError("An error occurred during login");
}
