<?php
// ================================================================
// FILE: includes/employee_header.php
// WAKALA SYSTEM - EMPLOYEE HEADER
// WITH FAVICON - FIXED
// 
// ✅ Uses html.dark-mode for full page dark mode
// ✅ No padding-top (handled by main-wrapper)
// ✅ No margin-left (handled by main-wrapper)
// ✅ Touches sidebar edge
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
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
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
        }
        
        /* ============================================================
           LIGHT MODE (Default)
           ============================================================ */
        html {
            --bg-body: #f3f4f6;
            --bg-card: #ffffff;
            --bg-input: #f9fafb;
            --bg-hover: #f3f4f6;
            --text-primary: #1f2937;
            --text-secondary: #374151;
            --text-muted: #6b7280;
            --text-light: #9ca3af;
            --border-color: #e5e7eb;
            --shadow-color: rgba(0,0,0,0.06);
            --shadow-hover: rgba(0,0,0,0.12);
            --sidebar-width: 220px;
            --topbar-height: 70px;
        }
        
        /* ============================================================
           DARK MODE - Applied to html element for FULL PAGE coverage
           ============================================================ */
        html.dark-mode {
            --bg-body: #0f172a;
            --bg-card: #1e293b;
            --bg-input: #334155;
            --bg-hover: #2d3a4f;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --text-light: #64748b;
            --border-color: #334155;
            --shadow-color: rgba(0,0,0,0.3);
            --shadow-hover: rgba(0,0,0,0.4);
        }
        
        /* ============================================================
           BODY - Uses CSS variables, NO padding (handled by main-wrapper)
           ============================================================ */
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            width: 100%;
            max-width: 100vw;
            overflow-x: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }
        
        /* ============================================================
           EMPLOYEE WRAPPER - NO margin-left (main-wrapper has it)
           ============================================================ */
        .employee-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 100%;
            flex: 1;
            background: var(--bg-body);
        }
        
        .employee-content {
            padding: 16px 20px 20px 20px;
            flex: 1;
            background: var(--bg-body);
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media (max-width: 1024px) {
            .employee-content {
                padding: 14px 16px 18px 16px;
            }
        }
        
        @media (max-width: 768px) {
            .employee-content {
                padding: 12px 14px 16px 14px;
            }
        }
        
        @media (max-width: 480px) {
            .employee-content {
                padding: 10px 10px 14px 10px;
            }
        }
        
        /* ============================================================
           SCROLLBAR
           ============================================================ */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: var(--bg-hover);
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb {
            background: #bb0404;
            border-radius: 3px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #8a0303;
        }
        html.dark-mode ::-webkit-scrollbar-track {
            background: #1e293b;
        }
        html.dark-mode ::-webkit-scrollbar-thumb {
            background: #bb0404;
        }
        
        /* ============================================================
           UTILITY CLASSES
           ============================================================ */
        .text-primary { color: var(--text-primary) !important; }
        .text-secondary { color: var(--text-secondary) !important; }
        .text-muted { color: var(--text-muted) !important; }
        .text-light { color: var(--text-light) !important; }
        .bg-card { background: var(--bg-card) !important; }
        .bg-input { background: var(--bg-input) !important; }
        .border-color { border-color: var(--border-color) !important; }
    </style>
</head>
<body>