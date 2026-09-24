<?php /* RED theme styles for employee index */ ?>
:root {
    --exp-bg: #f3f4f6;
    --exp-card-bg: #FFFFFF;
    --exp-text: #1F2937;
    --exp-text-secondary: #6B7280;
    --exp-text-light: #9CA3AF;
    --exp-border: #E5E7EB;
    --exp-hover: #F3F4F6;
    --exp-shadow: rgba(0,0,0,0.06);
}
html.dark-mode {
    --exp-bg: #0f172a;
    --exp-card-bg: #1E293B;
    --exp-text: #F1F5F9;
    --exp-text-secondary: #94A3B8;
    --exp-text-light: #64748B;
    --exp-border: #334155;
    --exp-hover: #2D3A4F;
    --exp-shadow: rgba(0,0,0,0.3);
}
* { box-sizing: border-box; }
html, body { overflow-x: hidden !important; max-width: 100% !important; }
.main-wrapper {
    overflow-x: hidden !important; max-width: 100% !important;
    margin-left: 240px;
    width: calc(100% - 240px);
    padding-top: 56px;
    min-height: 100vh;
    background: var(--exp-bg);
    transition: margin-left 0.3s ease, width 0.3s ease;
    position: relative;
}
.main-content {
    overflow-x: hidden !important; max-width: 100% !important;
    width: 100% !important;
    padding: 20px 24px !important;
}
@media (max-width: 1024px) {
    .main-wrapper { margin-left: 240px; width: calc(100% - 240px); padding-top: 56px; }
    .main-content { padding: 16px 18px !important; }
}
@media (max-width: 768px) {
    .main-wrapper { margin-left: 0; width: 100%; padding-top: 50px; }
    .main-content { padding: 16px 14px !important; }
}
body { background: var(--exp-bg) !important; color: var(--exp-text); }
.main-wrapper, .main-content { background: var(--exp-bg) !important; }

