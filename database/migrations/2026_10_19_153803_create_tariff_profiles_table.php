<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tariff_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('reference_key', 100)->nullable()->unique();
            $table->string('channel', 30);
            $table->string('category');
            $table->string('listing_type', 100);
            $table->decimal('commission_percent', 7, 4)->nullable();
            $table->decimal('fixed_fee', 14, 4)->nullable();
            $table->text('conditions')->nullable();
            $table->boolean('conditions_verified')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->date('review_on')->nullable();
            $table->date('observed_on')->nullable();
            $table->text('source');
            $table->text('notes')->nullable();
            $table->string('confidence', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE tariff_profiles ADD CONSTRAINT tariff_profiles_valid CHECK ((commission_percent IS NULL OR commission_percent BETWEEN 0 AND 100) AND (fixed_fee IS NULL OR fixed_fee >= 0) AND channel IN ('mercado_livre', 'shopee') AND confidence IN ('confirmed', 'estimated') AND (valid_from IS NULL OR valid_until IS NULL OR valid_until >= valid_from))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariff_profiles');
    }
};
