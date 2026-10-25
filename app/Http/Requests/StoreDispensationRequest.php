<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDispensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prescription_id' => ['required', 'integer', 'exists:prescriptions,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required', 'integer', 'distinct', 'exists:products,id',
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}