<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../core/Database.php';
require_once '../core/NotificationService.php';

$database = new Database();
$db = $database->getConnection();

$data = json_decode(file_get_contents("php://input"));
$email = trim($data->email ?? '');

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid email is required']);
    exit();
}

try {
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Email is not registered']);
        exit();
    }

    $temporaryPassword = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'), 0, 10);
    $hashedPassword = password_hash($temporaryPassword, PASSWORD_BCRYPT);

    $update = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update->execute([$hashedPassword, $user['id']]);

    $notifier = new NotificationService();
    $sent = $notifier->sendPasswordResetEmail($user['email'], $user['name'], $temporaryPassword);

    if (!$sent) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to send reset email. Please try again later.']);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'A temporary password has been sent to your email.',
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
