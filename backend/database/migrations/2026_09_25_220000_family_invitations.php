<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Apple Music : e-mail de l'identifiant Apple du membre, donné au paiement (chiffré).
        Schema::table('payments', function (Blueprint $table) {
            $table->text('invite_email')->nullable()->after('phone');
        });

        // Offres famille : invitation envoyée par l'hôte à chaque membre (lien Spotify / YouTube, ou e-mail Apple).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->text('invite_email')->nullable()->after('access_pin');
            $table->text('invite_link')->nullable()->after('invite_email');
            $table->timestamp('invite_sent_at')->nullable()->after('invite_link');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['invite_email', 'invite_link', 'invite_sent_at']));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('invite_email'));
    }
};
