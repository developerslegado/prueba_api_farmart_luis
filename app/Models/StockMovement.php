<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    protected $fillable = [
        'inventory_lot_id',
        'user_id',
        'type',
        'quantity_delta',
        'reference_type',
        'reference_id',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
        ];
    }

    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException(
            'Los movimientos de kardex son inmutables.'
        ));

        static::deleting(fn () => throw new LogicException(
            'Los movimientos de kardex no se pueden eliminar.'
        ));
    }
}
