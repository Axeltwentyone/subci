<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partage : un cercle d'amis qui s'organise autour d'un même abonnement (logique communautaire,
        // différente de « Revends » où un hôte publie une offre). La collecte des parts viendra plus tard.
        Schema::create('circles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            // Code du lien d'invitation (sub.ci/c/CODE) : le connaître = être invité.
            $table->string('code', 12)->unique();
            // Ouvert : les places libres sont proposées aux autres utilisateurs de Sub.ci.
            $table->boolean('is_open')->default(false);
            $table->unsignedTinyInteger('seats');
            // Prix réel de l'abonnement par mois, partagé entre les membres.
            $table->unsignedInteger('price');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['is_open', 'closed_at']);
        });

        Schema::create('circle_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('circle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['circle_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('circles');
    }
};
