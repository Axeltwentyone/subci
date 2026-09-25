<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suivi de l'invitation famille : le membre a rejoint, ou signale un lien qui ne marche plus ; rappel avant expiration (7 j chez Spotify).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('invite_joined_at')->nullable()->after('invite_sent_at');
            $table->timestamp('invite_problem_at')->nullable()->after('invite_joined_at');
            $table->timestamp('invite_reminded_at')->nullable()->after('invite_problem_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn(['invite_joined_at', 'invite_problem_at', 'invite_reminded_at']));
    }
};
