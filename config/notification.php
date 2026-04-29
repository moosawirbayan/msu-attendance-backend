<?php
/**
 * Simple notification config.
 * You can override these with environment variables in production.
 */
return [
    'email_enabled' => true,
    'smtp_host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
    'smtp_port' => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_username' => getenv('SMTP_USERNAME') ?: 'mosawirbayan47@gmail.com',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: 'xjdi ohmj rnth dxru',
    'from_email' => getenv('NOTIFY_FROM_EMAIL') ?: 'mosawirbayan47@gmail.com',
    'from_name' => getenv('NOTIFY_FROM_NAME') ?: 'MSU Attendance System',
];
