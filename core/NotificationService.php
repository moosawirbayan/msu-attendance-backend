<?php

class NotificationService {
    private $apiKey;
    private $fromEmail;
    private $fromName;

    public function __construct() {
        $this->apiKey    = getenv('BREVO_API_KEY');
        $this->fromEmail = getenv('NOTIFY_FROM_EMAIL') ?: 'mosawirbayan47@gmail.com';
        $this->fromName  = getenv('NOTIFY_FROM_NAME')  ?: 'Automated Classroom Attendance';
    }

    private function sendEmail($toEmail, $toName, $subject, $htmlBody, $textBody) {
        $payload = json_encode([
            'sender'     => [
                'email' => $this->fromEmail,
                'name'  => $this->fromName,
            ],
            'to' => [
                [
                    'email' => $toEmail,
                    'name'  => $toName ?: 'Parent/Guardian',
                ]
            ],
            'subject'     => $subject,
            'htmlContent' => $htmlBody,
            'textContent' => $textBody,
        ]);

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'api-key: ' . $this->apiKey,
                'content-type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log('[BREVO] cURL error: ' . $curlError);
            return false;
        }

        error_log('[BREVO] HTTP ' . $httpCode . ' — ' . $response);
        return $httpCode >= 200 && $httpCode < 300;
    }

    public function sendAttendanceEmail($toEmail, $parentName, $studentName, $className, $status, $checkInTime) {
        if (empty($toEmail)) return false;

        $safeParentName  = htmlspecialchars($parentName  ?: 'Parent/Guardian', ENT_QUOTES, 'UTF-8');
        $safeStudentName = htmlspecialchars($studentName,                       ENT_QUOTES, 'UTF-8');
        $safeClassName   = htmlspecialchars($className,                         ENT_QUOTES, 'UTF-8');
        $safeStatus      = htmlspecialchars(ucfirst($status),                   ENT_QUOTES, 'UTF-8');
        $safeTime        = htmlspecialchars($checkInTime,                       ENT_QUOTES, 'UTF-8');

        $subject  = 'Attendance Update: ' . $safeStudentName;
        $htmlBody = "
            <p>Dear {$safeParentName},</p>
            <p>This is to inform you that <strong>{$safeStudentName}</strong> has been marked <strong>{$safeStatus}</strong>.</p>
            <p>
                <strong>Class:</strong> {$safeClassName}<br>
                <strong>Time:</strong> {$safeTime}
            </p>
            <p>Thank you.</p>
            <p>Automated Classroom Attendance</p>
        ";
        $textBody = "Dear {$parentName}, {$studentName} has been marked {$status} in {$className} at {$checkInTime}.";

        return $this->sendEmail($toEmail, $parentName, $subject, $htmlBody, $textBody);
    }

    public function sendPasswordResetEmail($toEmail, $name, $temporaryPassword) {
        if (empty($toEmail)) return false;

        $safeName     = htmlspecialchars($name             ?: 'User', ENT_QUOTES, 'UTF-8');
        $safePassword = htmlspecialchars($temporaryPassword,           ENT_QUOTES, 'UTF-8');

        $subject  = 'Automated Classroom Attendance - Temporary Password';
        $htmlBody = "
            <p>Hello {$safeName},</p>
            <p>Your temporary password is:</p>
            <p><strong>{$safePassword}</strong></p>
            <p>Please log in and change your password immediately.</p>
            <p>Automated Classroom Attendance</p>
        ";
        $textBody = "Hello {$name}, your temporary password is {$temporaryPassword}. Please log in and change your password immediately.";

        return $this->sendEmail($toEmail, $name, $subject, $htmlBody, $textBody);
    }
}
?>