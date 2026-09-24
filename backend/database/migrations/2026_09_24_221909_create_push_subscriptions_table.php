<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un appareil / navigateur abonné aux notifications push (Web Push, VAPID).
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('content_encoding', 16)->default('aes128gcm');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        // Rappels d'échéance déjà envoyés pour la date de fin en cours (remis à zéro au renouvellement).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('reminded_j3_at')->nullable()->after('auto_renew');
            $table->timestamp('reminded_j1_at')->nullable()->after('reminded_j3_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['reminded_j3_at', 'reminded_j1_at']);
        });
        Schema::dropIfExists('push_subscriptions');
    }
};
