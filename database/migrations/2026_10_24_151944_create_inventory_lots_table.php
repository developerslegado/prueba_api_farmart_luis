
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('lot_number', 80);
            $table->date('expires_at');
            $table->integer('quantity')->default(0);

            $table->timestamps();

            $table->unique(
                ['warehouse_id', 'product_id', 'lot_number'],
                'inventory_lot_identity_unique'
            );

            $table->index([
                'warehouse_id',
                'product_id',
                'expires_at',
                'id',
            ]);
        });

        DB::statement(
            'ALTER TABLE inventory_lots
             ADD CONSTRAINT inventory_lots_quantity_nonnegative
             CHECK (quantity >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
