<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lending_policies', function (Blueprint $table) {
            // Used for loans requested by members themselves; members never choose their own rate
            $table->decimal('default_interest_rate', 5, 2)->default(10.00)->after('reject_on_past_default');
            $table->string('default_interest_calculation_type', 20)->default('flat')->after('default_interest_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lending_policies', function (Blueprint $table) {
            $table->dropColumn(['default_interest_rate', 'default_interest_calculation_type']);
        });
    }
};
