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
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('hospital_id')->constrained()->onDelete('cascade');
            $table->string('description');
            $table->foreignId('doctor_id')->constrained('users')->onDelete('cascade');
            $table->string('location');
            $table->integer('total_hourly_tokens')->default(10);
            $table->integer('self_hourly_tokens')->default(5);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clinics');
    }
};
