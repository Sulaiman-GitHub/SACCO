<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMemberPortalAccess
{
    /**
     * Handle an incoming request. Only a signed-in member who may still use the portal gets through,
     * and a member on a temporary password can reach nothing but the password change.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('member');
        $member = $guard->user();

        if (! $member) {
            return redirect()->route('portal.login');
        }

        if (! $member->canUsePortal()) {
            $guard->logout();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')->withErrors([
                'portal_username' => 'Portal access is not available for this account. Please contact your SACCO.',
            ]);
        }

        if ($member->portal_must_change_password && ! $request->routeIs('portal.password.*', 'portal.logout')) {
            return redirect()->route('portal.password.edit');
        }

        return $next($request);
    }
}
