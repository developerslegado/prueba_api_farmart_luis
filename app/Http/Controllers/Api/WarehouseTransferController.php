<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\StockMovement;
use App\Models\WarehouseTransfer;
use App\Models\WarehouseTransferItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseTransferController extends Controller
{
    public function index()
    {
        return WarehouseTransfer::with([
            'originWarehouse:id,name',
            'destinationWarehouse:id,name',
            'items.sourceLot.product:id,code,name',
            'items.destinationLot',
            'requester:id,name',
            'approver:id,name',
            'discrepancyResolver:id,name',
        ])->latest()->paginate(20);
    }

    public function show(WarehouseTransfer $transfer)
    {
        return $transfer->load([
            'originWarehouse:id,name',
            'destinationWarehouse:id,name',
            'items.sourceLot.product:id,code,name',
            'items.destinationLot',
            'requester:id,name',
            'approver:id,name',
            'discrepancyResolver:id,name',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'origin_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'destination_warehouse_id' => ['required', 'integer', 'different:origin_warehouse_id', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_lot_id' => ['required', 'integer', 'distinct', 'exists:inventory_lots,id'],
            'items.*.quantity_requested' => ['required', 'integer', 'min:1'],
        ]);

        $transfer = DB::transaction(function () use ($data, $request) {
            $transfer = WarehouseTransfer::create([
                'origin_warehouse_id' => $data['origin_warehouse_id'],
                'destination_warehouse_id' => $data['destination_warehouse_id'],
                'requested_by' => $request->user()->id,
                'status' => 'BORRADOR',
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $item) {
                $lot = InventoryLot::query()
                    ->whereKey($item['inventory_lot_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lot->warehouse_id != $data['origin_warehouse_id']) {
                    throw ValidationException::withMessages([
                        'items' => 'Todos los lotes deben pertenecer al almacén de origen.',
                    ]);
                }

                if ($lot->expires_at->lt(today())) {
                    throw ValidationException::withMessages([
                        'items' => "El lote {$lot->lot_number} está vencido.",
                    ]);
                }

                $transfer->items()->create([
                    'inventory_lot_id' => $lot->id,
                    'quantity_requested' => $item['quantity_requested'],
                ]);
            }

            return $transfer;
        }, 3);

        return response()->json($transfer->load('items'), 201);
    }

    public function requestApproval(Request $request, WarehouseTransfer $transfer)
    {
        return DB::transaction(function () use ($request, $transfer) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            abort_unless(
                $transfer->status === 'BORRADOR',
                409,
                'La transferencia no está en borrador.'
            );

            abort_unless(
                $transfer->requested_by == $request->user()->id,
                403,
                'Solo quien creó la transferencia puede solicitar su aprobación.'
            );

            $transfer->update(['status' => 'SOLICITADO']);

            return response()->json($transfer->fresh()->load('items'));
        }, 3);
    }

    public function approve(Request $request, WarehouseTransfer $transfer)
    {
        return DB::transaction(function () use ($request, $transfer) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            abort_unless($transfer->status === 'SOLICITADO', 409, 'La transferencia no está solicitada.');

            abort_if(
                $transfer->requested_by == $request->user()->id,
                403,
                'Quien solicita la transferencia no puede aprobarla (Segregación RN-08).'
            );

            $transfer->update([
                'status' => 'APROBADO',
                'approved_by' => $request->user()->id,
            ]);

            return response()->json($transfer->fresh()->load('items'));
        }, 3);
    }

    public function dispatch(Request $request, WarehouseTransfer $transfer)
    {
        return DB::transaction(function () use ($request, $transfer) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->with('items')
                ->findOrFail($transfer->id);

            abort_unless($transfer->status === 'APROBADO', 409, 'La transferencia debe estar aprobada.');

            foreach ($transfer->items as $item) {
                $lot = InventoryLot::query()
                    ->lockForUpdate()
                    ->findOrFail($item->inventory_lot_id);

                abort_unless(
                    $lot->warehouse_id == $transfer->origin_warehouse_id,
                    409,
                    'El lote ya no pertenece al almacén de origen.'
                );

                abort_if($lot->expires_at->lt(today()), 409, "No se puede despachar un lote vencido ({$lot->lot_number}).");

                abort_if(
                    $lot->quantity < $item->quantity_requested,
                    409,
                    "Stock insuficiente para el lote {$lot->lot_number}."
                );

                $lot->decrement('quantity', $item->quantity_requested);

                $item->update([
                    'quantity_dispatched' => $item->quantity_requested,
                ]);

                StockMovement::create([
                    'inventory_lot_id' => $lot->id,
                    'user_id' => $request->user()->id,
                    'type' => 'transfer_dispatch',
                    'quantity_delta' => -$item->quantity_requested,
                    'reference_type' => 'warehouse_transfer',
                    'reference_id' => $transfer->id,
                    'reason' => 'Despacho de transferencia #' . $transfer->id,
                ]);
            }

            $transfer->update([
                'status' => 'EN_TRANSITO',
                'dispatched_by' => $request->user()->id,
            ]);

            return response()->json($transfer->fresh()->load('items'));
        }, 3);
    }

    public function receive(Request $request, WarehouseTransfer $transfer)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.transfer_item_id' => [
                'required',
                'integer',
                'distinct',
                'exists:warehouse_transfer_items,id',
            ],
            'items.*.quantity_received' => ['required', 'integer', 'min:0'],
        ]);

        return DB::transaction(function () use ($request, $transfer, $data) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            abort_unless(
                in_array($transfer->status, ['EN_TRANSITO', 'RECIBIDO_PARCIAL'], true),
                409,
                'La transferencia no está en tránsito o pendiente de recepción.'
            );

            $receivedAnything = false;

            foreach ($data['items'] as $receivedItem) {
                $item = WarehouseTransferItem::query()
                    ->where('warehouse_transfer_id', $transfer->id)
                    ->lockForUpdate()
                    ->findOrFail($receivedItem['transfer_item_id']);

                $quantity = $receivedItem['quantity_received'];

                abort_if(
                    $item->quantity_received + $quantity > $item->quantity_dispatched,
                    422,
                    'La cantidad recibida supera la cantidad despachada.'
                );

                if ($quantity > 0) {
                    $receivedAnything = true;

                    $sourceLot = InventoryLot::query()
                        ->lockForUpdate()
                        ->findOrFail($item->inventory_lot_id);

                    $destinationLot = InventoryLot::query()
                        ->where('warehouse_id', $transfer->destination_warehouse_id)
                        ->where('product_id', $sourceLot->product_id)
                        ->where('lot_number', $sourceLot->lot_number)
                        ->lockForUpdate()
                        ->first();

                    if (!$destinationLot) {
                        $destinationLot = InventoryLot::create([
                            'warehouse_id' => $transfer->destination_warehouse_id,
                            'product_id' => $sourceLot->product_id,
                            'lot_number' => $sourceLot->lot_number,
                            'expires_at' => $sourceLot->expires_at->toDateString(),
                            'quantity' => 0,
                        ]);
                    }

                    abort_if(
                        $destinationLot->expires_at->lt(today()),
                        409,
                        'No se puede recibir un lote vencido.'
                    );

                    $destinationLot->increment('quantity', $quantity);

                    $newReceivedTotal = $item->quantity_received + $quantity;
                    $discrepancy = $item->quantity_dispatched - $newReceivedTotal;

                    $item->update([
                        'quantity_received' => $newReceivedTotal,
                        'quantity_discrepancy' => max(0, $discrepancy),
                        'destination_inventory_lot_id' => $destinationLot->id,
                    ]);

                    StockMovement::create([
                        'inventory_lot_id' => $destinationLot->id,
                        'user_id' => $request->user()->id,
                        'type' => 'transfer_receive',
                        'quantity_delta' => $quantity,
                        'reference_type' => 'warehouse_transfer',
                        'reference_id' => $transfer->id,
                        'reason' => 'Recepción de transferencia #' . $transfer->id,
                    ]);
                }
            }

            abort_unless($receivedAnything, 422, 'Debes ingresar al menos una unidad recibida.');

            $items = WarehouseTransferItem::query()
                ->where('warehouse_transfer_id', $transfer->id)
                ->get();

            $complete = $items->every(
                fn($item) => $item->quantity_received === $item->quantity_dispatched
            );

            $transfer->update([
                'status' => $complete ? 'RECIBIDO' : 'RECIBIDO_PARCIAL',
                'received_by' => $request->user()->id,
                'discrepancy_status' => $complete ? null : 'PENDIENTE',
            ]);

            return response()->json($transfer->fresh()->load('items'));
        }, 3);
    }

    /**
     * Resolución de discrepancia por Regente de Farmacia (RN-07)
     */
    public function resolveDiscrepancy(Request $request, WarehouseTransfer $transfer)
    {
        $validated = $request->validate([
            'notes' => ['required', 'string', 'min:5', 'max:1000'],
            'create_adjustment' => ['nullable', 'boolean'],
        ]);

        return DB::transaction(function () use ($request, $transfer, $validated) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->with('items.sourceLot')
                ->findOrFail($transfer->id);

            abort_unless(
                $transfer->status === 'RECIBIDO_PARCIAL' && $transfer->discrepancy_status === 'PENDIENTE',
                409,
                'El traslado no tiene una discrepancia pendiente de resolución.'
            );

            if (!empty($validated['create_adjustment'])) {
                foreach ($transfer->items as $item) {
                    $discrepancy = $item->quantity_dispatched - $item->quantity_received;
                    if ($discrepancy > 0 && $item->sourceLot) {
                        StockMovement::create([
                            'inventory_lot_id' => $item->sourceLot->id,
                            'user_id' => $request->user()->id,
                            'type' => 'adjustment',
                            'quantity_delta' => -$discrepancy,
                            'reference_type' => 'warehouse_transfer_discrepancy',
                            'reference_id' => $transfer->id,
                            'reason' => 'Pérdida/Mermas en traslado #' . $transfer->id . ': ' . $validated['notes'],
                        ]);
                    }
                }
            }

            $transfer->update([
                'discrepancy_status' => 'RESUELTA',
                'discrepancy_notes' => $validated['notes'],
                'discrepancy_resolved_by' => $request->user()->id,
                'discrepancy_resolved_at' => now(),
            ]);

            return response()->json([
                'message' => 'Discrepancia resuelta exitosamente.',
                'transfer' => $transfer->fresh()->load(['items', 'discrepancyResolver']),
            ]);
        }, 3);
    }

    public function cancel(Request $request, WarehouseTransfer $transfer)
    {
        return DB::transaction(function () use ($request, $transfer) {
            $transfer = WarehouseTransfer::query()
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            abort_unless(
                in_array($transfer->status, ['BORRADOR', 'SOLICITADO', 'APROBADO'], true),
                409,
                'No se puede anular una transferencia que ya fue despachada.'
            );

            abort_unless(
                $transfer->requested_by == $request->user()->id || $request->user()->role === 'admin',
                403,
                'Solo quien creó la transferencia o un administrador puede anularla.'
            );

            $transfer->update(['status' => 'ANULADO']);

            return response()->json($transfer->fresh());
        }, 3);
    }
}
