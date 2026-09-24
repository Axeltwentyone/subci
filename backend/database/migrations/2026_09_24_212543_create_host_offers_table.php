<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('host_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->string('plan_label');
            $table->unsignedTinyInteger('seats');
            $table->unsignedInteger('price');
            $table->string('access_mode', 16);
            $table->text('access_email')->nullable();
            $table->text('access_password')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('status', 16)->default('review');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('host_offers');
    }
};