.branch-status-card {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 20px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; width: 100%; color: #FFFFFF;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 20px; color: #FCD34D; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; position: relative; z-index: 1; flex: 1;
}
.branch-status-label {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.75);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.branch-status-name { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.branch-status-code {
    font-size: 11px; font-weight: 700; color: #FCD34D;
    padding: 3px 10px;
    background: rgba(252, 211, 77, 0.2);
    border-radius: 10px;
    border: 1px solid rgba(252, 211, 77, 0.35);
    font-family: 'Courier New', monospace;
}
.branch-status-location {
    display: flex; align-items: center; gap: 4px;
    font-size: 11px; color: rgba(255, 255, 255, 0.85);
    padding: 3px 10px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 10px;
}
.branch-status-date {
    display: flex; align-items: center; gap: 5px;
    font-size: 12px; color: rgba(255, 255, 255, 0.9);
    padding: 6px 14px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    flex-shrink: 0;
}

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 14px; padding: 0 4px; flex-wrap: wrap; gap: 10px;
    width: 100%;
}
.page-header-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-header-left h2 { font-size: 18px; font-weight: 700; color: var(--exp-text); margin: 0; }
.page-header-left h2 i { color: #DC2626; margin-right: 6px; }
.record-count {
    font-size: 12px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 2px 10px; border-radius: 12px;
}
.btn-add-expense {
    background: #DC2626; color: white;
    padding: 9px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    text-decoration: none; display: inline-flex;
    align-items: center; gap: 6px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(220, 38, 38, 0.3);
}
.btn-add-expense:hover {
    background: #B91C1C; transform: translateY(-2px); color: white;
    box-shadow: 0 4px 12px rgba(220, 38, 38, 0.4);
}

.alert {
    padding: 12px 16px; border-radius: 8px;
    margin-bottom: 14px; display: flex;
    align-items: center; gap: 10px;
    font-weight: 500; font-size: 13px;
}
.alert-success { background: #D1FAE5; color: #065F46; border: 1px solid #A7F3D0; }
.alert-danger { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
html.dark-mode .alert-success { background: #065F46; color: #D1FAE5; }
html.dark-mode .alert-danger { background: #7F1D1D; color: #FEE2E2; }
.alert i { font-size: 18px; flex-shrink: 0; }
.alert span { flex: 1; }
.alert-close {
    background: transparent; border: none; font-size: 20px;
    color: inherit; cursor: pointer; opacity: 0.6;
}

.expense-hero-card {
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 16px; padding: 22px 26px; margin-bottom: 16px;
    box-shadow: 0 8px 28px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden; color: #FFFFFF;
}
.expense-hero-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.hero-header {
    display: flex; justify-content: space-between; align-items: center;
    gap: 16px; margin-bottom: 18px; padding-bottom: 14px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    position: relative; z-index: 1; flex-wrap: wrap;
}
.hero-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
.hero-icon {
    width: 48px; height: 48px;
    background: rgba(255, 255, 255, 0.18);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FCD34D;
    flex-shrink: 0;
    border: 1.5px solid rgba(252, 211, 77, 0.35);
}
.hero-info { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.hero-title { font-size: 16px; font-weight: 800; color: #FFFFFF; }
.hero-subtitle { font-size: 12px; font-weight: 500; color: rgba(255, 255, 255, 0.75); }
.hero-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(252, 211, 77, 0.25);
    color: #FCD34D; border-radius: 20px;
    font-size: 12px; font-weight: 800;
    border: 1.5px solid rgba(252, 211, 77, 0.4);
    white-space: nowrap;
}
.hero-grid {
    display: grid; grid-template-columns: repeat(3, 1fr);
    gap: 16px; position: relative; z-index: 1;
}
.hero-part {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 14px; padding: 18px 20px;
    display: flex; flex-direction: column; gap: 10px;
    border: 1.5px solid rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease; min-width: 0;
}
.hero-part:hover { background: rgba(255, 255, 255, 0.15); transform: translateY(-3px); }
.hp-header { display: flex; align-items: center; gap: 10px; }
.hp-icon {
    width: 42px; height: 42px; border-radius: 10px;
    background: rgba(255, 255, 255, 0.2);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #FFFFFF; flex-shrink: 0;
}
.hp-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.85);
    text-transform: uppercase; letter-spacing: 1.2px;
}
.hp-value {
    font-size: clamp(18px, 1.6vw, 26px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.3px; line-height: 1.15;
    word-break: break-word;
    color: #FCD34D;
    text-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
}
.hp-sub {
    font-size: 10px; font-weight: 600;
    color: rgba(255, 255, 255, 0.7);
    display: inline-flex; align-items: center; gap: 5px;
    text-transform: uppercase; letter-spacing: 0.4px;
    margin-top: auto; padding-top: 8px;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
}

.quick-stats-row {
    display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px; margin-bottom: 14px;
}
.quick-stat-item {
    background: var(--exp-card-bg);
    border-radius: 12px; padding: 14px 18px;
    display: flex; align-items: center; gap: 14px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border); min-width: 0;
}
.quick-stat-icon {
    width: 44px; height: 44px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; flex-shrink: 0;
}
html.dark-mode .quick-stat-icon { background: #7F1D1D; color: #FCA5A5; }
.quick-stat-info {
    display: flex; flex-direction: column;
    gap: 3px; min-width: 0; flex: 1;
}
.quick-stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 0.5px; font-weight: 700;
    color: var(--exp-text-secondary);
}
.quick-stat-value {
    font-size: clamp(13px, 1vw, 16px);
    font-weight: 700; color: var(--exp-text);
    word-break: break-all;
}

.table-container {
    background: var(--exp-card-bg);
    border-radius: 12px;
    box-shadow: 0 1px 3px var(--exp-shadow);
    border: 1px solid var(--exp-border);
    overflow: hidden;
}
.table-header-red-with-controls {
    display: grid; grid-template-columns: 1fr auto 1fr;
    align-items: center; gap: 16px;
    padding: 14px 18px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    color: #FFFFFF; position: relative; overflow: hidden;
}
.table-header-red-with-controls::before {
    content: ''; position: absolute; top: -50%; right: -5%;
    width: 200px; height: 200px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.thrc-left, .thrc-center, .thrc-right {
    display: flex; align-items: center;
    position: relative; z-index: 1;
}
.thrc-left { justify-content: flex-start; }
.thrc-center { justify-content: center; gap: 12px; }
.thrc-right { justify-content: flex-end; }

.search-wrapper {
    display: flex; align-items: center; gap: 8px;
    background: rgba(255, 255, 255, 0.95);
    border: 1.5px solid rgba(255, 255, 255, 0.3);
    border-radius: 8px; padding: 6px 12px;
    width: 280px; max-width: 100%;
    transition: all 0.3s ease;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}
.search-wrapper:focus-within {
    border-color: #FCD34D;
    box-shadow: 0 0 0 3px rgba(252, 211, 77, 0.3);
    background: #FFFFFF;
}
.search-wrapper i { color: #DC2626; font-size: 12px; flex-shrink: 0; }
.search-wrapper input {
    flex: 1; border: none; background: transparent;
    padding: 4px 0; font-size: 12px;
    color: #1F2937; outline: none;
    min-width: 0; font-family: 'Inter', sans-serif;
}
.search-wrapper input::placeholder { color: #9CA3AF; font-size: 11px; }
.search-wrapper button {
    width: 20px; height: 20px; border-radius: 50%;
    background: #FEE2E2; color: #DC2626;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    font-size: 9px;
}
.search-count {
    font-size: 10px; font-weight: 800;
    padding: 2px 8px;
    background: #FCD34D; color: #78350F;
    border-radius: 8px; white-space: nowrap;
}
.scroll-btn {
    width: 40px; height: 40px; border-radius: 10px;
    border: 2px solid #FFFFFF; background: #FFFFFF;
    color: #DC2626; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 800;
    box-shadow: 0 3px 10px rgba(0, 0, 0, 0.25);
    flex-shrink: 0;
}
.scroll-btn:hover {
    background: #FCD34D; color: #78350F;
    border-color: #FCD34D; transform: translateY(-2px);
}
.scroll-label {
    font-size: 11px; font-weight: 800;
    color: #FCD34D; text-transform: uppercase;
    letter-spacing: 1.2px; display: flex;
    align-items: center; gap: 6px; white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0, 0, 0, 0.4);
}
.record-count-red {
    font-size: 11px; font-weight: 700;
    color: #FFFFFF;
    background: rgba(255, 255, 255, 0.2);
    padding: 6px 14px; border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    white-space: nowrap;
    display: inline-flex; align-items: center; gap: 6px;
}
.record-count-red i { color: #FCD34D; }

.table-responsive {
    overflow-x: auto; width: 100%; max-width: 100%;
    scroll-behavior: smooth;
}
.table-responsive::-webkit-scrollbar { height: 8px; }
.table-responsive::-webkit-scrollbar-track { background: var(--exp-hover); }
.table-responsive::-webkit-scrollbar-thumb { background: #DC2626; border-radius: 4px; }

.data-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.data-table thead { background: #DC2626; }
.data-table thead th {
    padding: 11px 14px; text-align: left;
    font-weight: 600; color: #FFFFFF;
    text-transform: uppercase; font-size: 10px;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #B91C1C;
    white-space: nowrap;
}
.data-table thead th.text-right { text-align: right; }
.data-table tbody tr {
    border-bottom: 1px solid var(--exp-border);
    transition: background 0.2s ease;
}
.data-table tbody tr:hover { background: var(--exp-hover); }
.data-table tbody td {
    padding: 11px 14px; color: var(--exp-text);
    vertical-align: middle;
}
.data-table tbody td.text-right { text-align: right; }
.expense-row-item.hidden-by-search { display: none !important; }

.row-number {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--exp-hover);
    font-size: 11px; font-weight: 700;
    color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
}
.reference-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-size: 11px; font-weight: 800;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FECACA; white-space: nowrap;
}
html.dark-mode .reference-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
.date-cell {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; font-weight: 600;
    color: var(--exp-text-secondary); white-space: nowrap;
}
.date-cell i { color: #DC2626; font-size: 10px; }
.expense-name-cell { font-weight: 700; color: var(--exp-text); font-size: 12px; }
.category-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-size: 11px; font-weight: 700;
    border: 1px solid #FECACA; white-space: nowrap;
}
html.dark-mode .category-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
.amount-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 12px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-weight: 800; font-size: 12px;
    font-family: 'Courier New', monospace;
    border: 1.5px solid #FECACA; white-space: nowrap;
}
html.dark-mode .amount-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }
.type-badge-cell {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px; border-radius: 8px;
    font-size: 10px; font-weight: 800;
    text-transform: uppercase; letter-spacing: 0.5px;
    white-space: nowrap;
}
.type-badge-cell.business {
    background: #D1FAE5; color: #065F46;
    border: 1px solid #A7F3D0;
}
.type-badge-cell.personal {
    background: #FEF3C7; color: #92400E;
    border: 1px solid #FDE68A;
}
html.dark-mode .type-badge-cell.business { background: #065F46; color: #D1FAE5; border-color: #047857; }
html.dark-mode .type-badge-cell.personal { background: #5F3A1E; color: #FCD34D; border-color: #F59E0B; }
.action-buttons { display: flex; gap: 5px; justify-content: center; }
.btn-action {
    width: 34px; height: 34px; border-radius: 8px; border: none;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all 0.25s ease;
    text-decoration: none; font-size: 13px;
}
.btn-view {
    background: #FEE2E2; color: #DC2626;
    border: 1.5px solid #FECACA;
}
.btn-view:hover {
    background: #DC2626; color: #FFFFFF;
    transform: translateY(-2px) scale(1.05);
}
html.dark-mode .btn-view { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.no-results {
    text-align: center; padding: 40px 20px;
    background: var(--exp-hover); display: none;
}
.no-results i {
    font-size: 44px; color: var(--exp-text-light);
    opacity: 0.4; display: block; margin-bottom: 12px;
}
.no-results p {
    font-size: 14px; color: var(--exp-text-secondary);
    margin: 0 0 16px 0;
}
.btn-reset {
    background: var(--exp-card-bg); color: var(--exp-text-secondary);
    border: 1px solid var(--exp-border);
    padding: 8px 16px; border-radius: 8px;
    font-weight: 600; font-size: 12px;
    cursor: pointer; display: inline-flex;
    align-items: center; gap: 6px;
}
.empty-state { text-align: center; padding: 50px 20px; }
.empty-state i {
    font-size: 50px; color: #DC2626;
    opacity: 0.4; display: block; margin-bottom: 14px;
}
.empty-state h3 {
    font-size: 18px; color: var(--exp-text);
    margin: 0 0 6px 0;
}
.empty-state p {
    color: var(--exp-text-secondary);
    font-size: 13px; margin: 0 0 20px 0;
}

@media (max-width: 1024px) {
    .hero-grid { grid-template-columns: repeat(3, 1fr); gap: 12px; }
}
@media (max-width: 768px) {
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 10px; }
    .branch-status-info { width: 100%; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .hero-grid { grid-template-columns: 1fr; gap: 10px; }
    .quick-stats-row { grid-template-columns: 1fr; gap: 10px; }
    .table-header-red-with-controls { grid-template-columns: 1fr; gap: 12px; }
    .thrc-left, .thrc-center, .thrc-right { justify-content: center; width: 100%; }
    .search-wrapper { width: 100%; }
}
@media (max-width: 480px) {
    .branch-status-card { flex-direction: column; text-align: center; }
    .branch-status-info { justify-content: center; }
    .hp-value { font-size: 16px; }
    .data-table thead th, .data-table tbody td { padding: 8px 10px; font-size: 11px; }
}