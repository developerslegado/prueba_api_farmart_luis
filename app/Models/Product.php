<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'code',
        'name',
        'controlled',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'controlled' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function inventoryLots(): HasMany
    {
        return $this->hasMany(InventoryLot::class);
    }
}