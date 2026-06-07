<?php
/**
 * Cancel or restore a class session for a specific date
 * Endpoint: POST /attendance/cancel_class.php
 *
 * Body: { class_id, date }          → toggles cancel/restore
 * GET:  ?class_id=X                 → returns list of cancelled dates for that class
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../core/Database.php';

$database = new Database();
$db = $database->getConnection();

// ── Auth ──────────────────────────────────────────────────────────────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? (function_exists('getallheaders') ? (getallheaders()['Authorization'] ?? '') : '');
$token = str_replace('Bearer ', '', $authHeader);
if (empty($token)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit();
}
$decoded = explode(':', base64_decode($token));
$userId  = $decoded[0] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
    exit();
}

// ── Ensure table exists ───────────────────────────────────────────────────────
$db->exec("
    CREATE TABLE IF NOT EXISTS cancelled_classes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        class_id   INT  NOT NULL,
        date       DATE NOT NULL,
        reason     VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT NOW(),
        UNIQUE KEY uq_class_date (class_id, date)
    )
");

// ══════════════════════════════════════════════════════════════════════════════
// GET — return cancelled dates for a class
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $classId = $_GET['class_id'] ?? null;
    if (!$classId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'class_id is required']);
        exit();
    }

    // Verify ownership
    $chk = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $chk->execute([$classId, $userId]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    $stmt = $db->prepare("SELECT date, reason FROM cancelled_classes WHERE class_id = ? ORDER BY date DESC");
    $stmt->execute([$classId]);
    $dates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'cancelled_dates' => $dates]);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// POST — toggle cancel / restore
// ══════════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $classId = intval($data['class_id'] ?? 0);
    $date    = trim($data['date']     ?? '');
    $reason  = trim($data['reason']   ?? '');

    if (!$classId || !$date) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'class_id and date are required']);
        exit();
    }

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD']);
        exit();
    }

    // Verify ownership
    $chk = $db->prepare("SELECT id FROM classes WHERE id = ? AND instructor_id = ?");
    $chk->execute([$classId, $userId]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    // Check if already cancelled
    $check = $db->prepare("SELECT id FROM cancelled_classes WHERE class_id = ? AND date = ?");
    $check->execute([$classId, $date]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Restore — delete the cancellation
        $del = $db->prepare("DELETE FROM cancelled_classes WHERE class_id = ? AND date = ?");
        $del->execute([$classId, $date]);

        echo json_encode([
            'success' => true,
            'action'  => 'restored',
            'message' => "Class session on $date has been restored.",
            'date'    => $date,
        ]);
    } else {
        // Cancel — insert cancellation
        $ins = $db->prepare("INSERT INTO cancelled_classes (class_id, date, reason) VALUES (?, ?, ?)");
        $ins->execute([$classId, $date, $reason ?: null]);

        // Also delete any attendance records auto-marked for that day
        // so cancelled day is completely wiped from records
        $del = $db->prepare("
            DELETE FROM attendance
            WHERE class_id = ?
              AND DATE(check_in_time) = ?
        ");
        $del->execute([$classId, $date]);

        echo json_encode([
            'success' => true,
            'action'  => 'cancelled',
            'message' => "Class session on $date has been cancelled. Attendance records for this day have been removed.",
            'date'    => $date,
        ]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed']);
?>
