<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('activates_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->string('pay_method', 8);
            // Coffre des accès : chiffré au repos (cast "encrypted" côté modèle).
            $table->string('profile_label')->nullable();
            $table->text('access_email')->nullable();
            $table->text('access_password')->nullable();
            $table->text('access_pin')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
