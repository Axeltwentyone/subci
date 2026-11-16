<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cercles payants : chaque membre paie sa part chaque mois, l'argent revient au créateur. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('circle_id')->nullable()->after('host_offer_id')->constrained()->nullOnDelete();
        });
        Schema::table('circle_members', function (Blueprint $table) {
            // Part payée jusqu'à cette date (null : créateur, ou membre d'avant les cercles payants).
            $table->timestamp('paid_until')->nullable();
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('circle_members', fn (Blueprint $t) => $t->dropColumn(['paid_until', 'reminded_at']));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('circle_id');
        });
    }
};
