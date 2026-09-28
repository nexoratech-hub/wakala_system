<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\login.php
// WAKALA FINANCIAL SYSTEM - LOGIN PAGE
// ✅ Compact card (max 1000px width, ~520px height)
// ✅ Dark mode inabadilisha background ya page pia
// ✅ Split: Left (brand), Right (form)
// ✅ Forgot Password (OTP + reset link)
// ================================================================

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CHECK LOGIN
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? 'employee';
    if ($role === 'super_admin' || $role === 'admin') {
        header('Location: modules/dashboard/admin.php');
        exit();
    } else {
        header('Location: modules/dashboard/employee.php');
        exit();
    }
}

$error = '';
$success = '';
$forgot_error = '';
$forgot_success = '';

// ============================================================
// CREATE DEFAULT ADMIN IF NOT EXISTS
// ============================================================
try {
    $check_stmt = $db->prepare("SELECT id FROM employees WHERE username = 'admin'");
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() == 0) {
        $default_password = password_hash('12345678', PASSWORD_DEFAULT);
        $insert_stmt = $db->prepare("
            INSERT INTO employees (employee_id, full_name, email, phone, username, password_hash, role, branch, is_active)
            VALUES ('EMP-001', 'Mbembati Kelvin', 'admin@wakala.com', '+255 700 000 001', 'admin', ?, 'super_admin', 'Main', 1)
        ");
        $insert_stmt->execute([$default_password]);
    }
} catch (PDOException $e) {}

