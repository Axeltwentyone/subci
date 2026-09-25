<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un appareil abonné appartient soit à un membre, soit à un admin.
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->foreignId('admin_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // Alertes choisies par l'admin (paiements, offres, versements, inscriptions).
        Schema::table('admins', function (Blueprint $table) {
            $table->json('alerts')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('admins', fn (Blueprint $table) => $table->dropColumn('alerts'));
        Schema::table('push_subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_id');
        });
    }
};
