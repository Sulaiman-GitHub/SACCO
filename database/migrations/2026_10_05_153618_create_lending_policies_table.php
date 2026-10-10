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
        Schema::create('lending_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->unique()->constrained('saccos')->cascadeOnDelete();
            $table->decimal('approve_below_score', 5, 2)->default(50.00); // score below this -> approve
            $table->decimal('reject_at_or_above_score', 5, 2)->default(75.00); // score at/above this -> reject
            $table->decimal('max_loan_to_savings_ratio', 6, 2)->nullable()->default(3.00); // null = no cap
            $table->unsignedInteger('min_tenure_months')->default(3);
            $table->boolean('reject_on_past_default')->default(false); // false = require guarantor instead
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lending_policies');
    }
};
