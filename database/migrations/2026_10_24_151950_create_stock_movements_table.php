<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('inventory_lot_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('user_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('type', 30);
            $table->integer('quantity_delta');
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->index(['inventory_lot_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
