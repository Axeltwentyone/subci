<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parrainage : le filleul ne paie pas les frais de service tant qu'il n'a pas été accepté par un hôte ;
        // le parrain reçoit alors un crédit Sub.ci, déduit de ses prochains paiements (non retirable).
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referee_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending'); // pending | rewarded | capped | cancelled
            $table->unsignedInteger('reward')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamps();
            $table->index(['referrer_id', 'status', 'rewarded_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('referral_credit')->default(0)->after('balance');
        });

        // Crédit utilisé sur un paiement (pris en charge par Sub.ci, jamais sur la part de l'hôte).
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedInteger('credit_used')->default(0)->after('service_fee');
            $table->timestamp('credit_restored_at')->nullable()->after('credit_used');
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn(['credit_used', 'credit_restored_at']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('referral_credit'));
        Schema::dropIfExists('referrals');
    }
};
