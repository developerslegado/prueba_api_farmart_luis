<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDispensationRequest;
use App\Services\DispensationService;
use Illuminate\Http\JsonResponse;

class DispensationController extends Controller
{
    public function store(
        StoreDispensationRequest $request,
        DispensationService $service
    ): JsonResponse {
        $data = $request->validated();

        $dispensation = $service->dispense(
            userId: (int) $request->user()->id,
            prescriptionId: (int) $data['prescription_id'],
            warehouseId: (int) $data['warehouse_id'],
            idempotencyKey: $data['idempotency_key'],
            items: $data['items'],
        );

        $isReplay = (bool) $dispensation->getAttribute('idempotent_replay');

        return response()->json([
            'data' => [
                'id' => $dispensation->id,
                'prescription_id' => $dispensation->prescription_id,
                'warehouse_id' => $dispensation->warehouse_id,
                'items' => $dispensation->items->map(fn($item) => [
                    'lot_id' => $item->inventory_lot_id,
                    'quantity' => $item->quantity,
                ])->values(),
            ],
        ], $isReplay ? 200 : 201);
    }
}
