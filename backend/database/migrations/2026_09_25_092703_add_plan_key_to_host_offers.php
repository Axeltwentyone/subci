<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Clé de la formule dans config/plans.php (limites de places, appareils possibles).
        Schema::table('host_offers', function (Blueprint $table) {
            $table->string('plan', 24)->nullable()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('host_offers', fn (Blueprint $t) => $t->dropColumn('plan'));
    }
};
