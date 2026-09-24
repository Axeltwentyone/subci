<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            $table->string('status', 16)->default('pending');
            $table->string('reference', 20)->unique();
            $table->string('label');
            $table->unsignedInteger('amount');
            $table->unsignedTinyInteger('months')->nullable();
            $table->string('method', 8);
            $table->string('phone', 10)->nullable();
            // Référence côté opérateur (Orange Money, Wave…) une fois la demande envoyée.
            $table->string('provider_reference')->nullable();
            // Période couverte (renouvellement : de l'ancienne échéance à la nouvelle).
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
