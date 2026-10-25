
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_transfers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('origin_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('destination_warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('dispatched_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('received_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 25)->default('BORRADOR');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('warehouse_transfer_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('warehouse_transfer_id')
                ->constrained('warehouse_transfers')
                ->restrictOnDelete();

            $table->foreignId('inventory_lot_id')
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->foreignId('destination_inventory_lot_id')
                ->nullable()
                ->constrained('inventory_lots')
                ->restrictOnDelete();

            $table->integer('quantity_requested');
            $table->integer('quantity_dispatched')->default(0);
            $table->integer('quantity_received')->default(0);
            $table->timestamps();

            $table->unique(
                ['warehouse_transfer_id', 'inventory_lot_id'],
                'warehouse_transfer_source_lot_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_transfer_items');
        Schema::dropIfExists('warehouse_transfers');
    }
};
