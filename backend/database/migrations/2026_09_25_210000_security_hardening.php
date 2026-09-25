<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Séquestre : les gains d'un hôte sont versés mois par mois, bloqués quelques heures avant d'être retirables.
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('source_payment_id')->nullable()->after('host_offer_id')->constrained('payments')->nullOnDelete();
            $table->unsignedInteger('gross')->nullable()->after('amount');
            $table->timestamp('available_at')->nullable()->after('expires_at');
            $table->timestamp('held_at')->nullable()->after('available_at');
            $table->index(['type', 'status', 'available_at']);
        });

        // Toutes les références passerelle d'un paiement (relances comprises) : aucun paiement réel ne se perd.
        Schema::create('payment_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('status', 16)->default('pending');
            $table->foreignId('refund_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();
        });

        // « Signaler un souci » : gèle les gains de l'hôte pour ce membre jusqu'à décision.
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('host_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 24);
            $table->text('message')->nullable();
            $table->string('status', 16)->default('open');
            $table->string('resolution', 240)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('payout_changed_at')->nullable()->after('payout_phone');
        });

        Schema::table('admins', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->unsignedBigInteger('two_factor_last_step')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('admins', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('payout_changed_at'));
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('payment_references');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['type', 'status', 'available_at']);
            $table->dropConstrainedForeignId('source_payment_id');
            $table->dropColumn(['gross', 'available_at', 'held_at']);
        });
    }
};
