<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trésorerie Subci : frais réels de la passerelle, relevé Jèko, retraits de bénéfices,
 * charges et ajustements (jamais modifiés ni supprimés), soldes relevés pour le rapprochement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Frais réellement facturés par la passerelle sur ce mouvement (FCFA). Null = inconnu (estimé).
            $table->unsignedInteger('provider_fee')->nullable()->after('service_fee');
            // Transaction Jèko correspondante (encaissement ou envoi).
            $table->string('provider_transaction_id', 64)->nullable()->after('provider_reference');
            $table->index('provider_transaction_id');
        });

        // Retraits de bénéfices Subci (mouvement de trésorerie, pas une charge : seuls les frais en sont une).
        Schema::create('treasury_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
            // Débité du compte marchand (frais compris).
            $table->unsignedInteger('amount');
            $table->unsignedInteger('fee')->default(0);
            // Reçu sur le compte de destination.
            $table->unsignedInteger('net');
            $table->string('destination', 120);
            $table->string('reference', 120)->nullable();
            $table->string('status', 12)->default('pending'); // pending | confirmed | failed
            $table->timestamp('withdrawn_at');
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('jeko_transaction_id', 64)->nullable()->unique();
            $table->timestamps();
            $table->index(['status', 'withdrawn_at']);
        });

        // Charges opérationnelles et ajustements manuels : on ne corrige que par une écriture inverse.
        Schema::create('treasury_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 12); // charge | adjustment
            // Charge : montant positif. Ajustement : signé (+ entrée d'argent, − sortie).
            $table->integer('amount');
            $table->string('label', 160);
            // La charge a été payée depuis le compte marchand (sinon : hors compte, bénéfice seulement).
            $table->boolean('from_merchant')->default(true);
            $table->foreignId('reverses_id')->nullable()->constrained('treasury_entries')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['kind', 'occurred_at']);
        });

        // Soldes du compte marchand : lus via l'API Jèko ou saisis à la main (jamais inventés).
        Schema::create('merchant_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('source', 12); // jeko | manual
            $table->string('note', 255)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index('recorded_at');
        });

        // Relevé Jèko (encaissements et envois tels que Jèko les a exécutés).
        Schema::create('jeko_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('jeko_id', 64)->unique();
            $table->string('type', 16); // payment | transfer | …
            $table->string('status', 16);
            $table->string('method', 16)->nullable();
            // FCFA. Encaissement : crédité net (frais déjà retirés). Envoi : envoyé (frais en plus).
            $table->bigInteger('amount');
            $table->unsignedInteger('fee')->default(0);
            $table->string('counterpart', 64)->nullable();
            $table->string('jeko_reference', 64)->nullable();
            $table->timestamp('executed_at');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('treasury_withdrawal_id')->nullable()->constrained()->nullOnDelete();
            // Hors Subci (test, espèces…) : exclu du rapprochement, avec la raison.
            $table->string('ignored_reason', 160)->nullable();
            $table->timestamps();
            $table->index(['type', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jeko_transactions');
        Schema::dropIfExists('merchant_balances');
        Schema::dropIfExists('treasury_entries');
        Schema::dropIfExists('treasury_withdrawals');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['provider_transaction_id']);
            $table->dropColumn(['provider_fee', 'provider_transaction_id']);
        });
    }
};
