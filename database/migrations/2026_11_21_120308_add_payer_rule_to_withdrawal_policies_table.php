<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawal_policies', function (Blueprint $table) {
            $table->string('payer_must_differ_from_approver', 20)->default('above_threshold'); // never | above_threshold | always
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_policies', function (Blueprint $table) {
            $table->dropColumn('payer_must_differ_from_approver');
        });
    }
};
