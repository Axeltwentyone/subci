<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // Identifiant du code chez DUGU Sender, qui génère et vérifie lui-même ses codes.
            $table->string('provider_id')->nullable()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('provider_id');
        });
    }
};
