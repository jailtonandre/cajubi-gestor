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
        foreach (['products', 'supplies'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->string('image_path')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['products', 'supplies'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('image_path');
            });
        }
    }
};
