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
        Schema::table('tariff_profiles', function (Blueprint $table) {
            $table->string('pricing_model')->default('single');
            $table->string('charge_basis')->default('item');
            $table->json('tiers')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tariff_profiles', function (Blueprint $table) {
            $table->dropColumn(['pricing_model', 'charge_basis', 'tiers']);
        });
    }
};
