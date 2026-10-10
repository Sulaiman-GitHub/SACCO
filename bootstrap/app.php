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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
