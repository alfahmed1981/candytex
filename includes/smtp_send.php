<?php
/**
 * Reusable SMTP email sender for Candytex
 * Extracted from admin_email.php — uses app_settings table
 */

function get_smtp_config($pdo)
{
    $keys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'from_name', 'from_email', 'reply_to'];
    $cfg = [];
    foreach ($keys as $k) {
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ?");
            $stmt->execute([$k]);
            $cfg[$k] = $stmt->fetchColumn() ?: '';
        } catch (Exception $e) {
            $cfg[$k] = '';
        }
    }
    return $cfg;
}

/**
 * Send email via SMTP (no external libraries)
 * @return true on success, string error message on failure
 */
function send_smtp_email($pdo, $to, $subject, $body)
{
    $cfg = get_smtp_config($pdo);
    if (empty($cfg['smtp_host']) || empty($cfg['smtp_username'])) {
        return "SMTP not configured";
    }

    $host = $cfg['smtp_host'];
    $port = intval($cfg['smtp_port'] ?: 587);
    $user = $cfg['smtp_username'];
    $pass = $cfg['smtp_password'];
    $enc = $cfg['smtp_encryption'] ?: 'tls';
    $from_email = $cfg['from_email'] ?: $user;
    $from_name = $cfg['from_name'] ?: 'Candytex';

    $encoded_subject = "=?UTF-8?B?" . base64_encode($subject) . "?=";

    try {
        $prefix = ($enc === 'ssl') ? 'ssl://' : '';
        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 10);
        if (!$socket)
            return "Connection failed: $errstr ($errno)";

        $r = fgets($socket, 512);
        if (substr($r, 0, 3) !== '220') {
            fclose($socket);
            return "Server not ready: $r";
        }

        // EHLO
        fwrite($socket, "EHLO candytex.ma\r\n");
        while ($line = fgets($socket, 512)) {
            if (substr($line, 3, 1) === ' ')
                break;
        }

        // STARTTLS
        if ($enc === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $r = fgets($socket, 512);
            if (substr($r, 0, 3) !== '220') {
                fclose($socket);
                return "STARTTLS failed";
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO candytex.ma\r\n");
            while ($line = fgets($socket, 512)) {
                if (substr($line, 3, 1) === ' ')
                    break;
            }
        }

        // AUTH
        fwrite($socket, "AUTH LOGIN\r\n");
        $r = fgets($socket, 512);
        if (substr($r, 0, 3) !== '334') {
            fclose($socket);
            return "AUTH failed";
        }
        fwrite($socket, base64_encode($user) . "\r\n");
        $r = fgets($socket, 512);
        fwrite($socket, base64_encode($pass) . "\r\n");
        $r = fgets($socket, 512);
        if (substr($r, 0, 3) !== '235') {
            fclose($socket);
            return "Authentication failed";
        }

        // SEND
        fwrite($socket, "MAIL FROM:<$from_email>\r\n");
        fgets($socket, 512);
        fwrite($socket, "RCPT TO:<$to>\r\n");
        fgets($socket, 512);
        fwrite($socket, "DATA\r\n");
        fgets($socket, 512);

        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $encoded_subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "\r\n";
        $headers .= $body . "\r\n.\r\n";

        fwrite($socket, $headers);
        $r = fgets($socket, 512);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return (substr($r, 0, 3) === '250') ? true : "Rejected: $r";
    } catch (Exception $e) {
        return "Exception: " . $e->getMessage();
    }
}

/**
 * Send email to all admins
 */
function send_email_to_admins($pdo, $subject, $body)
{
    $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin' AND email IS NOT NULL AND email != ''")->fetchAll(PDO::FETCH_COLUMN);
    $results = [];
    foreach ($admins as $email) {
        $results[$email] = send_smtp_email($pdo, $email, $subject, $body);
    }
    return $results;
}
