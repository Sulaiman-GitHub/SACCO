<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        // Super admins can access tenant views or redirect to super-admin console
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        if (empty($user->sacco_id)) {
            abort(403, 'Your account is not assigned to any registered SACCO.');
        }

        if (! $user->isActive()) {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated. Please contact your SACCO administrator.']);
        }

        $sacco = $user->sacco;
        if (! $sacco || $sacco->status !== 'active') {
            auth()->logout();

            return redirect()->route('login')->withErrors(['email' => 'Your SACCO account is suspended or inactive. Please contact system support.']);
        }

        return $next($request);
    }
}
