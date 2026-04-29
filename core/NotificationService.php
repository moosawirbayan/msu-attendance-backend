<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

class NotificationService {
    private $config;

    public function __construct() {
        $this->config = require __DIR__ . '/../config/notification.php';
    }

    private function createMailer() {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['smtp_host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['smtp_username'];
        $mail->Password = $this->config['smtp_password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $this->config['smtp_port'];
        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        return $mail;
    }

    public function sendAttendanceEmail($toEmail, $parentName, $studentName, $className, $status, $checkInTime) {
        if (empty($this->config['email_enabled']) || empty($toEmail)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($toEmail, $parentName ?: 'Parent/Guardian');
            $mail->isHTML(true);

            $safeParentName = htmlspecialchars($parentName ?: 'Parent/Guardian', ENT_QUOTES, 'UTF-8');
            $safeStudentName = htmlspecialchars($studentName, ENT_QUOTES, 'UTF-8');
            $safeClassName = htmlspecialchars($className, ENT_QUOTES, 'UTF-8');
            $safeStatus = htmlspecialchars(ucfirst($status), ENT_QUOTES, 'UTF-8');
            $safeTime = htmlspecialchars($checkInTime, ENT_QUOTES, 'UTF-8');

            $mail->Subject = 'Attendance Update: ' . $safeStudentName;
            $mail->Body = "
                <p>Dear {$safeParentName},</p>
                <p>This is to inform you that <strong>{$safeStudentName}</strong> has been marked <strong>{$safeStatus}</strong>.</p>
                <p>
                    <strong>Class:</strong> {$safeClassName}<br>
                    <strong>Time:</strong> {$safeTime}
                </p>
                <p>Thank you.</p>
                <p>MSU Attendance System</p>
            ";
            $mail->AltBody = "Dear {$parentName}, {$studentName} has been marked {$status} in {$className} at {$checkInTime}.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Notification email failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendPasswordResetEmail($toEmail, $name, $temporaryPassword) {
        if (empty($this->config['email_enabled']) || empty($toEmail)) {
            return false;
        }

        try {
            $mail = $this->createMailer();
            $mail->addAddress($toEmail, $name ?: 'User');
            $mail->isHTML(true);

            $safeName = htmlspecialchars($name ?: 'User', ENT_QUOTES, 'UTF-8');
            $safePassword = htmlspecialchars($temporaryPassword, ENT_QUOTES, 'UTF-8');

            $mail->Subject = 'MSU Attendance - Temporary Password';
            $mail->Body = "
                <p>Hello {$safeName},</p>
                <p>Your temporary password is:</p>
                <p><strong>{$safePassword}</strong></p>
                <p>Please log in and change your password immediately.</p>
                <p>MSU Attendance System</p>
            ";
            $mail->AltBody = "Hello {$name}, your temporary password is {$temporaryPassword}. Please log in and change your password immediately.";

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
            return false;
        }
    }
}
