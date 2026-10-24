{{-- Installable-app tags and the small styles for the offline banner, toasts and install prompt. Shared by every layout. --}}
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<meta name="theme-color" content="#0F172A">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="SACCO">
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}">
<style>
    .offline-banner {
        position: sticky; top: 0; z-index: 3000; padding: 8px 16px; text-align: center;
        background: #C4432E; color: #FFFFFF; font-size: 13px; font-weight: 600;
    }
    .sacco-toasts {
        position: fixed; z-index: 3100; left: 16px; right: 16px; bottom: calc(76px + env(safe-area-inset-bottom));
        display: flex; flex-direction: column; gap: 8px; align-items: flex-start; pointer-events: none;
    }
    .sacco-toast {
        pointer-events: auto; display: flex; align-items: center; gap: 12px; max-width: 440px;
        padding: 10px 12px 10px 16px; border-radius: 4px; background: #0F172A; color: #FFFFFF;
        border: 1px solid #23272B; font-size: 13px; box-shadow: 0 4px 16px rgba(15, 23, 42, 0.25);
    }
    .sacco-toast-action {
        border: 0; border-radius: 4px; padding: 6px 12px; font-size: 13px; font-weight: 700; cursor: pointer;
        background: linear-gradient(135deg, #1FAE5C 0%, #2FD98A 100%); color: #0F172A;
    }
    .sacco-toast-close { border: 0; background: transparent; color: #B2B8BF; font-size: 20px; line-height: 1; padding: 0 6px; cursor: pointer; }
    .install-wrap { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #B2B8BF; }
    .install-wrap[hidden], .install-wrap [hidden] { display: none !important; }
    .install-wrap .install-button {
        border: 1px solid #4A5058; background: transparent; color: #FFFFFF; border-radius: 4px;
        padding: 6px 12px; font-size: 12px; font-weight: 600; cursor: pointer; min-height: 32px;
    }
    .install-wrap .install-button:hover { background: #1E293B; }
    .install-wrap .install-close { border: 0; background: transparent; color: #8F97A0; font-size: 18px; line-height: 1; padding: 0 6px; min-width: 32px; min-height: 32px; cursor: pointer; }
    @media (min-width: 768px) { .sacco-toasts { bottom: 16px; } }
</style>
