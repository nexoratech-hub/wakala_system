<?php
// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\includes\admin_footer.php
// WAKALA FINANCIAL SYSTEM - SHARED ADMIN FOOTER
// ================================================================

// ============================================================
// GET COMPANY NAME FROM DATABASE
// ============================================================
$company_name = 'Wakala System'; // Default name

try {
    global $db;
    if (isset($db)) {
        $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'company_name'");
        $stmt->execute();
        $result = $stmt->fetch();
        if ($result && !empty($result['setting_value'])) {
            $company_name = $result['setting_value'];
        }
    }
} catch (Exception $e) {
    // Settings table might not exist yet
    $company_name = 'Wakala System';
}
?>
    <!-- ============================================================
    FOOTER
    ============================================================ -->
    <footer class="admin-footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($company_name); ?></p>
        <div class="footer-info">
            <span>Version 2.0.0</span>
            <span>|</span>
            <span>Powered by <strong>Wakala System</strong></span>
            <span>|</span>
            <span id="liveDateTime"></span>
        </div>
    </footer>

    <!-- ============================================================
    STYLES
    ============================================================ -->
    <style>
        .admin-footer {
            background: var(--topbar-bg, #FFFFFF);
            padding: 15px 25px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            box-shadow: 0 -1px 3px rgba(0,0,0,0.05);
            transition: background 0.3s ease;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .admin-footer p {
            font-size: 13px;
            color: var(--text-secondary, #6B7280);
            font-weight: 500;
        }
        
        .admin-footer .footer-info {
            font-size: 12px;
            color: var(--text-light, #9CA3AF);
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .admin-footer .footer-info strong {
            color: #DC2626;
        }
        
        @media (max-width: 480px) {
            .admin-footer {
                flex-direction: column;
                text-align: center;
                padding: 12px 15px;
            }
            
            .admin-footer .footer-info {
                justify-content: center;
            }
        }
    </style>

    <!-- ============================================================
    SCRIPTS
    ============================================================ -->
    <script src="../../assets/js/main.js"></script>
    <script src="../../assets/js/dashboard.js"></script>
    
    <!-- Live DateTime -->
    <script>
        function updateDateTime() {
            const now = new Date();
            const options = { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            };
            const el = document.getElementById('liveDateTime');
            if (el) {
                el.textContent = now.toLocaleDateString('en-US', options);
            }
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>