<?php

namespace App\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class TenantScope implements Scope
{
    /**
     * True while the signed-in user is being resolved, to stop the scope recursing into itself.
     */
    private static bool $resolvingUser = false;

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Loading the signed-in user from the session runs a User query, which would land back here
        // and ask who is signed in. That inner query must stay unscoped, or it recurses forever.
        if (self::$resolvingUser) {
            return;
        }

        self::$resolvingUser = true;

        try {
            $user = Auth::check() ? Auth::user() : null;
        } finally {
            self::$resolvingUser = false;
        }

        if ($user !== null) {
            // Super admins have global platform visibility
            if ($user instanceof User && $user->isSuperAdmin()) {
                return;
            }

            if (! empty($user->sacco_id)) {
                $builder->where($model->qualifyColumn('sacco_id'), $user->sacco_id);
            } else {
                // Non-super-admin without assigned SACCO is denied tenant data
                $builder->whereRaw('1 = 0');
            }
        }
    }
}
