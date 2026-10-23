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
        Schema::create('sale_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('reference', 100);
            $table->unique(['sale_id', 'reference']);
            $table->string('kind', 10);
            $table->decimal('amount', 14, 2);
            $table->date('received_on');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sale_receipts ADD CONSTRAINT sale_receipts_valid CHECK (amount > 0 AND kind IN ('receipt', 'refund'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_receipts');
    }
};
