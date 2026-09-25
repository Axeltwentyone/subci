<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un code de connexion ne sert pas à changer le numéro de retrait, et inversement.
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('purpose', 16)->default('login')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', fn (Blueprint $table) => $table->dropColumn('purpose'));
    }
};
