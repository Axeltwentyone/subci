<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Page de paiement de la passerelle (GeniusPay) où le membre valide.
        Schema::table('payments', function (Blueprint $table) {
            $table->text('checkout_url')->nullable()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('checkout_url'));
    }
};
