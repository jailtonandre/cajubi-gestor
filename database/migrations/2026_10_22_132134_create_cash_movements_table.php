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
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_projection_id')->constrained()->cascadeOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('description', 150);
            $table->string('direction', 10);
            $table->decimal('amount', 14, 2);
            $table->date('expected_on');
            $table->index(['cash_projection_id', 'expected_on', 'id']);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movements_valid CHECK (amount > 0 AND direction IN ('inflow', 'outflow'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
