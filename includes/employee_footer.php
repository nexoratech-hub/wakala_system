<?php
// ================================================================
// FILE: includes/employee_footer.php
// WAKALA SYSTEM - EMPLOYEE FOOTER
// COMPLETE DARK MODE SUPPORT
// ================================================================
?>
</body>
</html>

<!-- ============================================================
EMPLOYEE FOOTER
============================================================ -->
<footer class="employee-footer">
    <div class="footer-content">
        <span>&copy; <?php echo date('Y'); ?> Wakala System. All rights reserved.</span>
        <span class="footer-version">v2.0</span>
    </div>
</footer>

<style>
/* ============================================================
   EMPLOYEE FOOTER
   ============================================================ */
.employee-footer {
    background: #ffffff;
    padding: 10px 20px;
    border-top: 1px solid #e5e7eb;
    margin-top: auto;
    transition: background 0.3s ease, border-color 0.3s ease;
}

body.dark-mode .employee-footer {
    background: #1e293b;
    border-color: #334155;
}

.employee-footer .footer-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 12px;
    color: #6b7280;
}

body.dark-mode .employee-footer .footer-content {
    color: #94a3b8;
}

.employee-footer .footer-version {
    font-weight: 600;
    color: #bb0404;
}

@media (max-width: 768px) {
    .employee-footer {
        padding: 8px 14px;
    }
    .employee-footer .footer-content {
        font-size: 10px;
        flex-direction: column;
        gap: 4px;
        text-align: center;
    }
}
</style>