<?php
/**
 * Response Handler
 * Standardized API response format
 */

class Response {
    /**
     * Send success response
     */
    public static function success($data = [], $message = "Success", $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
        exit;
    }

    /**
     * Send error response
     */
    public static function error($message = "Error", $statusCode = 400, $errors = []) {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors
        ]);
        exit;
    }

    /**
     * Send validation error
     */
    public static function validationError($errors = []) {
        self::error("Validation failed", 422, $errors);
    }

    /**
     * Send unauthorized response
     */
    public static function unauthorized($message = "Unauthorized access") {
        self::error($message, 401);
    }

    /**
     * Send not found response
     */
    public static function notFound($message = "Resource not found") {
        self::error($message, 404);
    }

    /**
     * Send server error response
     */
    public static function serverError($message = "Internal server error") {
        self::error($message, 500);
    }
}
