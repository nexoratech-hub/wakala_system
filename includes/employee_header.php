<?php
// ================================================================
// FILE: includes/employee_header.php
// WAKALA SYSTEM - EMPLOYEE HEADER
// WITH FAVICON - FIXED
// ================================================================

$page_title = $page_title ?? 'Dashboard';

// Get user data from session
$full_name = $_SESSION['full_name'] ?? 'Employee';
$role = $_SESSION['role'] ?? 'employee';
$user_id = $_SESSION['user_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wakala System - <?php echo htmlspecialchars($page_title); ?></title>
    
    <!-- FAVICON -->
    <link rel="icon" type="image/png" href="../../assets/images/logo.PNG">
    <link rel="shortcut icon" type="image/png" href="../../assets/images/logo.PNG">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        /* ============================================================
           GLOBAL RESET & BASE
           ============================================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html, body {
            height: 100%;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            display: flex;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
            padding-top: 56px;
        }
        
        body.dark-mode {
            background: #0f172a;
            color: #f1f5f9;
        }
        
        /* ============================================================
           EMPLOYEE WRAPPER
           ============================================================ */
        .employee-wrapper {
            margin-left: 240px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: 100%;
            flex: 1;
        }
        
        .employee-content {
            padding: 16px 20px 20px 20px;
            flex: 1;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 768px) {
            body {
                padding-top: 50px;
            }
            
            .employee-wrapper {
                margin-left: 0;
            }
            
            .employee-content {
                padding: 10px 12px 16px 12px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding-top: 44px;
            }
        }
        
        body.dark-mode .employee-content {
            background: #0f172a;
        }
        
        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        ::-webkit-scrollbar-thumb {
            background: #bb0404;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #8a0303;
        }
        body.dark-mode ::-webkit-scrollbar-track {
            background: #1e293b;
        }
        body.dark-mode ::-webkit-scrollbar-thumb {
            background: #bb0404;
        }
    </style>
</head>
<body>