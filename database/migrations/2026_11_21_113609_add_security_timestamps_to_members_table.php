<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Never mass assignable; used by the withdrawal security hold. Null = never changed since tracking began.
            $table->timestamp('portal_password_changed_at')->nullable();
            $table->timestamp('phone_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['portal_password_changed_at', 'phone_changed_at']);
        });
    }
};
