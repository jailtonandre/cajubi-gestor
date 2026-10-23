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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('marketplace', 20);
            $table->string('order_reference', 100);
            $table->unique(['marketplace', 'order_reference']);
            $table->date('sold_on')->index();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->json('product_snapshot');
            $table->json('cost_snapshot')->nullable();
            $table->timestamp('cost_captured_at')->nullable();
            $table->text('cost_note')->nullable();
            foreach (['gross_amount', 'seller_discount', 'marketplace_fees', 'seller_freight', 'other_adjustment', 'expedition_cost', 'paid_labor'] as $column) {
                $table->decimal($column, 14, 2)->nullable();
            }
            $table->date('expected_on')->nullable();
            $table->string('freight_source', 20)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('reconciliation_closed')->default(false);
            $table->text('reconciliation_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_valid CHECK (quantity > 0 AND marketplace IN ('mercado_livre', 'shopee') AND (freight_source IS NULL OR freight_source IN ('marketplace', 'external')) AND (gross_amount IS NULL OR gross_amount >= 0) AND (seller_discount IS NULL OR seller_discount >= 0) AND (marketplace_fees IS NULL OR marketplace_fees >= 0) AND (seller_freight IS NULL OR seller_freight >= 0) AND (expedition_cost IS NULL OR expedition_cost >= 0) AND (paid_labor IS NULL OR paid_labor >= 0) AND (seller_discount IS NULL OR gross_amount IS NULL OR seller_discount <= gross_amount))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
