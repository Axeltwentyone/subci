<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Une offre = une formule + les appareils prévus (le membre choisit selon son usage).
        Schema::table('host_offers', function (Blueprint $table) {
            $table->json('devices')->nullable()->after('plan_label');
            $table->string('quality', 8)->nullable()->after('devices');
        });

        // Le membre paie, puis l'hôte accepte ou refuse (remboursement sinon).
        Schema::create('join_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('host_offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['host_offer_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('confirmed_at');
        });

        // Fiabilité montrée aux hôtes : nombre de fois retiré d'un cercle.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('removals_count')->default(0)->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('removals_count'));
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('refunded_at'));
        Schema::dropIfExists('join_requests');
        Schema::table('host_offers', fn (Blueprint $t) => $t->dropColumn(['devices', 'quality']));
    }
};
