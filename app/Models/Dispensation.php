<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dispensation extends Model
{
    protected $fillable = [
        'prescription_id',
        'warehouse_id',
        'dispensed_by',
        'idempotency_key',
        'request_hash',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(DispensationItem::class);
    }
}