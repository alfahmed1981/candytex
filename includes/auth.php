<?php
// includes/auth.php - Security & Authentication Utilities

// --- SECURITY HEADERS ---
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Referrer-Policy: strict-origin-when-cross-origin");

// --- CSRF TOKEN MANAGEMENT ---
function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}

function verify_csrf($token = null)
{
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

function require_csrf()
{
    if (!verify_csrf()) {
        http_response_code(403);
        die("⛔ Security Error: Invalid or missing CSRF token. Please refresh the page and try again.");
    }
}

// --- ACCESS CONTROL ---
function require_login()
{
    if (!isset($_SESSION['user_cin'])) {
        header("Location: index.php");
        exit;
    }
}

function require_admin()
{
    require_login();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        http_response_code(403);
        die("⛔ Access Denied. Admins Only.");
    }
}

function is_admin()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function is_hr()
{
    return isset($_SESSION['role']) && $_SESSION['role'] === 'hr';
}

function require_hr_or_admin()
{
    require_login();
    if (!is_admin() && !is_hr()) {
        http_response_code(403);
        die("⛔ Access Denied. HR or Admin clearance required.");
    }
}

// --- IMPERSONATION HELPERS ---
function start_impersonation($pdo, $target_cin)
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE cin = ?");
    $stmt->execute([$target_cin]);
    $target = $stmt->fetch();

    if ($target) {
        // Save original admin session
        $_SESSION['original_admin_cin'] = $_SESSION['user_cin'];
        $_SESSION['original_admin_name'] = $_SESSION['user_name'];
        $_SESSION['is_impersonating'] = true;

        // Switch to target user
        $_SESSION['user_cin'] = $target['cin'];
        $_SESSION['user_name'] = $target['name'];
        $_SESSION['role'] = $target['role'];
        return true;
    }
    return false;
}

function stop_impersonation()
{
    if (!empty($_SESSION['is_impersonating']) && !empty($_SESSION['original_admin_cin'])) {
        $_SESSION['user_cin'] = $_SESSION['original_admin_cin'];
        $_SESSION['user_name'] = $_SESSION['original_admin_name'];
        $_SESSION['role'] = 'admin';
        unset($_SESSION['is_impersonating'], $_SESSION['original_admin_cin'], $_SESSION['original_admin_name']);
        return true;
    }
    return false;
}

function is_impersonating()
{
    return !empty($_SESSION['is_impersonating']);
}

// --- AUDIT LOGGING ---
function audit_log($pdo, $action, $details = '')
{
    try {
        // Create table if not exists (self-healing)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `audit_log` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_cin` VARCHAR(20) NOT NULL,
            `action` VARCHAR(100) NOT NULL,
            `details` TEXT,
            `ip_address` VARCHAR(45),
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $stmt = $pdo->prepare("INSERT INTO audit_log (user_cin, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_cin'] ?? 'system',
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
    } catch (PDOException $e) {
        error_log("Audit log failed: " . $e->getMessage());
    }
}
?>