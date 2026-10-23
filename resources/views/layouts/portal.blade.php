<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'My Account') — Member Portal</title>

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
            --color-border: #D8DCE0;
            --color-risk-low: #2E9E5B;
            --color-risk-medium: #D98F2B;
            --color-risk-high: #C4432E;
        }

        html { -webkit-text-size-adjust: 100%; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--color-bg);
            color: var(--color-black);
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6 { font-weight: 700; letter-spacing: -0.02em; }

        /* Mobile-first: the base styles below are the phone layout. Wider screens add to them. */
        .portal-topbar { background: var(--color-black); color: #FFFFFF; }
        .portal-topbar .brand { font-weight: 700; font-size: 15px; color: #FFFFFF; text-decoration: none; min-width: 0; }
        .portal-topbar .brand small { display: block; font-size: 11px; font-weight: 500; color: var(--color-green-end); }
        .portal-topbar .brand span { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .portal-nav { display: none; }
        .portal-nav a {
            color: #B2B8BF; text-decoration: none; font-size: 13px; font-weight: 500;
            padding: 6px 12px; border-radius: 4px; display: inline-block;
        }
        .portal-nav a:hover { color: #FFFFFF; background: #1E293B; }
        .portal-nav a.active { color: #FFFFFF; border-bottom: 2px solid var(--color-green-start); border-radius: 4px 4px 0 0; }
        .portal-user { font-size: 12px; color: #8F97A0; max-width: 40vw; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .portal-signout { width: 44px; height: 44px; padding: 0; display: inline-flex; align-items: center; justify-content: center; }

        /* Bottom tab bar: the main navigation on phones, within thumb reach. */
        .portal-bottomnav {
            position: fixed; left: 0; right: 0; bottom: 0; z-index: 1030;
            display: flex; background: #FFFFFF; border-top: 1px solid var(--color-border);
            padding-bottom: env(safe-area-inset-bottom);
        }
        .portal-bottomnav a {
            flex: 1 1 0; min-width: 0; min-height: 60px;
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
            font-size: 11px; font-weight: 600; color: var(--color-slate); text-decoration: none;
            border-top: 3px solid transparent;
        }
        .portal-bottomnav a i { font-size: 20px; line-height: 1; }
        .portal-bottomnav a.active { color: var(--color-teal); border-top-color: var(--color-green-start); }

        .portal-main { padding-top: 16px; padding-bottom: 88px; }
        .portal-title { font-size: 20px; margin-bottom: 16px; overflow-wrap: anywhere; }

        .sacco-card { background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 4px; padding: 16px; margin-bottom: 16px; }
        .sacco-card-header {
            padding-bottom: 12px; margin-bottom: 12px; border-bottom: 1px solid var(--color-border);
            display: flex; flex-wrap: wrap; gap: 8px 12px; justify-content: space-between; align-items: center;
        }
        .stat-title { font-size: 12px; font-weight: 600; color: var(--color-slate); text-transform: uppercase; margin-bottom: 6px; }
        .stat-value { font-size: 22px; font-weight: 700; line-height: 1.1; overflow-wrap: anywhere; }

        /* Touch sizes everywhere in the portal; 16px text stops iOS zooming on focus. */
        .form-control, .form-select { min-height: 48px; font-size: 16px; }
        textarea.form-control { min-height: 96px; }
        .btn { min-height: 48px; display: inline-flex; align-items: center; justify-content: center; }
        .btn-sm { min-height: 44px; }
        .btn-brand-primary {
            background: linear-gradient(135deg, var(--color-green-start) 0%, var(--color-green-end) 100%);
            color: #0F172A; font-weight: 600; border: none; padding: 8px 16px; border-radius: 4px; font-size: 14px;
        }
        .btn-brand-primary:hover { opacity: 0.92; color: #000000; }
        .btn-brand-outline {
            background: transparent; color: var(--color-slate); border: 1px solid var(--color-border);
            font-weight: 500; padding: 7px 14px; border-radius: 4px; font-size: 14px;
        }

        .btn-block-mobile { width: 100%; }

        /* Whole-row tap targets for radio choices (consent requests). */
        .choice {
            display: flex; align-items: center; gap: 12px; min-height: 52px; margin-bottom: 8px; padding: 10px 12px;
            border: 1px solid var(--color-border); border-radius: 4px; cursor: pointer; font-size: 14px;
        }
        .choice input { width: 1.3rem; height: 1.3rem; flex-shrink: 0; margin: 0; }
        .choice:has(input:checked) { border-color: var(--color-teal); background: #F5F6F7; }

        /* Transaction lists replace tables on phones: type and date on the left, amount on the right. */
        .txn-list { list-style: none; margin: 0; padding: 0; }
        .txn-list li {
            display: flex; justify-content: space-between; align-items: center; gap: 12px;
            padding: 12px 0; border-bottom: 1px solid var(--color-border); font-size: 14px;
        }
        .txn-list li:last-child { border-bottom: 0; }
        .txn-list .txn-main { min-width: 0; }
        .txn-list .txn-meta { font-size: 12px; color: var(--color-slate); overflow-wrap: anywhere; }
        .txn-list .txn-amount { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-weight: 700; white-space: nowrap; }

        .sacco-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .sacco-table th {
            background-color: #F8F9FA; color: var(--color-slate); font-weight: 600; text-transform: uppercase;
            font-size: 11px; padding: 10px 14px; border-bottom: 2px solid var(--color-border);
        }
        .sacco-table td { padding: 12px 14px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }

        .badge-active {
            background-color: rgba(31, 174, 92, 0.15); color: var(--color-risk-low); font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 3px; border: 1px solid rgba(31, 174, 92, 0.3);
        }
        .badge-inactive {
            background-color: rgba(196, 67, 46, 0.12); color: var(--color-risk-high); font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 3px; border: 1px solid rgba(196, 67, 46, 0.25);
        }
        .badge-neutral {
            background-color: #E8ECEF; color: var(--color-slate); font-size: 11px; font-weight: 600;
            padding: 4px 8px; border-radius: 3px;
        }

        @media (min-width: 768px) {
            .portal-nav { display: flex; }
            .portal-bottomnav { display: none; }
            .portal-main { padding-top: 24px; padding-bottom: 32px; }
            .portal-title { font-size: 22px; margin-bottom: 20px; }
            .sacco-card { padding: 20px; margin-bottom: 24px; }
            .stat-value { font-size: 24px; }
            .form-control, .form-select { min-height: 40px; font-size: 14px; }
            .btn { min-height: 40px; }
            .btn-sm { min-height: 32px; }
            .btn-block-mobile { width: auto; }
        }
        .pagination { --bs-pagination-color: var(--color-black); --bs-pagination-hover-color: var(--color-black); --bs-pagination-focus-color: var(--color-black); --bs-pagination-active-bg: var(--color-black); --bs-pagination-active-border-color: var(--color-black); --bs-pagination-border-color: var(--color-border); flex-wrap: wrap; margin-bottom: 0; }
        .pagination .page-item.active .page-link { background-color: var(--color-black); border-color: var(--color-black); color: #FFFFFF; }
    </style>
</head>
<body>
@php($portalMember = Auth::guard('member')->user())
@php($showPortalNav = $portalMember && ! $portalMember->portal_must_change_password)

<header class="portal-topbar">
    <div class="container py-2 d-flex align-items-center justify-content-between gap-3">
        <a href="{{ $portalMember ? route('portal.dashboard') : route('portal.login') }}" class="brand">
            <span>{{ $portalMember?->sacco?->name ?? 'SACCO' }}</span>
            <small>Member Portal</small>
        </a>

        @if($showPortalNav)
            <nav class="portal-nav gap-1" aria-label="Portal sections">
                <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'active' : '' }}">Overview</a>
                <a href="{{ route('portal.savings') }}" class="{{ request()->routeIs('portal.savings') ? 'active' : '' }}">Savings</a>
                <a href="{{ route('portal.loans') }}" class="{{ request()->routeIs('portal.loans') ? 'active' : '' }}">Loans</a>
                <a href="{{ route('portal.consent') }}" class="{{ request()->routeIs('portal.consent') ? 'active' : '' }}">My consent</a>
                <a href="{{ route('portal.password.edit') }}" class="{{ request()->routeIs('portal.password.*') ? 'active' : '' }}">Password</a>
            </nav>
        @endif

        @if($portalMember)
            <form method="POST" action="{{ route('portal.logout') }}" class="m-0 d-flex align-items-center gap-2">
                @csrf
                <span class="portal-user d-none d-sm-inline">{{ $portalMember->full_name }}</span>
                <button type="submit" class="btn btn-outline-light portal-signout" title="Sign out" aria-label="Sign out">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        @endif
    </div>
</header>

<main class="container portal-main">
    <h1 class="portal-title">@yield('page_title', 'My Account')</h1>

    @if(session('success'))
        <div class="alert alert-success d-flex align-items-center mb-4" role="alert" style="border-radius: 4px; border: 1px solid #1FAE5C; background: #EAF8F0; color: #0E592E;">
            <i class="bi bi-check-circle-fill me-2"></i>
            <div>{{ session('success') }}</div>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger mb-4" role="alert" style="border-radius: 4px; border: 1px solid #C4432E; background: #FDF1F0; color: #8C2212;">
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</main>

@if($showPortalNav)
    <nav class="portal-bottomnav" aria-label="Portal sections">
        <a href="{{ route('portal.dashboard') }}" class="{{ request()->routeIs('portal.dashboard') ? 'active' : '' }}"><i class="bi bi-house-door"></i>Home</a>
        <a href="{{ route('portal.savings') }}" class="{{ request()->routeIs('portal.savings') ? 'active' : '' }}"><i class="bi bi-wallet2"></i>Savings</a>
        <a href="{{ route('portal.loans') }}" class="{{ request()->routeIs('portal.loans') ? 'active' : '' }}"><i class="bi bi-cash-stack"></i>Loans</a>
        <a href="{{ route('portal.consent') }}" class="{{ request()->routeIs('portal.consent') ? 'active' : '' }}"><i class="bi bi-shield-check"></i>Consent</a>
        <a href="{{ route('portal.password.edit') }}" class="{{ request()->routeIs('portal.password.*') ? 'active' : '' }}"><i class="bi bi-key"></i>Password</a>
    </nav>
@endif
</body>
</html>
