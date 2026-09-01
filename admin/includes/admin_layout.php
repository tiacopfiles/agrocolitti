<?php
/**
 * AgroColitti Admin â€” Layout Helper
 * Funções: adminHead(), adminSidebar(), adminOpenMain(), adminFooter()
 */

function adminHead(string $title): void { ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> Â· Admin AgroColitti</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" crossorigin="anonymous">
<style>
/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ VARIABLES â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
:root {
    --sw: 255px;
    --bg: #07091380;
    --bg-solid: #070913;
    --surface: #0c1220;
    --card: #101827;
    --card-hov: #162036;
    --border: rgba(148,163,184,.07);
    --border-c: rgba(0,210,255,.22);
    --txt1: #e2e8f0;
    --txt2: #94a3b8;
    --txt3: #475569;
    --cyan: #00d4ff;
    --purple: #a78bfa;
    --green: #34d399;
    --amber: #fbbf24;
    --red: #f87171;
    --pink: #f472b6;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg-solid);
    color: var(--txt1);
    font-size: 14px;
    line-height: 1.5;
    min-height: 100vh;
}

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ SIDEBAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.adm-sidebar {
    width: var(--sw);
    min-height: 100vh;
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    z-index: 100;
    transition: transform .28s cubic-bezier(.4,0,.2,1);
}

/* brand */
.sb-brand {
    padding: 22px 18px 18px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 13px;
}
.sb-icon {
    width: 42px; height: 42px;
    border-radius: 11px;
    background: linear-gradient(135deg,#00d4ff,#0060e0);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: #fff;
    box-shadow: 0 0 20px rgba(0,212,255,.35);
    flex-shrink: 0;
}
.sb-brand-text h2 {
    font-size: 14px; font-weight: 700; color: var(--txt1); line-height: 1.2;
}
.sb-brand-text span {
    font-size: 10px; color: var(--txt3); text-transform: uppercase; letter-spacing: 1.2px;
}

/* nav */
.sb-nav { flex: 1; padding: 12px 0; overflow-y: auto; }
.sb-section {
    padding: 14px 18px 4px;
    font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: 1.4px;
    color: var(--txt3);
}
.sb-link {
    display: flex; align-items: center; gap: 11px;
    padding: 10px 14px; margin: 1px 8px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--txt2);
    font-size: 13px; font-weight: 500;
    transition: all .18s ease;
    border-left: 2px solid transparent;
}
.sb-link i { width: 17px; text-align: center; font-size: 13px; flex-shrink: 0; }
.sb-link:hover {
    background: rgba(0,212,255,.06);
    color: var(--txt1);
    border-left-color: rgba(0,212,255,.35);
}
.sb-link.act {
    background: rgba(0,212,255,.1);
    color: var(--cyan);
    border-left-color: var(--cyan);
    font-weight: 600;
}
.sb-link.act i { filter: drop-shadow(0 0 5px rgba(0,212,255,.6)); }
.sb-badge {
    margin-left: auto;
    background: rgba(0,212,255,.12); color: var(--cyan);
    font-size: 10px; font-weight: 700;
    padding: 1px 7px; border-radius: 10px;
}

/* footer */
.sb-footer { padding: 14px; border-top: 1px solid var(--border); }
.sb-user {
    display: flex; align-items: center; gap: 10px;
    padding: 10px; border-radius: 8px;
    background: rgba(255,255,255,.03);
    border: 1px solid var(--border);
    margin-bottom: 10px;
}
.sb-avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg,#00d4ff,#8b5cf6);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.sb-user-info .sb-uname { font-size: 12px; font-weight: 700; color: var(--txt1); }
.sb-user-info .sb-ulevel {
    font-size: 10px; color: var(--txt3); text-transform: capitalize; margin-top: 1px;
}
.btn-logout {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    width: 100%; padding: 9px;
    border-radius: 7px;
    background: rgba(248,113,113,.08);
    border: 1px solid rgba(248,113,113,.15);
    color: var(--red); font-size: 13px; font-weight: 600;
    text-decoration: none; cursor: pointer; transition: all .18s ease;
}
.btn-logout:hover { background: rgba(248,113,113,.16); border-color: rgba(248,113,113,.3); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ MAIN â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.adm-main {
    margin-left: var(--sw);
    display: flex; flex-direction: column;
    min-height: 100vh;
}
.adm-topbar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(12,18,32,.95);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 13px 26px;
    display: flex; align-items: center; justify-content: space-between; gap: 14px;
}
.topbar-left { display: flex; align-items: center; gap: 12px; }
.btn-hamburguer {
    display: none; background: none; border: none;
    color: var(--txt2); font-size: 18px; cursor: pointer;
    padding: 2px 6px; border-radius: 6px;
}
.btn-hamburguer:hover { color: var(--txt1); background: rgba(255,255,255,.06); }
.topbar-title { font-size: 16px; font-weight: 700; color: var(--txt1); }
.topbar-sub { font-size: 11px; color: var(--txt3); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.topbar-clock {
    font-family: 'Courier New', monospace;
    font-size: 13px; color: var(--cyan); opacity: .75;
}
.topbar-badge {
    font-size: 11px; font-weight: 600;
    padding: 3px 10px; border-radius: 20px;
    background: rgba(52,211,153,.1); color: var(--green);
    border: 1px solid rgba(52,211,153,.2);
    display: flex; align-items: center; gap: 5px;
}
.live-dot {
    display: inline-block; width: 6px; height: 6px;
    border-radius: 50%; background: var(--green);
    animation: pulse-g 2s infinite;
}
@keyframes pulse-g {
    0%,100%{opacity:1;box-shadow:0 0 0 0 rgba(52,211,153,.4)}
    50%{opacity:.8;box-shadow:0 0 0 4px rgba(52,211,153,0)}
}
.adm-content { padding: 26px; flex: 1; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ STATS CARDS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px,1fr));
    gap: 14px; margin-bottom: 24px;
}
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 20px;
    display: flex; align-items: center; gap: 15px;
    transition: all .22s ease;
    position: relative; overflow: hidden;
    cursor: default;
}
.stat-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: var(--c, var(--cyan)); opacity: .7;
}
.stat-card::after {
    content: '';
    position: absolute; bottom: -20px; right: -10px;
    width: 80px; height: 80px; border-radius: 50%;
    background: var(--c, var(--cyan)); opacity: .04;
}
.stat-card:hover {
    border-color: var(--border-c);
    transform: translateY(-3px);
    box-shadow: 0 10px 28px rgba(0,0,0,.35);
}
.stat-ico {
    width: 46px; height: 46px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 19px; flex-shrink: 0;
    background: rgba(var(--cr, 0,212,255),.1);
    color: var(--c, var(--cyan));
}
.stat-body { flex: 1; min-width: 0; }
.stat-val {
    font-size: 24px; font-weight: 700; color: var(--txt1);
    line-height: 1.1; font-variant-numeric: tabular-nums;
}
.stat-lbl { font-size: 11px; color: var(--txt3); margin-top: 3px; white-space: nowrap; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ PANELS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.panels-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px,1fr));
    gap: 18px; margin-bottom: 20px;
}
.panel {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px; overflow: hidden;
}
.panel-hdr {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
}
.panel-title {
    font-size: 13px; font-weight: 700; color: var(--txt1);
    display: flex; align-items: center; gap: 7px;
}
.panel-title i { color: var(--cyan); font-size: 12px; }
.panel-pill {
    font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 20px;
    background: rgba(0,212,255,.1); color: var(--cyan);
    border: 1px solid rgba(0,212,255,.2);
}
.panel-body { padding: 16px 18px; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ LOG FEED â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.log-feed { display: flex; flex-direction: column; gap: 7px; }
.log-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 9px 12px;
    background: rgba(255,255,255,.02);
    border: 1px solid var(--border);
    border-radius: 8px;
    transition: border-color .2s;
    animation: slideIn .3s ease;
}
@keyframes slideIn {
    from{opacity:0;transform:translateX(-8px)}
    to{opacity:1;transform:translateX(0)}
}
.log-item:hover { border-color: var(--border-c); }
.log-dot {
    width: 8px; height: 8px; border-radius: 50%;
    margin-top: 5px; flex-shrink: 0;
}
.log-dot.c { background: var(--green); box-shadow: 0 0 6px rgba(52,211,153,.5); }
.log-dot.e { background: var(--amber); box-shadow: 0 0 6px rgba(251,191,36,.5); }
.log-dot.x { background: var(--red);   box-shadow: 0 0 6px rgba(248,113,113,.5); }
.log-dot.o { background: var(--cyan);  box-shadow: 0 0 6px rgba(0,212,255,.5); }
.log-meta { flex: 1; min-width: 0; }
.log-action { font-size: 12px; font-weight: 600; color: var(--txt1); }
.log-desc {
    font-size: 11px; color: var(--txt3); margin-top: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.log-time { font-size: 10px; color: var(--txt3); white-space: nowrap; font-variant-numeric: tabular-nums; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ BADGES â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.badge {
    display: inline-block;
    padding: 3px 9px; border-radius: 12px;
    font-size: 11px; font-weight: 700; white-space: nowrap;
}
.b-g  { background: rgba(52,211,153,.12); color:#34d399; border:1px solid rgba(52,211,153,.25); }
.b-y  { background: rgba(251,191,36,.12); color:#fbbf24; border:1px solid rgba(251,191,36,.25); }
.b-r  { background: rgba(248,113,113,.12); color:#f87171; border:1px solid rgba(248,113,113,.25); }
.b-c  { background: rgba(0,212,255,.12);  color:#00d4ff; border:1px solid rgba(0,212,255,.25); }
.b-p  { background: rgba(167,139,250,.12);color:#a78bfa; border:1px solid rgba(167,139,250,.25); }
.b-gr { background: rgba(148,163,184,.1); color:#94a3b8; border:1px solid rgba(148,163,184,.2); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ TABLE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.tbl-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.adm-tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
.adm-tbl th {
    background: rgba(255,255,255,.025);
    color: var(--txt3); font-size: 10px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .9px;
    padding: 10px 14px; text-align: left; white-space: nowrap;
    border-bottom: 1px solid var(--border);
}
.adm-tbl td {
    padding: 11px 14px;
    border-bottom: 1px solid var(--border);
    color: var(--txt1); vertical-align: middle;
}
.adm-tbl tr:last-child td { border-bottom: none; }
.adm-tbl tr:hover td { background: rgba(255,255,255,.02); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ BUTTONS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 15px; border-radius: 7px;
    font-size: 12px; font-weight: 600; cursor: pointer;
    border: none; text-decoration: none;
    transition: all .18s ease; white-space: nowrap;
}
.btn-c { background: rgba(0,212,255,.1); color:var(--cyan); border:1px solid rgba(0,212,255,.22); }
.btn-c:hover { background: rgba(0,212,255,.18); border-color:rgba(0,212,255,.45); box-shadow:0 0 12px rgba(0,212,255,.18); }
.btn-g { background: rgba(52,211,153,.1); color:var(--green); border:1px solid rgba(52,211,153,.22); }
.btn-g:hover { background: rgba(52,211,153,.18); }
.btn-r { background: rgba(248,113,113,.1); color:var(--red); border:1px solid rgba(248,113,113,.22); }
.btn-r:hover { background: rgba(248,113,113,.18); }
.btn-p { background: rgba(167,139,250,.1); color:var(--purple); border:1px solid rgba(167,139,250,.22); }
.btn-p:hover { background: rgba(167,139,250,.18); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ FORMS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.form-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 16px; }
.form-grp { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 160px; }
.form-lbl { font-size: 11px; font-weight: 700; color: var(--txt2); text-transform: uppercase; letter-spacing: .5px; }
.form-inp, .form-sel {
    padding: 9px 11px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    border-radius: 7px; color: var(--txt1);
    font-size: 13px; outline: none;
    transition: border-color .18s, box-shadow .18s;
    width: 100%;
}
.form-inp:focus, .form-sel:focus {
    border-color: var(--border-c);
    background: rgba(0,212,255,.04);
    box-shadow: 0 0 0 3px rgba(0,212,255,.08);
}
.form-sel option { background: #101827; color: var(--txt1); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ FILTERS â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.filters { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; align-items: flex-end; }
.filters .form-grp { min-width: 140px; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ PAGINATION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.pagi { display: flex; gap: 4px; justify-content: center; flex-wrap: wrap; margin-top: 18px; }
.pagi a, .pagi span {
    padding: 6px 11px; border-radius: 6px;
    text-decoration: none; font-size: 12px; font-weight: 600;
    border: 1px solid var(--border); color: var(--txt2);
    transition: all .15s;
}
.pagi a:hover { border-color:var(--border-c); color:var(--cyan); background:rgba(0,212,255,.07); }
.pagi .cur { background:var(--cyan); color:#070913; border-color:var(--cyan); }
.pagi .dis { opacity: .3; cursor: default; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ MISC â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.sec-title {
    font-size: 14px; font-weight: 700; color: var(--txt1);
    margin-bottom: 14px; display: flex; align-items: center; gap: 8px;
}
.sec-title i { color: var(--cyan); font-size: 13px; }
.empty { text-align: center; padding: 40px; color: var(--txt3); font-size: 13px; }
.overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.65); z-index: 90;
}
.overlay.on { display: block; }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ SCROLLBAR â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: rgba(148,163,184,.18); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(148,163,184,.35); }

/* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ RESPONSIVE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
@media (max-width: 768px) {
    .adm-sidebar { transform: translateX(-100%); z-index: 200; }
    .adm-sidebar.open { transform: translateX(0); box-shadow: 6px 0 24px rgba(0,0,0,.6); }
    .adm-main { margin-left: 0; }
    .btn-hamburguer { display: block; }
    .adm-content { padding: 14px; }
    .stats-grid { grid-template-columns: repeat(2,1fr); }
    .panels-grid { grid-template-columns: 1fr; }
    .topbar-clock { display: none; }
}
@media (max-width: 480px) {
    .stats-grid { grid-template-columns: 1fr; }
}
</style>
<?php
}

/* â”€â”€â”€ Sidebar â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function adminSidebar(string $page): void {
    $nome  = htmlspecialchars($_SESSION['usuario_nome'] ?? 'Admin');
    $nivel = htmlspecialchars($_SESSION['usuario_nivel'] ?? '');
    $ini   = strtoupper(mb_substr($_SESSION['usuario_nome'] ?? 'A', 0, 1));
    $links = [
        ['dashboard', 'fa-gauge-high',     'Dashboard',  'dashboard.php'],
        ['logs',      'fa-shield-halved',   'Logs',       'logs.php'],
        ['usuarios',  'fa-users',           'Usuários',   'usuarios.php'],
        ['produtos',  'fa-boxes-stacked',   'Produtos',   'produtos.php'],
        ['vendas',    'fa-chart-line',      'Vendas',     'vendas.php'],
    ];
    ?>
<div class="overlay" id="sb-overlay" onclick="closeSidebar()"></div>
<aside class="adm-sidebar" id="adm-sidebar">

    <div class="sb-brand">
        <div class="sb-icon"><i class="fa-solid fa-leaf"></i></div>
        <div class="sb-brand-text">
            <h2>AgroColitti</h2>
            <span>Central de Controle</span>
        </div>
    </div>

    <nav class="sb-nav">
        <div class="sb-section">Painel</div>
        <?php foreach ($links as [$key, $icon, $label, $href]): ?>
        <a href="<?= htmlspecialchars($href) ?>" class="sb-link <?= $page === $key ? 'act' : '' ?>">
            <i class="fa-solid <?= $icon ?>"></i>
            <?= $label ?>
        </a>
        <?php endforeach; ?>

        
    </nav>

    <div class="sb-footer">
        <div class="sb-user">
            <div class="sb-avatar"><?= $ini ?></div>
            <div class="sb-user-info">
                <div class="sb-uname"><?= $nome ?></div>
                <div class="sb-ulevel"><?= $nivel ?></div>
            </div>
        </div>
        <a href="../auth/logout.php" class="btn-logout">
            <i class="fa-solid fa-right-from-bracket"></i>
            Sair do Sistema
        </a>
    </div>

</aside>
<?php
}

/* â”€â”€â”€ Abre o bloco principal (main + topbar) â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function adminOpenMain(string $title, string $subtitle = ''): void { ?>
<div class="adm-main">
    <header class="adm-topbar">
        <div class="topbar-left">
            <button class="btn-hamburguer" onclick="openSidebar()">
                <i class="fa-solid fa-bars"></i>
            </button>
            <div>
                <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
                <?php if ($subtitle): ?>
                <div class="topbar-sub"><?= htmlspecialchars($subtitle) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="topbar-right">
            <span class="topbar-clock" id="clock">--:--:--</span>
            <span class="topbar-badge"><span class="live-dot"></span>Online</span>
        </div>
    </header>
    <main class="adm-content">
<?php
}

/* â”€â”€â”€ Fecha o layout â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function adminFooter(): void { ?>
    </main>
</div>

<script>
/* Relógio */
(function tick() {
    const el = document.getElementById('clock');
    if (el) el.textContent = new Date().toLocaleTimeString('pt-BR');
    setTimeout(tick, 1000);
})();

/* Sidebar mobile */
function openSidebar() {
    document.getElementById('adm-sidebar').classList.add('open');
    document.getElementById('sb-overlay').classList.add('on');
}
function closeSidebar() {
    document.getElementById('adm-sidebar').classList.remove('open');
    document.getElementById('sb-overlay').classList.remove('on');
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });
</script>
</body></html>
<?php
}
