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
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->constrained('saccos')->cascadeOnDelete();
            $table->string('member_number');
            $table->string('full_name');
            $table->string('national_id_hash', 64);
            $table->string('national_id_masked', 20)->nullable();
            $table->string('phone', 50);
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('occupation')->nullable();
            $table->text('address')->nullable();
            $table->decimal('monthly_income', 15, 2)->nullable();
            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone', 50)->nullable();
            $table->string('next_of_kin_relationship', 100)->nullable();
            $table->date('join_date');
            $table->string('status', 20)->default('active'); // active, inactive, suspended
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['sacco_id', 'member_number']);
            $table->unique(['sacco_id', 'national_id_hash']);
            $table->index(['sacco_id', 'status']);
            $table->index(['sacco_id', 'phone']);
            $table->index(['sacco_id', 'full_name']);
            $table->index(['sacco_id', 'join_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