// ============================================================
// HANDLE LOGIN
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, is_active, email 
                                  FROM employees 
                                  WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'Invalid username or password.';
            } else {
                if (password_verify($password, $user['password_hash'])) {
                    $update_stmt = $db->prepare("UPDATE employees SET last_login = NOW() WHERE id = ?");
                    $update_stmt->execute([$user['id']]);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $user['role'];
                    
                    logActivity($user['id'], 'Login', 'Authentication');
                    
                    if ($user['role'] === 'super_admin' || $user['role'] === 'admin') {
                        header('Location: modules/dashboard/admin.php');
                    } else {
                        header('Location: modules/dashboard/employee.php');
                    }
                    exit();
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        } catch (PDOException $e) {
            $error = 'System error. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}

// ============================================================
// HANDLE FORGOT PASSWORD (OTP + Link)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_password') {
    $email = trim($_POST['forgot_email'] ?? '');
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $forgot_error = 'Please enter a valid email address.';
    } else {
        try {
            $stmt = $db->prepare("
                SELECT id, username, full_name, email 
                FROM employees 
                WHERE email = ? 
                  AND role IN ('admin', 'super_admin') 
                  AND is_active = 1 
                LIMIT 1
            ");
            $stmt->execute([$email]);
            $admin_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$admin_user) {
                $forgot_success = 'If this email is registered as an admin, a reset code will be sent.';
            } else {
                $reset_token = bin2hex(random_bytes(32));
                $reset_otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $reset_expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                try {
                    $del_stmt = $db->prepare("DELETE FROM password_resets WHERE email = ?");
                    $del_stmt->execute([$email]);
                    
                    $check_col = $db->prepare("SHOW COLUMNS FROM password_resets LIKE 'user_id'");
                    $check_col->execute();
                    $has_user_id = $check_col->rowCount() > 0;
                    
                    if ($has_user_id) {
                        $ins_stmt = $db->prepare("
                            INSERT INTO password_resets (user_id, email, token, otp, expires_at, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())
                        ");
                        $ins_stmt->execute([$admin_user['id'], $email, $reset_token, $reset_otp, $reset_expires]);
                    } else {
                        $ins_stmt = $db->prepare("
                            INSERT INTO password_resets (email, token, otp, expires_at, created_at)
                            VALUES (?, ?, ?, ?, NOW())
                        ");
                        $ins_stmt->execute([$email, $reset_token, $reset_otp, $reset_expires]);
                    }
                } catch (PDOException $e) {
                    error_log("Password reset token save error: " . $e->getMessage());
                }
                
                $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                    . '://' . $_SERVER['HTTP_HOST'] 
                    . dirname($_SERVER['PHP_SELF']) 
                    . '/reset_password.php?token=' . $reset_token;
                
                $subject = 'Wakala System - Password Reset Code';
                $message = "Hello " . $admin_user['full_name'] . ",\n\n";
                $message .= "You requested a password reset for your Wakala System account.\n\n";
                $message .= "🔐 YOUR OTP CODE: " . $reset_otp . "\n\n";
                $message .= "OR click this link to reset:\n";
                $message .= $reset_link . "\n\n";
                $message .= "This OTP/link will expire in 1 hour.\n\n";
                $message .= "If you did not request this, please ignore this email.\n\n";
                $message .= "— Wakala Financial System";
                
                $headers = "From: no-reply@wakala.com\r\n";
                $headers .= "Reply-To: no-reply@wakala.com\r\n";
                
                @mail($email, $subject, $message, $headers);
                
                error_log("[WAKALA RESET] Email: $email | OTP: $reset_otp | Token: $reset_token");
                
                logActivity($admin_user['id'], 'Password Reset Requested', 'Authentication', $admin_user['id'], '', 'Email: ' . $email);
                
                $forgot_success = 'Password reset code has been sent to your email.';
            }
        } catch (PDOException $e) {
            $forgot_error = 'System error. Please try again later.';
            error_log("Forgot password error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" id="htmlRoot">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wakala Financial System - Sign In</title>
    <link rel="icon" href="assets/images/logo.PNG" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           GLOBAL
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            /* LIGHT MODE */
            --page-bg: linear-gradient(135deg, #8B0000 0%, #DC2626 50%, #FF4444 100%);
            --page-pattern-dot: rgba(255, 255, 255, 0.08);
            --page-blob: rgba(255, 255, 255, 0.06);
            
            --bg-card: #FFFFFF;
            --bg-input: #F9FAFB;
            --bg-input-focus: #FFFFFF;
            --text-primary: #1F2937;
            --text-secondary: #4B5563;
            --text-muted: #6B7280;
            --text-light: #9CA3AF;
            --border-color: #E5E7EB;
            --border-focus: #DC2626;
            
            --brand-dark: #8B0000;
            --brand-mid: #DC2626;
            --brand-light: #FF4444;
            
            --shadow-lg: 0 25px 70px rgba(0, 0, 0, 0.35);
        }
        
        /* ✅ DARK MODE - Different background */
        html.dark-mode {
            --page-bg: linear-gradient(135deg, #0F172A 0%, #1E293B 50%, #334155 100%);
            --page-pattern-dot: rgba(255, 255, 255, 0.04);
            --page-blob: rgba(220, 38, 38, 0.08);
            
            --bg-card: #1E293B;
            --bg-input: #334155;
            --bg-input-focus: #1E293B;
            --text-primary: #F1F5F9;
            --text-secondary: #CBD5E1;
            --text-muted: #94A3B8;
            --text-light: #64748B;
            --border-color: #334155;
            --border-focus: #EF4444;
            
            --shadow-lg: 0 25px 70px rgba(0, 0, 0, 0.6);
        }
        
        html, body {
            height: 100%;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            overflow-x: hidden;
            transition: background 0.5s ease, color 0.3s ease;
        }
        
        /* ✅ DYNAMIC BACKGROUND */
        body {
            background: var(--page-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow-x: hidden;
            transition: background 0.5s ease;
        }
        
        /* Decorative blobs */
        body::before {
            content: '';
            position: fixed;
            top: -15%;
            left: -8%;
            width: 450px;
            height: 450px;
            background: radial-gradient(circle, var(--page-blob) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            transition: background 0.5s ease;
        }
        
        body::after {
            content: '';
            position: fixed;
            bottom: -15%;
            right: -8%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, var(--page-blob) 0%, transparent 70%);
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            transition: background 0.5s ease;
        }
        
        /* Dot pattern */
        .bg-pattern {
            position: fixed;
            inset: 0;
            background-image: radial-gradient(circle, var(--page-pattern-dot) 1px, transparent 1px);
            background-size: 30px 30px;
            pointer-events: none;
            z-index: 0;
            transition: background-image 0.5s ease;
        }
        
        /* ============================================================
           DARK MODE TOGGLE - TOP RIGHT
           ============================================================ */
        .dark-mode-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            width: 46px;
            height: 46px;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            color: #FFFFFF;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .dark-mode-toggle:hover {
            transform: scale(1.1) rotate(15deg);
            background: rgba(255, 255, 255, 0.25);
            border-color: rgba(255, 255, 255, 0.5);
        }
        
        html.dark-mode .dark-mode-toggle {
            background: rgba(30, 41, 59, 0.85);
            border-color: rgba(148, 163, 184, 0.4);
            color: #FBBF24;
        }
        
        html.dark-mode .dark-mode-toggle:hover {
            background: rgba(51, 65, 85, 0.95);
        }
        
        .dark-mode-toggle i {
            transition: transform 0.4s ease;
        }
        
        html.dark-mode .dark-mode-toggle i {
            transform: rotate(180deg);
        }
        
        /* ============================================================
           LOGIN CARD - COMPACT HEIGHT
           ============================================================ */
        .login-card {
            width: 100%;
            max-width: 1000px;
            min-height: 480px;
            max-height: 520px;
            background: var(--bg-card);
            border-radius: 22px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            display: flex;
            position: relative;
            z-index: 1;
            animation: cardIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            transition: background 0.5s ease, box-shadow 0.5s ease;
        }
        
        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(30px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }
        
        /* ============================================================
           LEFT: BRAND PANEL
           ============================================================ */
        .brand-panel {
            flex: 1;
            background: linear-gradient(135deg, #8B0000 0%, #DC2626 50%, #FF4444 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 35px;
            position: relative;
            overflow: hidden;
            color: #FFFFFF;
            min-width: 0;
        }
        
        html.dark-mode .brand-panel {
            background: linear-gradient(135deg, #450a0a 0%, #7f1d1d 50%, #991b1b 100%);
        }
        
        .brand-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 30% 30%, rgba(255,255,255,0.1) 0%, transparent 40%),
                radial-gradient(circle at 70% 70%, rgba(255,255,255,0.08) 0%, transparent 40%);
            animation: floatBg 20s ease-in-out infinite;
            pointer-events: none;
        }
        
        @keyframes floatBg {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            50% { transform: translate(-5%, -5%) rotate(5deg); }
        }
        
        .brand-content {
            position: relative;
            z-index: 1;
            text-align: center;
            max-width: 350px;
        }
        
        .brand-logo-wrapper {
            display: inline-block;
            padding: 12px;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.18);
            backdrop-filter: blur(12px);
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .brand-logo-wrapper:hover {
            transform: translateY(-4px) scale(1.03);
            background: rgba(255, 255, 255, 0.25);
        }
        
        .brand-logo {
            width: 75px;
            height: 75px;
            object-fit: contain;
            display: block;
            filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.2));
        }
        
        .brand-title {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: -0.6px;
            margin-bottom: 4px;
            text-shadow: 0 3px 12px rgba(0, 0, 0, 0.2);
            line-height: 1.1;
        }
        
        .brand-subtitle {
            font-size: 12px;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 22px;
            letter-spacing: 0.5px;
        }
        
        .brand-divider {
            width: 45px;
            height: 2.5px;
            background: rgba(255, 255, 255, 0.4);
            margin: 0 auto 20px;
            border-radius: 2px;
        }
        
        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .brand-feature {
            display: flex;
            align-items: center;
            gap: 11px;
            padding: 10px 14px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            border-radius: 11px;
            border: 1px solid rgba(255, 255, 255, 0.15);
            text-align: left;
            transition: all 0.3s ease;
        }
        
        .brand-feature:hover {
            background: rgba(255, 255, 255, 0.16);
            transform: translateX(4px);
        }
        
        .brand-feature-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .brand-feature-text h4 {
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 1px;
        }
        
        .brand-feature-text p {
            font-size: 10px;
            opacity: 0.85;
            font-weight: 400;
        }
        
        /* ============================================================
           RIGHT: FORM PANEL
           ============================================================ */
        .form-panel {
            flex: 1;
            background: var(--bg-card);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 38px;
            transition: background 0.5s ease;
            min-width: 0;
        }
        
        .form-container {
            width: 100%;
            max-width: 340px;
        }
        
        .form-header {
            margin-bottom: 22px;
        }
        
        .form-header h2 {
            font-size: 23px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 3px;
            letter-spacing: -0.4px;
        }
        
        .form-header p {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        /* ============================================================
           ALERTS
           ============================================================ */
        .alert {
            padding: 10px 13px;
            border-radius: 9px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            animation: shake 0.4s ease;
            font-weight: 500;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-6px); }
            75% { transform: translateX(6px); }
        }
        
        .alert i { font-size: 14px; flex-shrink: 0; }
        
        .alert-danger {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #991B1B;
        }
        
        .alert-success {
            background: #D1FAE5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }
        
        html.dark-mode .alert-danger {
            background: #7F1D1D; color: #FECACA; border-color: #991B1B;
        }
        
        html.dark-mode .alert-success {
            background: #065F46; color: #D1FAE5; border-color: #10B981;
        }
        
        /* ============================================================
           FORM
           ============================================================ */
        .form-group { margin-bottom: 15px; }
        
        .form-group label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }
        
        .form-group label .required {
            color: var(--brand-mid);
            margin-left: 2px;
        }
        
        .input-wrapper { position: relative; }
        
        .input-wrapper .input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 13px;
            transition: color 0.3s ease;
            pointer-events: none;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 11px 13px 11px 40px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 13px;
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            transition: all 0.3s ease;
            background: var(--bg-input);
            color: var(--text-primary);
            outline: none;
        }
        
        .input-wrapper input:focus {
            border-color: var(--border-focus);
            background: var(--bg-input-focus);
            box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.08);
        }
        
        .input-wrapper input:focus ~ .input-icon,
        .input-wrapper input:focus + .input-icon {
            color: var(--brand-mid);
        }
        
        .input-wrapper input::placeholder {
            color: var(--text-light);
            font-weight: 400;
            font-size: 12px;
        }
        
        .password-toggle {
            position: absolute;
            right: 13px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 13px;
            padding: 4px;
            transition: color 0.3s ease;
        }
        
        .password-toggle:hover { color: var(--brand-mid); }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 11px;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .remember-me input[type="checkbox"] {
            width: 14px;
            height: 14px;
            accent-color: var(--brand-mid);
            cursor: pointer;
        }
        
        .forgot-link {
            font-size: 11px;
            color: var(--brand-mid);
            text-decoration: none;
            font-weight: 700;
            transition: all 0.2s ease;
            cursor: pointer;
            background: none;
            border: none;
            font-family: inherit;
        }
        
        .forgot-link:hover {
            color: var(--brand-dark);
            text-decoration: underline;
        }
        
        html.dark-mode .forgot-link:hover { color: var(--brand-light); }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            color: #FFFFFF;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
        }
        
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(220, 38, 38, 0.5);
        }
        
        .btn-login:active:not(:disabled) { transform: translateY(0); }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .btn-login .spinner {
            display: none;
            width: 15px;
            height: 15px;
            border: 2.5px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #FFFFFF;
            animation: spin 0.8s linear infinite;
        }
        
        .btn-login.loading .spinner { display: inline-block; }
        .btn-login.loading .btn-text { display: none; }
        
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .form-footer {
            text-align: center;
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }
        
        .form-footer p {
            font-size: 10px;
            color: var(--text-muted);
            line-height: 1.5;
        }
        
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 8px;
            padding: 4px 10px;
            background: var(--bg-input);
            border-radius: 16px;
            font-size: 9px;
            color: var(--text-muted);
            border: 1px solid var(--border-color);
            font-weight: 500;
        }
        
        .security-badge i { color: #10B981; font-size: 10px; }
        
        /* ============================================================
           FORGOT PASSWORD MODAL
           ============================================================ */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.2s ease;
        }
        
        .modal-overlay.show { display: flex; }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-box {
            background: var(--bg-card);
            border-radius: 18px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 70px rgba(0,0,0,0.5);
            animation: modalSlide 0.3s ease;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }
        
        @keyframes modalSlide {
            from { opacity: 0; transform: translateY(20px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .modal-header {
            padding: 20px 24px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header-left {
            display: flex;
            align-items: center;
            gap: 11px;
        }
        
        .modal-header-icon {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-size: 17px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }
        
        .modal-header h3 {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 1px;
        }
        
        .modal-header p {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 500;
        }
        
        .modal-close {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: all 0.2s ease;
        }
        
        .modal-close:hover {
            background: var(--brand-mid);
            color: #FFFFFF;
            transform: rotate(90deg);
        }
        
        .modal-body { padding: 20px 24px 24px; }
        
        .modal-body p.modal-desc {
            font-size: 12px;
            color: var(--text-secondary);
            line-height: 1.6;
            margin-bottom: 16px;
        }
        
        .modal-actions {
            display: flex;
            gap: 9px;
            margin-top: 18px;
        }
        
        .btn-cancel-modal {
            flex: 1;
            padding: 10px;
            background: var(--bg-input);
            border: 1.5px solid var(--border-color);
            color: var(--text-secondary);
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-cancel-modal:hover {
            background: var(--border-color);
            color: var(--text-primary);
        }
        
        .btn-submit-modal {
            flex: 2;
            padding: 10px;
            background: linear-gradient(135deg, var(--brand-dark), var(--brand-mid));
            color: #FFFFFF;
            border: none;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        
        .btn-submit-modal:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220, 38, 38, 0.45);
        }
        
        .btn-submit-modal:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 900px) {
            .login-card {
                flex-direction: column;
                max-width: 440px;
                min-height: auto;
                max-height: none;
            }
            
            .brand-panel {
                padding: 30px 24px 26px;
            }
            
            .brand-title { font-size: 20px; }
            .brand-subtitle { font-size: 11px; margin-bottom: 0; }
            .brand-logo { width: 55px; height: 55px; }
            .brand-logo-wrapper { padding: 10px; margin-bottom: 12px; }
            .brand-features { display: none; }
            .brand-divider { display: none; }
            
            .form-panel { padding: 26px 24px 30px; }
        }
        
        @media (max-width: 480px) {
            body { padding: 12px; }
            
            .brand-panel { padding: 24px 18px 20px; }
            .brand-title { font-size: 18px; }
            .brand-logo { width: 48px; height: 48px; }
            
            .form-panel { padding: 22px 18px 26px; }
            .form-header h2 { font-size: 20px; }
            .form-container { max-width: 100%; }
            
            .dark-mode-toggle {
                width: 40px;
                height: 40px;
                font-size: 15px;
                top: 10px;
                right: 10px;
            }
        }
    </style>
</head>
<body>

<div class="bg-pattern"></div>

<!-- DARK MODE TOGGLE -->
<button type="button" class="dark-mode-toggle" id="darkModeToggle" title="Toggle Dark Mode">
    <i class="fas fa-moon" id="darkModeIcon"></i>
</button>

<!-- LOGIN CARD -->
<div class="login-card">
    
    <!-- LEFT: BRAND PANEL -->
    <div class="brand-panel">
        <div class="brand-content">
            
            <div class="brand-logo-wrapper">
                <img src="assets/images/logo.PNG" 
                     alt="Wakala Logo" 
                     class="brand-logo"
                     onerror="this.style.display='none'; this.parentElement.innerHTML='<i class=\'fas fa-building\' style=\'font-size:44px;color:#FFF;\'></i>';">
            </div>
            
            <h1 class="brand-title">Wakala System</h1>
            <p class="brand-subtitle">Financial Management Platform</p>
            
            <div class="brand-divider"></div>
            
            <div class="brand-features">
                <div class="brand-feature">
                    <div class="brand-feature-icon">
                        <i class="fas fa-shield-alt"></i>
                    </div>
                    <div class="brand-feature-text">
                        <h4>Secure & Reliable</h4>
                        <p>Bank-level encryption</p>
                    </div>
                </div>
                
                <div class="brand-feature">
                    <div class="brand-feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="brand-feature-text">
                        <h4>Real-time Reports</h4>
                        <p>Track daily & monthly financials</p>
                    </div>
                </div>
                
                <div class="brand-feature">
                    <div class="brand-feature-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="brand-feature-text">
                        <h4>Team Management</h4>
                        <p>Manage employees & payroll</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- RIGHT: FORM PANEL -->
    <div class="form-panel">
        <div class="form-container">
            
            <div class="form-header">
                <h2>Welcome Back</h2>
                <p>Sign in to access your dashboard</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm" autocomplete="off">
                <input type="hidden" name="action" value="login">
                
                <div class="form-group">
                    <label for="username">
                        Username <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="Enter your username"
                               value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                               required 
                               autofocus>
                        <i class="fas fa-user input-icon"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">
                        Password <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required>
                        <i class="fas fa-lock input-icon"></i>
                        <button type="button" 
                                class="password-toggle" 
                                id="togglePassword"
                                title="Show password">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" id="remember">
                        Remember me
                    </label>
                    <button type="button" class="forgot-link" onclick="openForgotModal()">
                        Forgot Password?
                    </button>
                </div>
                
                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="spinner"></span>
                    <span class="btn-text">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </span>
                </button>
            </form>
            
            <div class="form-footer">
                <p>&copy; <?php echo date('Y'); ?> Mbembati Kelvin L, T/A Wakala</p>
                <div class="security-badge">
                    <i class="fas fa-shield-alt"></i>
                    <span>Secure Connection</span>
                </div>
            </div>
        </div>
    </div>
    
</div>

<!-- FORGOT PASSWORD MODAL -->
<div class="modal-overlay" id="forgotModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-header-icon">
                    <i class="fas fa-key"></i>
                </div>
                <div>
                    <h3>Reset Password</h3>
                    <p>Admin only</p>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeForgotModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="modal-body">
            <p class="modal-desc">
                Enter your admin email address. We'll send you a password reset code (OTP) and a reset link.
                <br><small style="color:var(--text-muted);font-size:10px;display:block;margin-top:6px;">
                    <i class="fas fa-info-circle"></i> 
                    Only <strong>Admin</strong> and <strong>Super Admin</strong> emails are accepted.
                </small>
            </p>
            
            <?php if (!empty($forgot_error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo htmlspecialchars($forgot_error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($forgot_success)): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo htmlspecialchars($forgot_success); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="forgotForm" autocomplete="off">
                <input type="hidden" name="action" value="forgot_password">
                
                <div class="form-group">
                    <label for="forgot_email">
                        Admin Email <span class="required">*</span>
                    </label>
                    <div class="input-wrapper">
                        <input type="email" 
                               id="forgot_email" 
                               name="forgot_email" 
                               placeholder="admin@wakala.com"
                               required>
                        <i class="fas fa-envelope input-icon"></i>
                    </div>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel-modal" onclick="closeForgotModal()">
                        Cancel
                    </button>
                    <button type="submit" class="btn-submit-modal" id="forgotSubmitBtn">
                        <i class="fas fa-paper-plane"></i>
                        Send Code
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ============================================================
// DARK MODE
// ============================================================
const htmlRoot = document.getElementById('htmlRoot');
const darkModeToggle = document.getElementById('darkModeToggle');
const darkModeIcon = document.getElementById('darkModeIcon');

function applyDarkMode(isDark) {
    if (isDark) {
        htmlRoot.classList.add('dark-mode');
        darkModeIcon.className = 'fas fa-sun';
    } else {
        htmlRoot.classList.remove('dark-mode');
        darkModeIcon.className = 'fas fa-moon';
    }
}

const savedDarkMode = localStorage.getItem('darkMode') === 'true';
applyDarkMode(savedDarkMode);

darkModeToggle.addEventListener('click', function() {
    const isDark = !htmlRoot.classList.contains('dark-mode');
    applyDarkMode(isDark);
    localStorage.setItem('darkMode', isDark);
    document.dispatchEvent(new CustomEvent('darkModeChanged', { detail: { isDark } }));
});

// ============================================================
// PASSWORD TOGGLE
// ============================================================
const togglePassword = document.getElementById('togglePassword');
const passwordInput = document.getElementById('password');
const eyeIcon = document.getElementById('eyeIcon');

togglePassword.addEventListener('click', function() {
    const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
    passwordInput.setAttribute('type', type);
    eyeIcon.className = type === 'password' ? 'fas fa-eye' : 'fas fa-eye-slash';
    togglePassword.title = type === 'password' ? 'Show password' : 'Hide password';
});

// ============================================================
// LOGIN FORM LOADING
// ============================================================
const loginForm = document.getElementById('loginForm');
const loginBtn = document.getElementById('loginBtn');

if (loginForm) {
    loginForm.addEventListener('submit', function() {
        loginBtn.classList.add('loading');
        loginBtn.disabled = true;
    });
}

// ============================================================
// REMEMBER ME
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const rememberCheck = document.getElementById('remember');
    const usernameInput = document.getElementById('username');
    
    if (!rememberCheck || !usernameInput) return;
    
    if (localStorage.getItem('remembered_username')) {
        usernameInput.value = localStorage.getItem('remembered_username');
        rememberCheck.checked = true;
    }
    
    loginForm.addEventListener('submit', function() {
        if (rememberCheck.checked) {
            localStorage.setItem('remembered_username', usernameInput.value);
        } else {
            localStorage.removeItem('remembered_username');
        }
    });
});

// ============================================================
// FORGOT PASSWORD MODAL
// ============================================================
const forgotModal = document.getElementById('forgotModal');
const forgotForm = document.getElementById('forgotForm');
const forgotSubmitBtn = document.getElementById('forgotSubmitBtn');

function openForgotModal() {
    forgotModal.classList.add('show');
    document.body.style.overflow = 'hidden';
    setTimeout(function() {
        const emailInput = document.getElementById('forgot_email');
        if (emailInput) emailInput.focus();
    }, 100);
}

function closeForgotModal() {
    forgotModal.classList.remove('show');
    document.body.style.overflow = '';
}

forgotModal.addEventListener('click', function(e) {
    if (e.target === forgotModal) closeForgotModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && forgotModal.classList.contains('show')) {
        closeForgotModal();
    }
});

if (forgotForm) {
    forgotForm.addEventListener('submit', function() {
        forgotSubmitBtn.disabled = true;
        forgotSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
    });
}

<?php if (!empty($forgot_error) || !empty($forgot_success)): ?>
document.addEventListener('DOMContentLoaded', function() {
    openForgotModal();
});
<?php endif; ?>

// ============================================================
// AUTO-FOCUS
// ============================================================
window.addEventListener('load', function() {
    setTimeout(function() {
        const usernameInput = document.getElementById('username');
        if (usernameInput && !usernameInput.value) usernameInput.focus();
    }, 300);
});
</script>

</body>
</html>