<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adresse de la PWA d'où vient le membre (dev, preview, prod) : retour après paiement.
        Schema::table('payments', function (Blueprint $table) {
            $table->string('return_url')->nullable()->after('checkout_url');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('return_url'));
    }
};
