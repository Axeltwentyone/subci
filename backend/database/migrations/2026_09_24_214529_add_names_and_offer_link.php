<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prénom + nom demandés juste après la vérification du numéro.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 40)->nullable()->after('name');
            $table->string('last_name', 40)->nullable()->after('first_name');
        });

        // Un abonnement membre peut être rattaché à l'offre d'un hôte :
        // quand l'hôte change ses identifiants, le coffre des membres suit.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('host_offer_id')->nullable()->after('service_id')->constrained()->nullOnDelete();
        });

        DB::table('users')->whereNotNull('name')->orderBy('id')->each(function ($u) {
            [$first, $last] = array_pad(explode(' ', trim($u->name), 2), 2, null);
            DB::table('users')->where('id', $u->id)->update(['first_name' => $first, 'last_name' => $last]);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('host_offer_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
