<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Envoi automatique des versements par Jèko : une seule tentative par versement, erreur gardée pour l'équipe. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('payout_attempted_at')->nullable()->after('confirmed_at');
            $table->string('payout_error', 255)->nullable()->after('payout_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['payout_attempted_at', 'payout_error']));
    }
};
