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
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('drug_name');
            $table->foreignId('hospital_id')->constrained()->onDelete('cascade');
            $table->string('brand_name')->nullable();
            $table->integer('weight')->nullable();
            $table->enum('type', ['tablet', 'capsule', 'syrup', 'injection', 'ointment'])->default('tablet');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
