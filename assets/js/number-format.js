// ================================================================
// FILE: C:\xampp\htdocs\wakala_system\assets\js\number-format.js
// WAKALA FINANCIAL SYSTEM - AUTO FORMAT NUMBERS WITH COMMAS
// ================================================================

/**
 * Format number with commas (e.g., 1000000 -> 1,000,000)
 * @param {number|string} value - The number to format
 * @returns {string} Formatted number with commas
 */
function formatNumberWithCommas(value) {
    // Remove any existing commas and non-numeric characters (keep only digits and decimal)
    let num = String(value).replace(/,/g, '').replace(/[^0-9.]/g, '');
    
    // If empty or not a number, return empty
    if (num === '' || isNaN(num)) return '';
    
    // Parse as float
    let number = parseFloat(num);
    
    // Format with commas
    return number.toLocaleString('en-US');
}

/**
 * Format number with commas and currency symbol
 * @param {number|string} value - The number to format
 * @returns {string} Formatted number with TSh
 */
function formatCurrencyWithCommas(value) {
    let formatted = formatNumberWithCommas(value);
    return formatted !== '' ? 'TSh ' + formatted : '';
}

/**
 * Parse formatted number back to raw number
 * @param {string} formatted - The formatted string (e.g., "1,000,000")
 * @returns {number} Raw number
 */
function parseFormattedNumber(formatted) {
    if (!formatted) return 0;
    // Remove commas and convert to number
    return parseFloat(String(formatted).replace(/,/g, '')) || 0;
}

/**
 * Auto-format input field on input event
 * @param {HTMLElement} input - The input element
 */
function autoFormatNumber(input) {
    // Get raw value (remove commas)
    let raw = input.value.replace(/,/g, '');
    
    // If empty, set empty
    if (raw === '') {
        input.dataset.rawValue = '';
        return;
    }
    
    // Check if valid number
    if (!isNaN(raw) && raw !== '') {
        // Store raw value as data attribute
        input.dataset.rawValue = raw;
        
        // Format with commas
        let formatted = formatNumberWithCommas(raw);
        
        // Only update if different to avoid cursor jump
        if (input.value !== formatted) {
            // Save cursor position
            let cursorPos = input.selectionStart;
            let lengthDiff = formatted.length - input.value.length;
            
            input.value = formatted;
            
            // Restore cursor position
            if (cursorPos !== null) {
                input.setSelectionRange(cursorPos + lengthDiff, cursorPos + lengthDiff);
            }
        }
    }
}

/**
 * Get raw value from formatted input
 * @param {HTMLElement} input - The input element
 * @returns {number} Raw number value
 */
function getRawNumberValue(input) {
    if (input.dataset.rawValue !== undefined && input.dataset.rawValue !== '') {
        return parseFloat(input.dataset.rawValue) || 0;
    }
    return parseFormattedNumber(input.value);
}

/**
 * Update total display with commas
 * @param {number} total - The total number
 * @param {string} elementId - The element ID to update
 */
function updateTotalDisplay(total, elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        let formatted = formatNumberWithCommas(total);
        element.textContent = 'TSh ' + formatted;
    }
}

// ============================================================
// AUTO-FORMAT ALL AMOUNT FIELDS ON PAGE LOAD
// ============================================================

document.addEventListener('DOMContentLoaded', function() {
    // Find all amount input fields
    const amountInputs = document.querySelectorAll('.amount-input, input[name*="amount"], input[name*="cash"], input[name*="balance"], .provider-input');
    
    amountInputs.forEach(input => {
        // Format on input
        input.addEventListener('input', function(e) {
            autoFormatNumber(this);
            
            // Trigger any calculation events
            if (typeof calculateTotal === 'function') {
                calculateTotal();
            }
        });
        
        // Format on blur (final format)
        input.addEventListener('blur', function() {
            autoFormatNumber(this);
            if (typeof calculateTotal === 'function') {
                calculateTotal();
            }
        });
        
        // Format on focus (remove commas for editing)
        input.addEventListener('focus', function() {
            if (this.dataset.rawValue !== undefined && this.dataset.rawValue !== '') {
                this.value = this.dataset.rawValue;
            } else {
                this.value = this.value.replace(/,/g, '');
            }
        });
        
        // Initial format
        if (input.value && input.value !== '') {
            autoFormatNumber(input);
        }
    });
});

// Make functions globally available
window.formatNumberWithCommas = formatNumberWithCommas;
window.formatCurrencyWithCommas = formatCurrencyWithCommas;
window.parseFormattedNumber = parseFormattedNumber;
window.autoFormatNumber = autoFormatNumber;
window.getRawNumberValue = getRawNumberValue;
window.updateTotalDisplay = updateTotalDisplay;

console.log('%c NUMBER FORMAT v1.0 ',
    'background:#10B981; color:white; padding:4px 10px; border-radius:4px; font-size:12px; font-weight:bold;');
console.log('%c ✅ Auto-format numbers with commas enabled (1,000,000) ',
    'color:#6B7280; font-size:11px;');