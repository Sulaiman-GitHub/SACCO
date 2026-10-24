{{-- Offline safety for forms, plus install and update prompts. The ?v= stamp makes a changed file load fresh past the worker's cache. --}}
@php
    $pwaVersion = static fn (string $file): int => (int) @filemtime(public_path($file));
@endphp
<script src="{{ asset('js/offline-guard.js') }}?v={{ $pwaVersion('js/offline-guard.js') }}" defer></script>
<script src="{{ asset('js/pwa.js') }}?v={{ $pwaVersion('js/pwa.js') }}" defer></script>
