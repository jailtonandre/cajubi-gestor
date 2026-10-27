<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_projections', function (Blueprint $table) {
            $table->boolean('sales_integration_enabled')->default(false);
            $table->timestamp('integration_reviewed_at')->nullable();
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->string('nature', 30)->default('other');
            $table->date('realized_on')->nullable();
            $table->string('integration_review', 20)->default('unreviewed');
            $table->foreignId('replacement_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $table->timestamp('integration_reviewed_at')->nullable();
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('cash_forecast_reviewed_refund_id')->nullable()->constrained('sale_receipts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_forecast_reviewed_refund_id');
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replacement_sale_id');
            $table->dropColumn(['nature', 'realized_on', 'integration_review', 'integration_reviewed_at']);
        });
        Schema::table('cash_projections', function (Blueprint $table) {
            $table->dropColumn(['sales_integration_enabled', 'integration_reviewed_at']);
        });
    }
};
