<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Crédit parrainage : part qui n'a pas pu être reprise (déjà dépensée ailleurs), jamais rendue une seconde fois. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('credit_shortfall')->default(0)->after('credit_used');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('credit_shortfall'));
    }
};
