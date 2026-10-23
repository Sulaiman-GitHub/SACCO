<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — SACCO Credit Intelligence Platform</title>

    {{-- Bootstrap 5 CSS & Icons --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <style>
        :root {
            --color-black: #0F172A;
            --color-green-start: #1FAE5C;
            --color-green-end: #2FD98A;
            --color-teal: #1B7A6E;
            --color-bg: #F5F6F7;
            --color-slate: #4A5058;
            --color-slate-light: #E1E4E8;
            --color-border: #D8DCE0;
            --color-risk-low: #2E9E5B;
            --color-risk-medium: #D98F2B;
            --color-risk-high: #C4432E;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--color-bg);
            color: var(--color-black);
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6, .brand-font {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--color-black);
        }

        /* Layout structure */
        .app-shell {
            display: flex;
            min-height: 100vh;
        }

        .app-sidebar {
            width: 260px;
            background-color: var(--color-black);
            color: #FFFFFF;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            border-right: 1px solid #23272B;
        }

        .sidebar-header {
            padding: 20px 18px;
            border-bottom: 1px solid #1E2328;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-header img {
            width: 36px;
            height: 36px;
            object-fit: contain;
            background: #FFFFFF;
            padding: 3px;
            border-radius: 4px;
        }

        .sidebar-header .brand-title {
            font-size: 15px;
            line-height: 1.2;
            font-weight: 700;
            color: #FFFFFF;
        }

        .sidebar-header .brand-sub {
            font-size: 11px;
            color: var(--color-green-end);
            font-weight: 500;
        }

        .tenant-indicator {
            background-color: #171B1F;
            border-bottom: 1px solid #262B31;
            padding: 12px 18px;
        }

        .tenant-label {
            font-size: 10px;
            color: #9AA1A9;
            text-transform: uppercase;
            font-weight: 600;
            margin-bottom: 2px;
        }

        .tenant-name {
            font-size: 13px;
            font-weight: 600;
            color: #FFFFFF;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .tenant-tag {
            display: inline-block;
            font-size: 10px;
            background: rgba(31, 174, 92, 0.2);
            color: var(--color-green-end);
            padding: 2px 6px;
            border-radius: 3px;
            margin-top: 4px;
        }

        .sidebar-nav {
            padding: 16px 10px;
            flex-grow: 1;
        }

        .nav-section-title {
            font-size: 11px;
            font-weight: 600;
            color: #717882;
            padding: 8px 12px 4px 12px;
            text-transform: uppercase;
        }

        .nav-link-custom {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            font-size: 13px;
            font-weight: 500;
            color: #B2B8BF;
            text-decoration: none;
            border-radius: 4px;
            margin-bottom: 2px;
            transition: all 0.15s ease-in-out;
        }

        .nav-link-custom:hover {
            color: #FFFFFF;
            background-color: #1E293B;
        }

        .nav-link-custom.active {
            color: #FFFFFF;
            background: linear-gradient(90deg, rgba(31, 174, 92, 0.25) 0%, rgba(47, 217, 138, 0.12) 100%);
            border-left: 3px solid var(--color-green-start);
            font-weight: 600;
        }

        .sidebar-footer {
            padding: 16px 18px;
            border-top: 1px solid #1E2328;
            background-color: #121518;
        }

        .app-main {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
        }

        .app-topbar {
            background: #FFFFFF;
            border-bottom: 1px solid var(--color-border);
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .topbar-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
        }

        .app-content {
            padding: 28px;
            flex-grow: 1;
        }

        /* Structural card designs (sharp corners, no fluffy SaaS shadows) */
        .sacco-card {
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 24px;
        }

        .sacco-card-header {
            padding-bottom: 14px;
            margin-bottom: 16px;
            border-bottom: 1px solid var(--color-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .btn-brand-primary {
            background: linear-gradient(135deg, var(--color-green-start) 0%, var(--color-green-end) 100%);
            color: #0F172A;
            font-weight: 600;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 13px;
        }

        .btn-brand-primary:hover {
            opacity: 0.92;
            color: #000000;
        }

        .btn-brand-dark {
            background-color: var(--color-black);
            color: #FFFFFF;
            font-weight: 600;
            border: 1px solid var(--color-black);
            padding: 8px 16px;
            border-radius: 4px;
            font-size: 13px;
        }

        .btn-brand-dark:hover {
            background-color: #23272B;
            color: #FFFFFF;
        }

        .btn-brand-outline {
            background: transparent;
            color: var(--color-slate);
            border: 1px solid var(--color-border);
            font-weight: 500;
            padding: 7px 14px;
            border-radius: 4px;
            font-size: 13px;
        }

        .btn-brand-outline:hover {
            background: #EDF0F2;
            color: var(--color-black);
        }

        /* Stat tiles */
        .stat-tile {
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 16px 20px;
        }

        .stat-title {
            font-size: 12px;
            font-weight: 600;
            color: var(--color-slate);
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--color-black);
            line-height: 1.1;
        }

        .stat-desc {
            font-size: 11px;
            color: var(--color-slate);
            margin-top: 4px;
        }

        /* Table styling */
        .sacco-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .sacco-table th {
            background-color: #F8F9FA;
            color: var(--color-slate);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            padding: 10px 14px;
            border-bottom: 2px solid var(--color-border);
        }

        .sacco-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--color-border);
            vertical-align: middle;
        }

        .sacco-table tbody tr:hover {
            background-color: #FBFBFC;
        }

        /* Pagination */
        .pagination { --bs-pagination-color: var(--color-black); --bs-pagination-hover-color: var(--color-black); --bs-pagination-focus-color: var(--color-black); --bs-pagination-active-bg: var(--color-black); --bs-pagination-active-border-color: var(--color-black); --bs-pagination-border-color: var(--color-border); flex-wrap: wrap; margin-bottom: 0; }
        .pagination .page-item.active .page-link { background-color: var(--color-black); border-color: var(--color-black); color: #FFFFFF; }

        /* Badges */
        .badge-active {
            background-color: rgba(31, 174, 92, 0.15);
            color: var(--color-risk-low);
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 3px;
            border: 1px solid rgba(31, 174, 92, 0.3);
        }

        .badge-inactive {
            background-color: rgba(196, 67, 46, 0.12);
            color: var(--color-risk-high);
            font-size: 11px;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 3px;
            border: 1px solid rgba(196, 67, 46, 0.25);
        }

        .badge-role {
            background-color: #E8ECEF;
            color: var(--color-slate);
            font-size: 11px;
            font-weight: 600;
            padding: 3px 6px;
            border-radius: 3px;
        }

        /* ---------- Responsive behaviour (tablet and phone) ----------
           Desktop layout above is unchanged. Below 992px the sidebar becomes an off-canvas
           menu opened from the topbar, tables scroll inside their own card or stack into
           cards, and controls grow to touch size. Only existing brand tokens are used. */
        .app-main { min-width: 0; }
        .topbar-heading { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .topbar-title { overflow-wrap: anywhere; }
        .topbar-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .table-responsive { -webkit-overflow-scrolling: touch; }

        .nav-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            padding: 0;
            font-size: 22px;
            color: var(--color-black);
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 4px;
        }

        .sidebar-close {
            display: none;
            margin-left: auto;
            width: 44px;
            height: 44px;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: 1px solid #262B31;
            border-radius: 4px;
            color: #FFFFFF;
            font-size: 18px;
        }

        .sidebar-backdrop { display: none; }

        @media (max-width: 991.98px) {
            .app-shell { display: block; }

            .app-sidebar {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                z-index: 1040;
                width: 280px;
                max-width: 85vw;
                overflow-y: auto;
                transform: translateX(-100%);
                transition: transform 0.2s ease-in-out;
            }

            body.nav-open .app-sidebar { transform: none; }
            body.nav-open { overflow: hidden; }

            .sidebar-backdrop {
                display: block;
                position: fixed;
                inset: 0;
                z-index: 1035;
                background: rgba(15, 23, 42, 0.55);
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.2s ease-in-out;
            }

            body.nav-open .sidebar-backdrop { opacity: 1; pointer-events: auto; }

            .nav-toggle, .sidebar-close { display: inline-flex; }
            .nav-link-custom { padding: 12px; font-size: 14px; }

            .app-main { overflow-y: visible; }

            .app-topbar {
                position: sticky;
                top: 0;
                z-index: 1020;
                flex-wrap: wrap;
                gap: 10px 12px;
                padding: 10px 16px;
            }

            .topbar-title { font-size: 16px; }

            /* Touch-friendly controls for tablets and phones. 16px text stops iOS zooming on focus. */
            .form-control, .form-select, .input-group-text { min-height: 44px; font-size: 16px; }
            textarea.form-control { min-height: 88px; }
            .btn { min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
            .btn-sm { min-height: 40px; }
            .form-check-input { width: 1.3rem; height: 1.3rem; margin-top: 0.1rem; }
            .form-check { padding-left: 1.9rem; min-height: 32px; }
            .form-label { margin-bottom: 6px; }

            .app-content { padding: 16px; }
            .stat-tile { height: 100%; }
            .sacco-card { padding: 16px; }
            .sacco-card-header { flex-wrap: wrap; gap: 8px 12px; }
        }

        @media (max-width: 767.98px) {
            /* Primary actions sit on their own full-width row under the title. */
            .topbar-actions { width: 100%; }
            .topbar-actions > .btn,
            .topbar-actions > form,
            .topbar-actions > a { flex: 1 1 auto; }
            .topbar-actions > form .btn { width: 100%; }

            .stat-tile { padding: 14px 16px; }
            .stat-value { font-size: 21px; }

            /* Stacked-card tables: each row becomes a card with label/value lines. */
            .sacco-table.table-stack,
            .table-stack thead,
            .table-stack tbody,
            .table-stack tr,
            .table-stack td { display: block; width: 100%; }
            .table-stack thead { display: none; }
            .table-stack tr {
                margin-bottom: 12px;
                background: #FFFFFF;
                border: 1px solid var(--color-border);
                border-radius: 4px;
            }
            .table-stack tbody tr:hover { background-color: #FFFFFF; }
            /* Dense tables that are not stacked scroll sideways inside their own card, not the page. */
            .table-responsive > .sacco-table:not(.table-stack):has(thead th:nth-child(4)) { min-width: 560px; }

            /* Label in the left column, however many lines the value has in the right column. */
            .table-stack td {
                display: grid;
                grid-template-columns: minmax(84px, 38%) minmax(0, 1fr);
                column-gap: 12px;
                align-items: start;
                justify-items: end;
                min-width: 0 !important;
                padding: 9px 14px;
                text-align: right;
                overflow-wrap: anywhere;
                border-bottom: 1px solid var(--color-border);
            }
            .table-stack td:last-child { border-bottom: 0; }
            .table-stack td::before {
                content: attr(data-label);
                grid-column: 1;
                grid-row: 1 / span 4;
                justify-self: start;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                text-align: left;
                color: var(--color-slate);
            }
            .table-stack td > * { grid-column: 2; }
            .table-stack td > * + * { margin-top: 2px; }
            .table-stack td[data-label=""] { display: flex; justify-content: flex-end; align-items: center; }
            .table-stack td[data-label=""]::before { display: none; }
            .table-stack td[data-label=""] > * + * { margin-top: 0; }
            .table-stack td[colspan] { justify-content: center; text-align: center; }
            .table-stack .btn { min-width: 44px; padding: 0 12px !important; }
        }

        @media (max-width: 575.98px) {
            .app-content { padding: 12px; }

            /* Stat tiles sit two to a row on phones instead of one tall card each. */
            .row:has(> [class*="col-"] > .stat-tile) > [class*="col-"] { width: 50%; }
            .stat-tile { padding: 12px; height: 100%; }
            .stat-title { font-size: 11px; }
            .stat-value { font-size: 19px; overflow-wrap: anywhere; }
            .stat-desc { font-size: 10.5px; }
            .topbar-actions > .btn,
            .topbar-actions > a { flex: 1 1 140px; }
        }
    </style>
    @yield('styles')
</head>
<body>
<div class="app-shell">
    {{-- Sidebar --}}
    <aside class="app-sidebar" id="app-sidebar">
        <div class="sidebar-header">
            <img src="{{ asset('images/logo.jpg') }}" alt="Fat Tech Logo">
            <div>
                <div class="brand-title">SACCO Credit Risk</div>
                <div class="brand-sub">Intelligence Platform</div>
            </div>
            <button type="button" class="sidebar-close" data-nav-close aria-label="Close navigation menu">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        @auth
            <div class="tenant-indicator">
                @if(Auth::user()->isSuperAdmin())
                    <div class="tenant-label">Signed in as</div>
                    <div class="tenant-name">Platform Super Admin</div>
                    <span class="tenant-tag" style="background: rgba(27, 122, 110, 0.25); color: #2FD98A;">Platform operator</span>
                @else
                    <div class="tenant-label">Your SACCO</div>
                    <div class="tenant-name">{{ Auth::user()->sacco->name ?? 'Default SACCO' }}</div>
                    <span class="tenant-tag">{{ Auth::user()->sacco->tier ?? 'Tier 4' }} &bull; {{ ucfirst(str_replace('_', ' ', Auth::user()->role)) }}</span>
                @endif
            </div>

            <nav class="sidebar-nav">
                @if(Auth::user()->isSuperAdmin())
                    <div class="nav-section-title">Platform Operator</div>
                    <a href="{{ route('super_admin.dashboard') }}" class="nav-link-custom {{ request()->routeIs('super_admin.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-grid-1x2"></i> Overview
                    </a>
                    <a href="{{ route('super_admin.saccos.index') }}" class="nav-link-custom {{ request()->routeIs('super_admin.saccos.*') ? 'active' : '' }}">
                        <i class="bi bi-building"></i> Registered SACCOs
                    </a>
                @else
                    <div class="nav-section-title">SACCO Workspace</div>
                    <a href="{{ route('tenant.dashboard') }}" class="nav-link-custom {{ request()->routeIs('tenant.dashboard') ? 'active' : '' }}">
                        <i class="bi bi-speedometer2"></i> SACCO Dashboard
                    </a>
                    <a href="{{ route('tenant.members.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.members.*') ? 'active' : '' }}">
                        <i class="bi bi-person-vcard"></i> Members
                    </a>
                    <a href="{{ route('tenant.savings.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.savings.*') ? 'active' : '' }}">
                        <i class="bi bi-wallet2"></i> Savings Ledger
                    </a>
                    <a href="{{ route('tenant.loans.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.loans.*') ? 'active' : '' }}">
                        <i class="bi bi-cash-stack"></i> Loans
                    </a>
                    <a href="{{ route('tenant.repayments.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.repayments.*') ? 'active' : '' }}">
                        <i class="bi bi-receipt"></i> Repayments Ledger
                    </a>
                    @if(Auth::user()->isSaccoAdmin())
                        <a href="{{ route('tenant.staff.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.staff.*') ? 'active' : '' }}">
                            <i class="bi bi-people"></i> Staff & Users
                        </a>
                        <a href="{{ route('tenant.reports.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.reports.*') ? 'active' : '' }}">
                            <i class="bi bi-file-earmark-bar-graph"></i> Reports
                        </a>
                        <a href="{{ route('tenant.audit_log.index') }}" class="nav-link-custom {{ request()->routeIs('tenant.audit_log.*') ? 'active' : '' }}">
                            <i class="bi bi-journal-check"></i> Audit Log
                        </a>
                        <a href="{{ route('tenant.lending_policy.edit') }}" class="nav-link-custom {{ request()->routeIs('tenant.lending_policy.*') ? 'active' : '' }}">
                            <i class="bi bi-sliders"></i> Lending Policy
                        </a>
                    @endif
                @endif
            </nav>

            <div class="sidebar-footer">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div style="font-size: 12px; font-weight: 600; color: #FFFFFF;">{{ Auth::user()->name }}</div>
                        <div style="font-size: 11px; color: #8F97A0;">{{ Auth::user()->email }}</div>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="m-0">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-outline-light p-1 px-2" title="Sign Out">
                            <i class="bi bi-box-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        @endauth
    </aside>
    <div class="sidebar-backdrop" data-nav-close></div>

    {{-- Main Content Area --}}
    <main class="app-main">
        <header class="app-topbar">
            <div class="topbar-heading">
                @auth
                    <button type="button" class="nav-toggle" data-nav-toggle aria-label="Open navigation menu" aria-controls="app-sidebar" aria-expanded="false">
                        <i class="bi bi-list"></i>
                    </button>
                @endauth
                <h1 class="topbar-title">@yield('page_title', 'Dashboard')</h1>
            </div>
            <div class="topbar-actions">
                @yield('topbar_actions')
            </div>
        </header>

        <div class="app-content">
            @if(session('success'))
                <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="border-radius: 4px; border: 1px solid #1FAE5C; background: #EAF8F0; color: #0E592E;">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <div>{{ session('success') }}</div>
                </div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger d-flex align-items-center mb-4" role="alert" style="border-radius: 4px; border: 1px solid #C4432E; background: #FDF1F0; color: #8C2212;">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div>{{ session('error') }}</div>
                </div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger mb-4" role="alert" style="border-radius: 4px; border: 1px solid #C4432E; background: #FDF1F0; color: #8C2212;">
                    <div class="fw-bold mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i> Please resolve the following errors:</div>
                    <ul class="mb-0 ps-3">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Off-canvas navigation for tablet and phone widths.
    (function () {
        var toggles = document.querySelectorAll('[data-nav-toggle]');
        var sidebar = document.getElementById('app-sidebar');

        function setOpen(open) {
            document.body.classList.toggle('nav-open', open);
            toggles.forEach(function (t) { t.setAttribute('aria-expanded', open ? 'true' : 'false'); });
        }

        toggles.forEach(function (t) { t.addEventListener('click', function () { setOpen(true); }); });
        document.querySelectorAll('[data-nav-close]').forEach(function (c) { c.addEventListener('click', function () { setOpen(false); }); });
        if (sidebar) {
            sidebar.querySelectorAll('a').forEach(function (a) { a.addEventListener('click', function () { setOpen(false); }); });
        }
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });
        window.addEventListener('resize', function () { if (window.innerWidth >= 992) { setOpen(false); } });
    })();
</script>
@yield('scripts')
</body>
</html>
