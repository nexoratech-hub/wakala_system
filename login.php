<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\login.php
// WAKALA FINANCIAL SYSTEM - LOGIN PAGE
// FIXED: Session handling order
// ================================================================

// ============================================================
// ===== FIX: Include config FIRST, then start session =====
// ============================================================

// Include config and database FIRST (before session)
require_once 'config/config.php';
require_once 'config/database.php';  // Session settings are set here if session not active
require_once 'includes/functions.php';

// ============================================================
// ===== Start session AFTER config =====
// ============================================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// ===== Check login =====
// ============================================================

// If already logged in, redirect to dashboard
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

// ============================================================
// ===== Create default admin if not exists =====
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
        $success = 'Default admin account created. Please login with username: admin, password: 12345678';
    }
} catch (PDOException $e) {
    // Ignore errors
}

// ============================================================
// ===== Handle login =====
// ============================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        try {
            $stmt = $db->prepare("SELECT id, username, password_hash, full_name, role, is_active 
                                  FROM employees 
                                  WHERE username = ? AND is_active = 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if (!$user) {
                $error = 'Username not found. Default: admin / 12345678';
            } else {
                if (password_verify($password, $user['password_hash'])) {
                    $update_stmt = $db->prepare("UPDATE employees SET last_login = NOW() WHERE id = ?");
                    $update_stmt->execute([$user['id']]);
                    
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    
                    logActivity($user['id'], 'Login', 'Authentication');
                    
                    if ($user['role'] === 'super_admin' || $user['role'] === 'admin') {
                        header('Location: modules/dashboard/admin.php');
                    } else {
                        header('Location: modules/dashboard/employee.php');
                    }
                    exit();
                } else {
                    $error = 'Invalid password. Default is: 12345678';
                }
            }
        } catch (PDOException $e) {
            $error = 'System error. Please try again later.';
            error_log("Login error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wakala Financial System - Login</title>
    <link rel="icon" href="assets/images/logo.PNG" type="image/png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           LOGIN PAGE STYLES
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #8B0000 0%, #CC0000 50%, #FF4444 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 15px;
            position: relative;
            overflow: hidden;
        }
        
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: 
                radial-gradient(circle at 20% 50%, rgba(255,255,255,0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 50%, rgba(255,255,255,0.05) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .login-container {
            background: #FFFFFF;
            border-radius: 16px;
            box-shadow: 0 15px 50px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 400px;
            padding: 20px 28px 18px;
            position: relative;
            z-index: 1;
            animation: slideUp 0.4s ease-out;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 12px;
        }
        
        .logo-wrapper {
            display: inline-block;
            padding: 8px;
            border-radius: 12px;
            border: 2px solid #DC2626;
            background: white;
            box-shadow: 0 3px 10px rgba(220,38,38,0.1);
        }
        
        .logo-wrapper img {
            width: 55px;
            height: 55px;
            object-fit: contain;
            display: block;
        }
        
        .company-name {
            font-size: 14px;
            font-weight: 700;
            color: #8B0000;
            margin-top: 5px;
            letter-spacing: -0.3px;
        }
        
        .company-tagline {
            font-size: 10px;
            color: #6B7280;
            font-weight: 400;
            margin-top: 1px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 10px;
        }
        
        .login-header h1 {
            font-size: 17px;
            font-weight: 700;
            color: #1F2937;
            margin-bottom: 1px;
        }
        
        .login-header p {
            font-size: 12px;
            color: #6B7280;
        }
        
        .default-credentials {
            background: #FEF3C7;
            border: 1px solid #FDE68A;
            color: #92400E;
            padding: 4px 10px;
            border-radius: 6px;
            margin-bottom: 10px;
            font-size: 11px;
            text-align: center;
        }
        
        .default-credentials strong {
            color: #78350F;
        }
        
        .alert {
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
            animation: shake 0.4s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }
        
        .alert-danger {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            color: #DC2626;
        }
        
        .alert-danger i {
            font-size: 13px;
            color: #DC2626;
        }
        
        .alert-success {
            background: #D1FAE5;
            border: 1px solid #A7F3D0;
            color: #065F46;
        }
        
        .alert-success i {
            font-size: 13px;
            color: #065F46;
        }
        
        .form-group {
            margin-bottom: 10px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 3px;
        }
        
        .form-group label .required {
            color: #DC2626;
            margin-left: 2px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 16px;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 12px 14px 12px 44px;
            border: 2px solid #E5E7EB;
            border-radius: 10px;
            font-size: 16px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s ease;
            background: #F9FAFB;
            color: #1F2937;
        }
        
        .input-wrapper input:focus {
            outline: none;
            border-color: #DC2626;
            background: #FFFFFF;
            box-shadow: 0 0 0 4px rgba(220,38,38,0.08);
        }
        
        .input-wrapper input::placeholder {
            color: #9CA3AF;
            font-size: 14px;
        }
        
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #9CA3AF;
            cursor: pointer;
            font-size: 16px;
            padding: 4px;
        }
        
        .password-toggle:hover {
            color: #DC2626;
        }
        
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 4px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            font-size: 13px;
            color: #4B5563;
        }
        
        .remember-me input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #DC2626;
            cursor: pointer;
        }
        
        .forgot-link {
            font-size: 13px;
            color: #DC2626;
            text-decoration: none;
            font-weight: 500;
        }
        
        .forgot-link:hover {
            color: #8B0000;
            text-decoration: underline;
        }
        
        .btn-login {
            width: 100%;
            padding: 13px;
            background: linear-gradient(135deg, #8B0000, #DC2626);
            color: #FFFFFF;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(220,38,38,0.3);
        }
        
        .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-login .spinner {
            display: none;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #FFFFFF;
            animation: spin 0.8s linear infinite;
        }
        
        .btn-login.loading .spinner {
            display: inline-block;
        }
        
        .btn-login.loading .btn-text {
            display: none;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .login-footer {
            text-align: center;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #F3F4F6;
        }
        
        .login-footer p {
            font-size: 11px;
            color: #6B7280;
        }
        
        .login-footer .version {
            font-size: 10px;
            color: #9CA3AF;
            margin-top: 1px;
        }
        
        .security-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 6px;
            font-size: 10px;
            color: #9CA3AF;
        }
        
        .security-badge i {
            color: #10B981;
            font-size: 10px;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 15px 18px 14px;
                border-radius: 14px;
            }
            .logo-wrapper img {
                width: 45px;
                height: 45px;
            }
            .company-name {
                font-size: 13px;
            }
            .login-header h1 {
                font-size: 15px;
            }
            .input-wrapper input {
                padding: 10px 12px 10px 38px;
                font-size: 14px;
            }
            .btn-login {
                padding: 11px;
                font-size: 14px;
            }
        }
        
        @media (max-width: 380px) {
            .login-container {
                padding: 12px 14px 12px;
            }
            .logo-wrapper img {
                width: 38px;
                height: 38px;
            }
            .login-header h1 {
                font-size: 14px;
            }
            .input-wrapper input {
                padding: 8px 10px 8px 34px;
                font-size: 13px;
            }
            .btn-login {
                padding: 10px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-wrapper">
                <img src="assets/images/logo.PNG" alt="Wakala Logo" 
                     onerror="this.src='assets/images/default-avatar.png';">
            </div>
            <div class="company-name">Mbembati Kelvin L</div>
            <div class="company-tagline">T/A Wakala - Financial Management</div>
        </div>
        
        <!-- Login Header -->
        <div class="login-header">
            <h1>Welcome Back</h1>
            <p>Sign in to your account</p>
        </div>
        
        <!-- Default Credentials -->
        <div class="default-credentials">
            <i class="fas fa-info-circle"></i>
            <strong>Default:</strong> admin / 12345678
        </div>
        
        <!-- Success Message -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Error Message -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Login Form -->
        <form method="POST" action="" id="loginForm" autocomplete="off">
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
                            id="togglePassword">
                        <i class="fas fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember" id="remember">
                    Remember me
                </label>
                <a href="#" class="forgot-link">Forgot Password?</a>
            </div>
            
            <button type="submit" class="btn-login" id="loginBtn">
                <span class="spinner"></span>
                <span class="btn-text">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </span>
            </button>
        </form>
        
        <!-- Footer -->
        <div class="login-footer">
            <p>&copy; <?php echo date('Y'); ?> Mbembati Kelvin L, T/A Wakala</p>
            <div class="version">Version 2.0.0</div>
            <div class="security-badge">
                <i class="fas fa-shield-alt"></i> Secure
                <span>•</span>
                <i class="fas fa-lock"></i> Encrypted
            </div>
        </div>
    </div>
    
    <script>
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
        });
        
        // ============================================================
        // LOADING STATE
        // ============================================================
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        loginForm.addEventListener('submit', function() {
            loginBtn.classList.add('loading');
            loginBtn.disabled = true;
        });
        
        // ============================================================
        // REMEMBER ME
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            const rememberCheck = document.getElementById('remember');
            const usernameInput = document.getElementById('username');
            
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
        // AUTO-FOCUS
        // ============================================================
        window.addEventListener('load', function() {
            setTimeout(() => {
                document.getElementById('username').focus();
            }, 300);
        });
        
        console.log('%c WAKALA FINANCIAL SYSTEM v2.0 ',
            'background:#8B0000; color:white; padding:8px 16px; border-radius:4px; font-size:14px; font-weight:bold;');
        console.log('📧 Default Login: admin');
        console.log('🔑 Default Password: 12345678');
    </script>
</body>
</html>