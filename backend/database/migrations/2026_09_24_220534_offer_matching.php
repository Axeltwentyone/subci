<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Le paiement réserve une place dans l'offre choisie par Sub.ci.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('host_offer_id')->nullable()->after('subscription_id')->constrained()->nullOnDelete();
        });

        // Les places libres se calculent désormais à partir des offres en ligne.
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('free_seats');
        });

        Schema::table('host_offers', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('status');
            $table->index(['service_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('host_offers', function (Blueprint $table) {
            $table->dropIndex(['service_id', 'status']);
            $table->dropColumn('approved_at');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('free_seats')->default(0)->after('seats');
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_offer_id');
        });
    }
};
