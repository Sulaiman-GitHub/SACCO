<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Existing members have portal access switched off and no credentials. Staff enable it per member.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('portal_username', 30)->nullable()->unique()->after('next_of_kin_informed'); // globally unique login name issued by staff
            $table->string('password')->nullable()->after('portal_username');
            $table->rememberToken()->after('password');
            $table->boolean('portal_enabled')->default(false)->after('remember_token');
            $table->boolean('portal_must_change_password')->default(false)->after('portal_enabled');
            $table->timestamp('portal_last_login_at')->nullable()->after('portal_must_change_password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['portal_username']);
            $table->dropColumn([
                'portal_username',
                'password',
                'remember_token',
                'portal_enabled',
                'portal_must_change_password',
                'portal_last_login_at',
            ]);
        });
    }
};
