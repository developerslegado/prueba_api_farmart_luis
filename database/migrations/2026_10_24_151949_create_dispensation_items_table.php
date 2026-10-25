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
        Schema::create('dispensation_items', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('dispensation_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('prescription_item_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('inventory_lot_id')
                ->constrained()
                ->restrictOnDelete();

            $table->integer('quantity');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispensation_items');
    }
};
