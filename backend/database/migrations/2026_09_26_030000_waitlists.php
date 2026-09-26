<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liste d'attente : prévenu dès qu'une place se libère sur le service (une fois, puis on se réinscrit).
        Schema::create('waitlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'service_id']);
            $table->index(['service_id', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlists');
    }
};
