<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseTransfer extends Model
{
    protected $fillable = [
        'origin_warehouse_id',
        'destination_warehouse_id',
        'requested_by',
        'approved_by',
        'dispatched_by',
        'received_by',
        'status',
        'notes',
        'discrepancy_status',
        'discrepancy_notes',
        'discrepancy_resolved_by',
        'discrepancy_resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'discrepancy_resolved_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(WarehouseTransferItem::class);
    }

    public function originWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function discrepancyResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discrepancy_resolved_by');
    }
}
