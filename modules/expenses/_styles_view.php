<?php /* RED theme for view pages */ ?>
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
html, body { overflow-x: hidden !important; max-width: 100vw !important; }
.main-wrapper { overflow-x: hidden !important; max-width: 100% !important; }
.main-content { padding: 16px 20px !important; max-width: 100% !important; }
body { background: var(--exp-bg) !important; color: var(--exp-text); }
.main-wrapper, .main-content { background: var(--exp-bg) !important; }

.branch-status-card {
    display: flex; align-items: center; gap: 18px;
    padding: 16px 22px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 12px; margin-bottom: 16px;
    box-shadow: 0 4px 20px rgba(220, 38, 38, 0.35);
    position: relative; overflow: hidden;
    flex-wrap: wrap; color: #FFFFFF;
}
.branch-status-card::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 250px; height: 250px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 50%; pointer-events: none;
}
.branch-status-icon {
    width: 52px; height: 52px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%; display: flex; align-items: center; justify-content: center;
    font-size: 22px; color: #FFFFFF; flex-shrink: 0;
    position: relative; z-index: 1;
}
.branch-status-info {
    display: flex; align-items: center; gap: 10px;
    flex-wrap: wrap; flex: 1;
    position: relative; z-index: 1;
}
.branch-status-label {
    font-size: 11px; font-weight: 500;
    color: rgba(255, 255, 255, 0.7);
    text-transform: uppercase; letter-spacing: 1px;
}
.branch-status-name { font-size: 18px; font-weight: 700; color: #FFFFFF; }
.branch-status-code {
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.85);
    padding: 3px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
}
.btn-back-card {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.12);
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: #FFFFFF; text-decoration: none;
    font-size: 13px; font-weight: 500;
    transition: all 0.3s ease;
    position: relative; z-index: 1;
}
.btn-back-card:hover { background: rgba(255, 255, 255, 0.2); color: #FFFFFF; }

.page-header {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 16px; padding: 0 4px; flex-wrap: wrap; gap: 12px;
}
.page-header-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.page-header-left h2 { font-size: 20px; font-weight: 700; color: var(--exp-text); margin: 0; }
.page-header-left h2 i { color: #DC2626; margin-right: 8px; }
.page-subtitle {
    font-size: 13px; color: var(--exp-text-secondary);
    background: var(--exp-hover);
    padding: 3px 12px; border-radius: 12px;
    font-family: 'Courier New', monospace; font-weight: 700;
}

.expense-hero {
    display: flex; align-items: center; gap: 24px;
    padding: 28px 32px;
    background: linear-gradient(135deg, #DC2626 0%, #B91C1C 100%);
    border-radius: 16px; margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(220, 38, 38, 0.35);
    flex-wrap: wrap; color: #FFFFFF;
    position: relative; overflow: hidden;
}
.expense-hero::before {
    content: ''; position: absolute; top: -50%; right: -10%;
    width: 350px; height: 350px;
    background: rgba(255, 255, 255, 0.06);
    border-radius: 50%; pointer-events: none;
}
.hero-icon-wrap {
    width: 80px; height: 80px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px; color: #FCD34D;
    flex-shrink: 0; position: relative; z-index: 1;
    border: 2px solid rgba(255, 255, 255, 0.3);
}
.hero-info-wrap {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 6px;
    position: relative; z-index: 1;
}
.hero-label {
    font-size: 11px; font-weight: 800;
    color: rgba(255, 255, 255, 0.8);
    text-transform: uppercase; letter-spacing: 1.5px;
}
.hero-amount {
    font-size: clamp(28px, 3vw, 42px);
    font-weight: 900;
    font-family: 'Inter', 'Courier New', monospace;
    letter-spacing: -0.5px; line-height: 1.1;
    word-break: break-word;
    color: #FCD34D;
    text-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
}
.hero-meta {
    display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap; margin-top: 6px;
}
.hero-meta-item {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 600;
    color: rgba(255, 255, 255, 0.9);
    padding: 4px 12px;
    background: rgba(255, 255, 255, 0.15);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.15);
}

.details-card {
    background: var(--exp-card-bg);
    border-radius: 12px;
    border: 1px solid var(--exp-border);
    margin-bottom: 16px; overflow: hidden;
    box-shadow: 0 1px 3px var(--exp-shadow);
}
.section-title {
    font-size: 13px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1px;
    color: var(--exp-text-secondary);
    padding: 16px 22px 12px;
    border-bottom: 2px solid var(--exp-border);
    display: flex; align-items: center; gap: 8px;
    background: var(--exp-hover);
}
.section-title i { color: #DC2626; font-size: 14px; }

.info-grid {
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 0;
}
.info-item {
    padding: 16px 22px;
    display: flex; flex-direction: column; gap: 6px;
    border-bottom: 1px solid var(--exp-border);
    border-right: 1px solid var(--exp-border);
    min-width: 0;
}
.info-item:nth-child(2n) { border-right: none; }
.info-item:nth-last-child(-n+2) { border-bottom: none; }
.info-label {
    font-size: 10px; font-weight: 700;
    color: var(--exp-text-light);
    text-transform: uppercase; letter-spacing: 0.8px;
    display: flex; align-items: center; gap: 5px;
}
.info-label i { color: #DC2626; font-size: 11px; }
.info-value {
    font-size: 14px; font-weight: 700;
    color: var(--exp-text); word-break: break-word;
}
.category-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px;
    background: #FEE2E2; color: #991B1B;
    border-radius: 8px;
    font-size: 12px; font-weight: 700;
    border: 1px solid #FECACA;
}
html.dark-mode .category-badge { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.description-box {
    padding: 18px 22px;
    font-size: 14px; line-height: 1.7;
    color: var(--exp-text);
}
.notes-box {
    padding: 18px 22px;
    font-size: 14px; line-height: 1.7;
    background: #FEF3C7; color: #78350F;
    border-left: 4px solid #F59E0B;
}
html.dark-mode .notes-box { background: #5F3A1E; color: #FDE68A; border-left-color: #FBBF24; }

.receipt-box { padding: 18px 22px; }
.receipt-image {
    max-width: 100%; max-height: 400px;
    border-radius: 8px;
    border: 2px solid var(--exp-border);
    display: block;
}
.receipt-link {
    display: inline-flex; align-items: center; gap: 10px;
    padding: 12px 20px;
    background: #FEE2E2; color: #DC2626;
    border-radius: 8px; text-decoration: none;
    font-weight: 700; font-size: 14px;
    border: 1.5px solid #FECACA;
    transition: all 0.3s ease;
}
.receipt-link:hover { background: #FECACA; transform: translateY(-1px); }
html.dark-mode .receipt-link { background: #7F1D1D; color: #FCA5A5; border-color: #991B1B; }

.view-actions {
    display: flex; gap: 12px;
    padding: 16px 20px;
    background: var(--exp-card-bg);
    border-radius: 12px;
    border: 1px solid var(--exp-border);
    box-shadow: 0 1px 3px var(--exp-shadow);
    flex-wrap: wrap;
}
.btn {
    padding: 10px 22px; border-radius: 8px;
    font-weight: 700; font-size: 13px;
    border: none; cursor: pointer;
    transition: all 0.3s ease;
    font-family: 'Inter', sans-serif;
    display: inline-flex; align-items: center; gap: 6px;
    text-decoration: none; white-space: nowrap;
}
.btn-back { background: var(--exp-hover); color: var(--exp-text-secondary); }
.btn-back:hover { background: var(--exp-border); color: var(--exp-text); }

@media (max-width: 768px) {
    .main-content { padding: 12px !important; }
    .branch-status-card { flex-direction: column; align-items: flex-start; gap: 12px; }
    .btn-back-card { width: 100%; justify-content: center; }
    .page-header { flex-direction: column; align-items: flex-start; }
    .expense-hero { flex-direction: column; align-items: flex-start; padding: 20px 22px; }
    .hero-icon-wrap { width: 60px; height: 60px; font-size: 26px; }
    .info-grid { grid-template-columns: 1fr; }
    .info-item { border-right: none !important; }
    .view-actions { flex-direction: column; }
    .view-actions .btn { width: 100%; justify-content: center; }
}
@media (max-width: 480px) {
    .main-content { padding: 10px !important; }
    .hero-amount { font-size: 24px; }
    .expense-hero { padding: 18px 18px; }
}