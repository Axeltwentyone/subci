<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappel à l'hôte quand une demande approche de l'expiration (remboursement auto après 24 h).
        Schema::table('join_requests', function (Blueprint $table) {
            $table->timestamp('host_reminded_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('join_requests', fn (Blueprint $t) => $t->dropColumn('host_reminded_at'));
    }
};
