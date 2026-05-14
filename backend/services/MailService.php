<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class MailService
{
    private string $mode;
    private string $from;
    private string $fromName;
    private string $logFile;
    private string $frontendUrl;
    
    // SMTP Config
    private ?string $smtpHost;
    private ?string $smtpUser;
    private ?string $smtpPass;
    private int $smtpPort;
    private int $smtpTimeout;

    public function __construct()
    {
        $config = require __DIR__ . '/../config/config.php';
        $mailConfig = $config['mail'];

        $this->mode = $mailConfig['mode'];
        $this->from = $mailConfig['from'];
        $this->fromName = $mailConfig['from_name'];
        $this->logFile = $mailConfig['log_file'];
        $this->frontendUrl = $this->resolveFrontendUrl($config['app']['frontend_url']);
        
        $this->smtpHost = $mailConfig['smtp_host'] ?? 'smtp.gmail.com';
        $this->smtpUser = $mailConfig['smtp_user'];
        $this->smtpPass = $mailConfig['smtp_pass'];
        $this->smtpPort = (int)($mailConfig['smtp_port'] ?? 587);
        $this->smtpTimeout = max(1, (int)($mailConfig['smtp_timeout'] ?? 5));
    }

    public function send($to, $subject, $body)
    {
        if ($this->mode === 'smtp' && $this->smtpUser && $this->smtpPass) {
            return $this->sendViaSMTP($to, $subject, $body);
        }

        if ($this->mode === 'api') {
            return $this->sendViaAPI($to, $subject, $body);
        }

        if ($this->mode === 'php') {
            $headers = "MIME-Version: 1.0" . "\r\n";
            $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
            $headers .= "From: {$this->fromName} <{$this->from}>" . "\r\n";
            return mail($to, $subject, $body, $headers);
        }

        return $this->logEmail($to, $subject, $body);
    }

    private function sendViaSMTP($to, $subject, $body)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host       = $this->smtpHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->smtpUser;
            $mail->Password   = $this->smtpPass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $this->smtpPort;
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = $this->smtpTimeout;
            $mail->SMTPKeepAlive = false;

            // Recipients
            $mail->setFrom($this->smtpUser, $this->fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            error_log("SMTP Mail Error: {$mail->ErrorInfo}");
            return false;
        }
    }

    private function sendViaAPI($to, $subject, $body)
    {
        // Placeholder for Transactional Email API (e.g., SendGrid, Mailgun, Brevo)
        // You can extend this with your specific API implementation
        error_log("Attempting to send email via API to $to (Not implemented - falling back to log)");
        return $this->logEmail($to, $subject, $body);
    }

    private function logEmail($to, $subject, $body)
    {
        $logPath = $this->resolveLogFile($this->logFile);
        $date = date('Y-m-d H:i:s');
        $content = "[$date] TO: $to | SUBJECT: $subject\nBODY: $body\n" . str_repeat("-", 50) . "\n";
        
        return file_put_contents($logPath, $content, FILE_APPEND) !== false;
    }

    public function sendActivationEmail($email, $displayName, $token)
    {
        $link = "{$this->frontendUrl}/activate?token=$token";
        $subject = "Activate your NoteMate Account";
        $body = "
            <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <h2 style='color: #4f46e5;'>Welcome to NoteMate, $displayName!</h2>
                <p>Please click the button below to activate your account and start taking notes:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='padding: 12px 24px; background: #4f46e5; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Activate Account</a>
                </div>
                <p style='color: #666; font-size: 14px;'>Or copy this link: <br>$link</p>
                <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #999;'>&copy; " . date('Y') . " NoteMate. All rights reserved.</p>
            </div>
        ";

        return $this->send($email, $subject, $body);
    }

    public function sendPasswordResetEmail($email, $displayName, $token, $otp = null)
    {
        $link = "{$this->frontendUrl}/reset-password?token=$token";
        $subject = "Reset your NoteMate Password";
        $otpHtml = $otp ? "
            <div style='background: #f3f4f6; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; letter-spacing: 5px; margin: 20px 0; color: #1f2937;'>
                $otp
            </div>" : "";

        $body = "
            <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <h2 style='color: #ef4444;'>Hi $displayName,</h2>
                <p>You requested to reset your password. You can use the code below or click the button:</p>
                $otpHtml
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='padding: 12px 24px; background: #ef4444; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
                </div>
                <p style='color: #666; font-size: 14px;'>This code and link will expire in 1 hour.</p>
                <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #999;'>&copy; " . date('Y') . " NoteMate. All rights reserved.</p>
            </div>
        ";

        return $this->send($email, $subject, $body);
    }

    public function sendShareNotificationEmail($to, $displayName, $ownerName, $noteTitle, $permission)
    {
        $subject = "$ownerName shared a note with you: $noteTitle";
        $role = $permission === 'edit' ? 'Editor' : 'Viewer';
        
        $body = "
            <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                <h2 style='color: #4f46e5;'>Hello $displayName,</h2>
                <p><strong>$ownerName</strong> has shared a note with you.</p>
                <div style='background: #f9fafb; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <p style='margin: 0;'><strong>Title:</strong> $noteTitle</p>
                    <p style='margin: 0;'><strong>Your Permission:</strong> $role</p>
                </div>
                <p>You can find this note in the 'Shared with me' section of your NoteMate dashboard.</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='{$this->frontendUrl}' style='padding: 12px 24px; background: #4f46e5; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Go to Dashboard</a>
                </div>
                <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                <p style='font-size: 12px; color: #999;'>&copy; " . date('Y') . " NoteMate. All rights reserved.</p>
            </div>
        ";

        return $this->send($to, $subject, $body);
    }

    private function resolveLogFile(string $logFile): string
    {
        return (preg_match('/^[A-Za-z]:\\\\|^\//', $logFile) === 1) ? $logFile : __DIR__ . '/../' . ltrim($logFile, '/\\');
    }

    private function resolveFrontendUrl(string $configuredUrl): string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if ($origin && preg_match('/^https?:\/\/[^\/]+$/', $origin) === 1) {
            return rtrim($origin, '/');
        }

        return rtrim($configuredUrl, '/');
    }
}
