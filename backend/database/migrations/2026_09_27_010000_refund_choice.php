<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Remboursement d'une demande refusée : le membre choisit crédit Sub.ci (immédiat) ou argent (sous 48 h). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // null = ancien remboursement (argent) ; pending = en attente du choix ; credit ; cash.
            $table->string('refund_choice', 8)->nullable()->after('credit_restored_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('refund_choice'));
    }
};
