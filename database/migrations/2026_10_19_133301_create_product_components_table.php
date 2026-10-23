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
        Schema::create('product_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('component_product_id')->nullable()->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 14, 6);
            $table->unique(['product_id', 'supply_id']);
            $table->unique(['product_id', 'component_product_id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_components ADD CONSTRAINT product_components_valid CHECK (quantity > 0 AND ((supply_id IS NOT NULL AND component_product_id IS NULL) OR (supply_id IS NULL AND component_product_id IS NOT NULL AND component_product_id <> product_id)))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_components');
    }
};
