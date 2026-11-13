<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cercle ouvert : le créateur fixe lui-même le prix d'une place pour les autres utilisateurs.
        Schema::table('circles', function (Blueprint $table) {
            $table->unsignedInteger('seat_price')->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('circles', function (Blueprint $table) {
            $table->dropColumn('seat_price');
        });
    }
};
