<?php

use App\Http\Middleware\EnsureMemberPortalAccess;
use App\Http\Middleware\EnsureSaccoAdmin;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureTenantUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'super_admin' => EnsureSuperAdmin::class,
            'tenant_user' => EnsureTenantUser::class,
            'sacco_admin' => EnsureSaccoAdmin::class,
            'member_portal' => EnsureMemberPortalAccess::class,
        ]);

        // A signed-in member who opens a guest-only page (the portal login) goes to the portal.
        $middleware->redirectUsersTo(fn () => Auth::guard('member')->check() ? route('portal.dashboard') : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A form left open past the session lifetime (or a long time offline) posts a stale CSRF token. Nothing was
        // saved, so send the person back to the form with what they typed (never passwords) and say so plainly.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }

            return back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password', 'new_password', 'new_password_confirmation']))
                ->withErrors(['session_expired' => 'Your session timed out, so nothing was saved. Check the details below and submit again.']);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
