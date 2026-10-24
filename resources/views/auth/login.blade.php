<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — SACCO Credit Risk & Business Management Platform</title>

    <link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    @include('partials.pwa-head')

    <style>
        :root {
            --color-black: #0F172A;
            --color-green-start: #1FAE5C;
            --color-green-end: #2FD98A;
            --color-teal: #1B7A6E;
            --color-bg: #F5F6F7;
            --color-slate: #4A5058;
            --color-border: #D8DCE0;
        }

        body {
            background-color: var(--color-bg);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--color-black);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        /* The page body is a centring flex row, so the offline banner is pinned instead of joining the row. */
        .offline-banner { position: fixed; top: 0; left: 0; right: 0; }

        .login-container {
            width: 100%;
            max-width: 440px;
        }

        .brand-header {
            text-align: center;
            margin-bottom: 28px;
        }

        .brand-header img {
            width: 58px;
            height: 58px;
            object-fit: contain;
            background: #FFFFFF;
            padding: 6px;
            border-radius: 6px;
            border: 1px solid var(--color-border);
            margin-bottom: 14px;
        }

        .brand-header h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--color-black);
            margin-bottom: 4px;
            letter-spacing: -0.02em;
        }

        .brand-header p {
            font-size: 13px;
            color: var(--color-slate);
            margin: 0;
        }

        .login-card {
            background: #FFFFFF;
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 32px;
        }

        .form-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--color-slate);
            text-transform: uppercase;
            letter-spacing: 0.02em;
            margin-bottom: 6px;
        }

        .form-control {
            border: 1px solid var(--color-border);
            border-radius: 4px;
            padding: 10px 14px;
            font-size: 14px;
        }

        .form-control:focus {
            border-color: var(--color-teal);
            box-shadow: 0 0 0 2px rgba(27, 122, 110, 0.2);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--color-green-start) 0%, var(--color-green-end) 100%);
            color: #0F172A;
            font-weight: 700;
            border: none;
            width: 100%;
            padding: 11px;
            border-radius: 4px;
            font-size: 14px;
            margin-top: 10px;
        }

        .btn-submit:hover {
            opacity: 0.93;
            color: #000000;
        }

        .login-footer {
            margin-top: 24px;
            text-align: center;
            font-size: 12px;
            color: #717882;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="brand-header">
        <img src="{{ asset('images/logo.jpg') }}" alt="Fat Tech Computer World Logo">
        <h1>SACCO Credit Intelligence</h1>
        <p>Savings, loans and credit risk for SACCOs</p>
    </div>

    <div class="login-card">
        @if(session('error'))
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size: 13px; border-radius: 4px;">
                {{ session('error') }}
            </div>
        @endif

        @if($errors->any())
            <div class="alert alert-danger py-2 px-3 mb-3" style="font-size: 13px; border-radius: 4px;">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror" id="email" name="email" value="{{ old('email') }}" required autofocus placeholder="name@sacco.org">
            </div>

            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center">
                    <label for="password" class="form-label">Password</label>
                </div>
                <input type="password" class="form-control @error('password') is-invalid @enderror" id="password" name="password" required placeholder="Enter your password">
            </div>

            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" name="remember" id="remember" {{ old('remember') ? 'checked' : '' }}>
                <label class="form-check-label" for="remember" style="font-size: 13px; color: var(--color-slate);">
                    Remember my session
                </label>
            </div>

            <button type="submit" class="btn btn-submit" data-busy-label="Signing in...">
                Sign In
            </button>
        </form>
    </div>

    <div class="login-footer">
        Powered by Fat Tech Computer World
    </div>
</div>

@include('partials.pwa-scripts')

</body>
</html>
