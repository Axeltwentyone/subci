<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('mono', 3);
            $table->string('color', 7);
            $table->string('fg', 7);
            $table->string('category', 16)->index();
            $table->string('meta');
            $table->text('description');
            // Montants en FCFA (entiers, pas de centimes).
            $table->unsignedInteger('price');
            $table->unsignedInteger('full_price');
            $table->unsignedTinyInteger('seats');
            $table->unsignedTinyInteger('free_seats')->default(0);
            $table->unsignedSmallInteger('activation_minutes')->default(15);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
