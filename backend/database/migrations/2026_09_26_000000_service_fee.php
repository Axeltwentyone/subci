<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Frais de service Sub.ci payés par le membre, inclus dans « amount » (jamais reversés à l'hôte).
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('service_fee')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('service_fee'));
    }
};
