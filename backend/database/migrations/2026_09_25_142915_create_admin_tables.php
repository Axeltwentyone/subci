<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comptes d'administration : séparés des membres (pas de connexion SMS).
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Journal : qui a fait quoi, sur quoi, quand.
        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained()->cascadeOnDelete();
            $table->string('action', 48);
            $table->string('subject_type', 32)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        // Suspension d'un compte membre (connexion et API bloquées).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('removals_count');
            $table->string('suspension_reason')->nullable()->after('suspended_at');
        });

        // Refus d'une offre en modération, avec le motif montré à l'hôte.
        Schema::table('host_offers', function (Blueprint $table) {
            $table->string('rejection_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('host_offers', fn (Blueprint $t) => $t->dropColumn('rejection_reason'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['suspended_at', 'suspension_reason']));
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('admins');
    }
};
