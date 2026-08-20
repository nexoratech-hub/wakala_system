<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\includes\admin_header.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN HEADER
// USED IN ALL ADMIN PAGES
// ================================================================

// Get current page for active state
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir = basename(dirname($_SERVER['PHP_SELF']));

// Get user data
$full_name = $_SESSION['full_name'] ?? 'Admin';
$role = $_SESSION['role'] ?? 'admin';
$user_id = $_SESSION['user_id'] ?? 0;

// Get profile image - use logo as default
$profile_image = '../../assets/images/logo.PNG';
?>
<!DOCTYPE html>
<html lang="en" id="html-root">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?> - Admin</title>
    
    <!-- Favicon -->
    <link rel="icon" href="../../assets/images/logo.PNG" type="image/png">
    <link rel="shortcut icon" href="../../assets/images/logo.PNG" type="image/png">
    
    <!-- Fonts & Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../../assets/css/dashboard.css">
    <link rel="stylesheet" href="../../assets/css/dark-mode.css">
    
    <style>
        /* ============================================================
           BASE STYLES
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: var(--bg-body, #F3F4F6);
            display: flex;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        /* ============================================================
           MAIN CONTENT WRAPPER
           ============================================================ */
        .main-wrapper {
            margin-left: 260px;
            width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .main-content {
            padding: 20px;
            flex: 1;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            .main-wrapper {
                margin-left: 0;
            }
        }
    </style>
</head>
<body>