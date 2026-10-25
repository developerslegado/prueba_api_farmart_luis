<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLot extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'lot_number',
        'expires_at',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'quantity' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}