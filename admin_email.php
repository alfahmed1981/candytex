<?php
session_start();
require 'db.php';
require 'includes/auth.php';

// Security Check: ONLY Admins
if (!isset($_SESSION['user_cin']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied. Admins Only.");
}

// --- Self-healing: create email_settings table if not exists ---
$pdo->exec("CREATE TABLE IF NOT EXISTS `email_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT DEFAULT NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Helper functions
function get_email_setting($pdo, $key, $default = '')
{
    $stmt = $pdo->prepare("SELECT setting_value FROM email_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

function set_email_setting($pdo, $key, $value)
{
    $stmt = $pdo->prepare("INSERT INTO email_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

// Load current settings
$settings = [
    'smtp_host' => get_email_setting($pdo, 'smtp_host', ''),
    'smtp_port' => get_email_setting($pdo, 'smtp_port', '587'),
    'smtp_username' => get_email_setting($pdo, 'smtp_username', ''),
    'smtp_password' => get_email_setting($pdo, 'smtp_password', ''),
    'smtp_encryption' => get_email_setting($pdo, 'smtp_encryption', 'tls'),
    'from_name' => get_email_setting($pdo, 'from_name', 'Candytex System'),
    'from_email' => get_email_setting($pdo, 'from_email', ''),
    'reply_to' => get_email_setting($pdo, 'reply_to', ''),
];

$msg = '';
$error = '';
$test_result = '';

// --- HANDLE FORM SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // Save Settings
    if (isset($_POST['save_settings'])) {
        $fields = [
            'smtp_host',
            'smtp_port',
            'smtp_username',
            'smtp_password',
            'smtp_encryption',
            'from_name',
            'from_email',
            'reply_to'
        ];
        foreach ($fields as $f) {
            set_email_setting($pdo, $f, trim($_POST[$f] ?? ''));
        }
        // Reload
        foreach ($fields as $f) {
            $settings[$f] = trim($_POST[$f] ?? '');
        }
        $msg = "✅ Email settings saved successfully! / تم حفظ إعدادات البريد بنجاح!";
        audit_log($pdo, 'email_settings', "Updated email settings — Host: {$settings['smtp_host']}");
    }

    // Send Test Email
    if (isset($_POST['send_test'])) {
        $test_to = trim($_POST['test_email'] ?? '');
        if (empty($test_to) || !filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address / أدخل عنوان بريد إلكتروني صالح";
        } elseif (empty($settings['smtp_host']) || empty($settings['smtp_username'])) {
            $error = "Please save SMTP settings first / يرجى حفظ إعدادات SMTP أولاً";
        } else {
            // Try sending via SMTP using fsockopen
            $result = send_smtp_test($settings, $test_to);
            if ($result === true) {
                $test_result = "success";
                $msg = "✅ Test email sent successfully to $test_to";
                audit_log($pdo, 'email_test', "Test email sent to: $test_to");
            } else {
                $test_result = "fail";
                $error = "❌ Failed to send: $result";
            }
        }
    }
}

/**
 * Send a test email using SMTP via fsockopen (no external libraries needed)
 */
function send_smtp_test($cfg, $to)
{
    $host = $cfg['smtp_host'];
    $port = intval($cfg['smtp_port']);
    $user = $cfg['smtp_username'];
    $pass = $cfg['smtp_password'];
    $enc = $cfg['smtp_encryption'];
    $from_email = $cfg['from_email'] ?: $user;
    $from_name = $cfg['from_name'] ?: 'Candytex';

    $subject = "=?UTF-8?B?" . base64_encode("🔧 Candytex - Test Email / بريد تجريبي") . "?=";
    $body = "This is a test email from Candytex Factory Management System.\n\n"
        . "هذا بريد تجريبي من نظام إدارة مصنع كانديتكس.\n\n"
        . "If you received this email, your SMTP settings are configured correctly!\n"
        . "إذا استلمت هذا البريد، فإن إعدادات SMTP مهيأة بشكل صحيح!\n\n"
        . "— Candytex System\n"
        . "Sent at: " . date('Y-m-d H:i:s');

    try {
        $prefix = ($enc === 'ssl') ? 'ssl://' : '';
        $timeout = 10;

        $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return "Connection failed: $errstr ($errno)";
        }

        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '220') {
            fclose($socket);
            return "Server not ready: $response";
        }

        // EHLO
        fwrite($socket, "EHLO candytex.ma\r\n");
        $response = '';
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ')
                break;
        }

        // STARTTLS for TLS
        if ($enc === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $response = fgets($socket, 512);
            if (substr($response, 0, 3) !== '220') {
                fclose($socket);
                return "STARTTLS failed: $response";
            }
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            // Re-EHLO after STARTTLS
            fwrite($socket, "EHLO candytex.ma\r\n");
            $response = '';
            while ($line = fgets($socket, 512)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ')
                    break;
            }
        }

        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '334') {
            fclose($socket);
            return "AUTH failed: $response";
        }

        fwrite($socket, base64_encode($user) . "\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '334') {
            fclose($socket);
            return "Username rejected: $response";
        }

        fwrite($socket, base64_encode($pass) . "\r\n");
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '235') {
            fclose($socket);
            return "Authentication failed — check username/password";
        }

        // MAIL FROM
        fwrite($socket, "MAIL FROM:<$from_email>\r\n");
        $response = fgets($socket, 512);

        // RCPT TO
        fwrite($socket, "RCPT TO:<$to>\r\n");
        $response = fgets($socket, 512);

        // DATA
        fwrite($socket, "DATA\r\n");
        $response = fgets($socket, 512);

        // Headers + Body
        $headers = "From: $from_name <$from_email>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Date: " . date('r') . "\r\n";
        $headers .= "\r\n";
        $headers .= $body . "\r\n.\r\n";

        fwrite($socket, $headers);
        $response = fgets($socket, 512);
        if (substr($response, 0, 3) !== '250') {
            fclose($socket);
            return "Message rejected: $response";
        }

        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return true;
    } catch (Exception $e) {
        return "Exception: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Settings - SQD+C</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .email-container {
            max-width: 800px;
            margin: 0 auto;
        }

        .settings-card {
            background: white;
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 24px;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8e8e8;
        }

        .settings-card h3 {
            margin: 0 0 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 16px;
        }

        .form-row.single {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: #444;
            margin-bottom: 6px;
            font-size: 13px;
        }

        .form-group label small {
            font-weight: normal;
            color: #888;
        }

        .form-group input,
        .form-group select {
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.15);
        }

        .form-group input[type="password"] {
            font-family: monospace;
            letter-spacing: 2px;
        }

        .btn-row {
            display: flex;
            gap: 12px;
            margin-top: 8px;
            flex-wrap: wrap;
        }

        .btn-save {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s, box-shadow 0.2s;
        }

        .btn-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        /* Test Email Section */
        .test-section {
            background: linear-gradient(135deg, #f8f9ff 0%, #f0f4ff 100%);
            border: 2px dashed #667eea;
        }

        .test-form {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .test-form .form-group {
            flex: 1;
            min-width: 200px;
        }

        .btn-test {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            white-space: nowrap;
            transition: transform 0.1s, box-shadow 0.2s;
        }

        .btn-test:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        /* Alerts */
        .alert-msg {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Password toggle */
        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            width: 100%;
            padding-right: 40px;
            box-sizing: border-box;
        }

        .toggle-pass {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 18px;
            padding: 4px;
            color: #888;
        }

        /* Status indicator */
        .status-dot {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 6px;
        }

        .status-configured {
            background: #28a745;
            box-shadow: 0 0 6px rgba(40, 167, 69, 0.4);
        }

        .status-not-configured {
            background: #dc3545;
            box-shadow: 0 0 6px rgba(220, 53, 69, 0.4);
        }

        /* Help text */
        .help-text {
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        /* Info box */
        .info-box {
            background: #e7f3ff;
            color: #004085;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 4px solid #007bff;
        }

        /* Common providers */
        .providers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .provider-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 10px 14px;
            cursor: pointer;
            text-align: center;
            font-size: 13px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
        }

        .provider-btn:hover {
            border-color: #667eea;
            background: #f8f9ff;
            transform: translateY(-1px);
        }

        .provider-btn.active {
            border-color: #667eea;
            background: #f0f4ff;
            font-weight: 600;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .test-form {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <!-- Top Navigation -->
    <div class="top-nav">
        <div class="top-nav-header">
            <h3>📧 Email Settings</h3>
        </div>
        <div class="nav-links">
            <a href="admin.php">🔙 Admin Panel</a>
            <a href="admin_advanced.php">⚙️ Advanced</a>
            <a href="index.php?logout=1" class="logout">Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="email-container">
            <h1>📧 Email Configuration / إعدادات البريد الإلكتروني</h1>
            <p>Configure the SMTP server used for sending notifications and alerts.
                <br><small style="color:#888;">إعداد خادم SMTP المستخدم لإرسال الإشعارات والتنبيهات.</small>
            </p>

            <!-- Status -->
            <?php
            $is_configured = !empty($settings['smtp_host']) && !empty($settings['smtp_username']);
            ?>
            <div class="info-box">
                <span class="status-dot <?= $is_configured ? 'status-configured' : 'status-not-configured' ?>"></span>
                <?php if ($is_configured): ?>
                    <strong>Configured</strong> — SMTP:
                    <?= htmlspecialchars($settings['smtp_host']) ?>:
                    <?= htmlspecialchars($settings['smtp_port']) ?>
                    (
                    <?= strtoupper($settings['smtp_encryption']) ?>)
                <?php else: ?>
                    <strong>Not Configured</strong> — Please fill in your SMTP details below.
                    / <strong>غير مهيأ</strong> — يرجى ملء بيانات SMTP أدناه.
                <?php endif; ?>
            </div>

            <?php if ($msg): ?>
                <div class="alert-msg alert-success">
                    <?= htmlspecialchars($msg) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-msg alert-error">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <!-- Quick Presets -->
            <div class="settings-card">
                <h3>⚡ Quick Presets / إعدادات سريعة</h3>
                <p style="font-size:13px; color:#666; margin-top:0;">Click a provider to auto-fill SMTP settings:
                    <br><small>اضغط على مزوّد لملء إعدادات SMTP تلقائياً</small>
                </p>
                <div class="providers-grid">
                    <button type="button" class="provider-btn" onclick="fillPreset('smtp.gmail.com', 587, 'tls')">
                        📮 Gmail
                    </button>
                    <button type="button" class="provider-btn" onclick="fillPreset('smtp.office365.com', 587, 'tls')">
                        📘 Outlook / Office 365
                    </button>
                    <button type="button" class="provider-btn" onclick="fillPreset('smtp.mail.yahoo.com', 587, 'tls')">
                        📧 Yahoo Mail
                    </button>
                    <button type="button" class="provider-btn" onclick="fillPreset('smtp.zoho.com', 465, 'ssl')">
                        🔷 Zoho Mail
                    </button>
                    <button type="button" class="provider-btn" onclick="fillPreset('mail.candytex.ma', 465, 'ssl')">
                        🏭 Candytex (Custom)
                    </button>
                    <button type="button" class="provider-btn" onclick="fillPreset('smtp.hostinger.com', 465, 'ssl')">
                        🌐 Hostinger
                    </button>
                </div>
            </div>

            <!-- SMTP Settings Form -->
            <form method="POST">
                <?= csrf_field() ?>

                <div class="settings-card">
                    <h3>🔧 SMTP Server / خادم SMTP</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>SMTP Host / المضيف <small>(e.g. smtp.gmail.com)</small></label>
                            <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host']) ?>"
                                placeholder="smtp.example.com" required>
                        </div>
                        <div class="form-group">
                            <label>SMTP Port / المنفذ</label>
                            <input type="number" name="smtp_port"
                                value="<?= htmlspecialchars($settings['smtp_port']) ?>" placeholder="587">
                            <span class="help-text">TLS: 587, SSL: 465, None: 25</span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Encryption / التشفير</label>
                            <select name="smtp_encryption" id="smtp_encryption">
                                <option value="tls" <?= $settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS
                                    (Recommended / موصى)</option>
                                <option value="ssl" <?= $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL
                                </option>
                                <option value="none" <?= $settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>
                                    None / بدون</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <h3>🔐 Authentication / المصادقة</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Username / اسم المستخدم <small>(usually your email)</small></label>
                            <input type="text" name="smtp_username"
                                value="<?= htmlspecialchars($settings['smtp_username']) ?>"
                                placeholder="user@example.com">
                        </div>
                        <div class="form-group">
                            <label>Password / كلمة المرور</label>
                            <div class="password-wrapper">
                                <input type="password" name="smtp_password" id="smtp_password"
                                    value="<?= htmlspecialchars($settings['smtp_password']) ?>" placeholder="••••••••">
                                <button type="button" class="toggle-pass" onclick="togglePassword()">👁️</button>
                            </div>
                            <span class="help-text">For Gmail, use an "App Password" / بالنسبة لـ Gmail، استخدم "كلمة
                                مرور التطبيق"</span>
                        </div>
                    </div>
                </div>

                <div class="settings-card">
                    <h3>📤 Sender Info / بيانات المرسل</h3>

                    <div class="form-row">
                        <div class="form-group">
                            <label>From Name / اسم المرسل</label>
                            <input type="text" name="from_name" value="<?= htmlspecialchars($settings['from_name']) ?>"
                                placeholder="Candytex System">
                        </div>
                        <div class="form-group">
                            <label>From Email / بريد المرسل</label>
                            <input type="email" name="from_email"
                                value="<?= htmlspecialchars($settings['from_email']) ?>"
                                placeholder="noreply@candytex.ma">
                            <span class="help-text">Leave empty to use SMTP username / اتركه فارغاً لاستخدام اسم
                                المستخدم</span>
                        </div>
                    </div>

                    <div class="form-row single">
                        <div class="form-group">
                            <label>Reply-To / الرد على</label>
                            <input type="email" name="reply_to" value="<?= htmlspecialchars($settings['reply_to']) ?>"
                                placeholder="admin@candytex.ma">
                            <span class="help-text">Optional: where replies go / اختياري: أين تذهب الردود</span>
                        </div>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" name="save_settings" class="btn-save">💾 Save Settings / حفظ
                        الإعدادات</button>
                </div>
            </form>

            <!-- Test Email Section -->
            <div class="settings-card test-section" style="margin-top:24px;">
                <h3>🧪 Test Email / إرسال بريد تجريبي</h3>
                <p style="font-size:13px; color:#555; margin-top:0;">Send a test email to verify your SMTP
                    configuration.
                    <br><small>أرسل بريداً تجريبياً للتحقق من إعدادات SMTP.</small>
                </p>
                <form method="POST" class="test-form">
                    <?= csrf_field() ?>
                    <div class="form-group">
                        <label>Recipient Email / البريد المستلم</label>
                        <input type="email" name="test_email" placeholder="test@example.com" required>
                    </div>
                    <button type="submit" name="send_test" class="btn-test">📨 Send Test / إرسال تجريبي</button>
                </form>
            </div>

            <!-- Info -->
            <div class="settings-card" style="margin-top:24px; background:#fffcf0; border-color:#ffc107;">
                <h3>ℹ️ Gmail Setup Guide / دليل إعداد Gmail</h3>
                <ol style="font-size:13px; color:#555; line-height:1.8;">
                    <li>Go to <a href="https://myaccount.google.com/security" target="_blank">Google Account
                            Security</a></li>
                    <li>Enable <strong>2-Step Verification</strong> / فعّل التحقق بخطوتين</li>
                    <li>Go to <a href="https://myaccount.google.com/apppasswords" target="_blank">App Passwords</a>
                    </li>
                    <li>Create a new app password for "Mail" / أنشئ كلمة مرور تطبيق جديدة</li>
                    <li>Use the 16-character password here / استخدم كلمة المرور المكونة من 16 حرفاً هنا</li>
                </ol>
            </div>

        </div>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('smtp_password');
            const btn = event.currentTarget;
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🔒';
            } else {
                input.type = 'password';
                btn.textContent = '👁️';
            }
        }

        function fillPreset(host, port, encryption) {
            document.querySelector('[name="smtp_host"]').value = host;
            document.querySelector('[name="smtp_port"]').value = port;
            document.getElementById('smtp_encryption').value = encryption;

            // Highlight active
            document.querySelectorAll('.provider-btn').forEach(b => b.classList.remove('active'));
            event.currentTarget.classList.add('active');

            // Smooth scroll to settings
            document.querySelector('.settings-card:nth-child(2)').scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            });
        }
    </script>
</body>

</html>