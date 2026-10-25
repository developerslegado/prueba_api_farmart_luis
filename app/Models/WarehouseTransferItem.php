<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseTransferItem extends Model
{
    protected $fillable = [
        'warehouse_transfer_id',
        'inventory_lot_id',
        'destination_inventory_lot_id',
        'quantity_requested',
        'quantity_dispatched',
        'quantity_received',
        'quantity_discrepancy',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested' => 'integer',
            'quantity_dispatched' => 'integer',
            'quantity_received' => 'integer',
            'quantity_discrepancy' => 'integer',
        ];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(
            WarehouseTransfer::class,
            'warehouse_transfer_id'
        );
    }

    public function sourceLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    public function destinationLot(): BelongsTo
    {
        return $this->belongsTo(
            InventoryLot::class,
            'destination_inventory_lot_id'
        );
    }
}
