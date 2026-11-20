<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sacco_payment_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sacco_id')->unique()->constrained('saccos')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('environment', 10)->default('sandbox');
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->text('webhook_secret')->nullable(); // encrypted
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sacco_payment_settings');
    }
};
