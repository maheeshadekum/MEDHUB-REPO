<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('token_type')->nullable()->after('status');
            $table->foreignId('opd_token_id')->nullable()->constrained('opd_tokens')->after('token_type')->onDelete('cascade');
            $table->foreignId('clinic_token_id')->nullable()->constrained('clinic_tokens')->after('opd_token_id')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropForeign(['opd_token_id']);
            $table->dropForeign(['clinic_token_id']);
            $table->dropColumn(['token_type', 'opd_token_id', 'clinic_token_id']);
        });
    }
};
