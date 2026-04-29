<?php
/**
 * PHP CLI Development Router
 * Usage: php -S 0.0.0.0:8000 router.php
 * 
 * Handles CORS preflight early and routes all requests properly.
 */

// Handle CORS preflight for ALL routes before anything else
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Access-Control-Max-Age: 86400");
    http_response_code(200);
    exit();
}

// Normalize Authorization header — PHP CLI on Windows doesn't always expose it via getallheaders()
if (!isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $allHeaders = function_exists('getallheaders') ? getallheaders() : [];
    if (isset($allHeaders['Authorization'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $allHeaders['Authorization'];
    } elseif (isset($allHeaders['authorization'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $allHeaders['authorization'];
    }
}

// Route to the requested PHP file
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . $uri;

// Serve PHP files
if (is_file($file) && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
    // Change working directory to the file's directory so relative require_once paths work
    chdir(dirname($file));
    require $file;
    return true;
}

// Serve existing static files
if (is_file($file)) {
    return false; // Let built-in server handle it
}

// 404
http_response_code(404);
echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
