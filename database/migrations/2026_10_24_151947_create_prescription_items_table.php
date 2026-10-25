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
        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();

            $table
                ->foreignId('prescription_id')
                ->constrained()
                ->restrictOnDelete();

            $table
                ->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->integer('quantity_prescribed');
            $table->timestamps();

            $table->unique(
                ['prescription_id', 'product_id']
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
