<?php

namespace App\Services;

use App\Models\ControlledMedicationAuthorization;
use App\Models\Dispensation;
use App\Models\InventoryLot;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DispensationService
{
    public function dispense(
        int $userId,
        int $prescriptionId,
        int $warehouseId,
        string $idempotencyKey,
        array $items
    ): Dispensation {
        $normalizedItems = collect($items)
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => (int) $item['quantity'],
            ])
            ->sortBy('product_id')
            ->values()
            ->all();

        if (
            empty($normalizedItems) ||
            collect($normalizedItems)->contains(
                fn ($item) => $item['product_id'] < 1
                    || $item['quantity'] < 1
            ) ||
            collect($normalizedItems)
                ->pluck('product_id')
                ->unique()
                ->count() !== count($normalizedItems)
        ) {
            throw ValidationException::withMessages([
                'items' => 'Los productos deben ser únicos y las cantidades positivas.',
            ]);
        }

        $requestHash = hash('sha256', json_encode([
            'prescription_id' => $prescriptionId,
            'warehouse_id' => $warehouseId,
            'items' => $normalizedItems,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $userId,
            $prescriptionId,
            $warehouseId,
            $idempotencyKey,
            $normalizedItems,
            $requestHash
        ) {
            DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first();

            $existing = Dispensation::query()
                ->where('dispensed_by', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if (! hash_equals(
                    $existing->request_hash,
                    $requestHash
                )) {
                    abort(
                        409,
                        'La clave idempotente ya se utilizó con otra solicitud.'
                    );
                }

                $existing->setAttribute('idempotent_replay', true);

                return $existing->load('items');
            }

            $prescription = Prescription::query()
                ->whereKey($prescriptionId)
                ->lockForUpdate()
                ->firstOrFail();

            $today = now()->toDateString();

            if (
                $prescription->status !== 'active' ||
                $prescription->valid_from->toDateString() > $today ||
                $prescription->valid_until->toDateString() < $today
            ) {
                abort(422, 'La prescripción no está vigente.');
            }

            $dispensation = Dispensation::create([
                'prescription_id' => $prescription->id,
                'warehouse_id' => $warehouseId,
                'dispensed_by' => $userId,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            foreach ($normalizedItems as $requested) {
                $prescriptionItem = PrescriptionItem::query()
                    ->where('prescription_id', $prescription->id)
                    ->where('product_id', $requested['product_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyDispensed = DB::table('dispensation_items')
                    ->join(
                        'dispensations',
                        'dispensations.id',
                        '=',
                        'dispensation_items.dispensation_id'
                    )
                    ->where(
                        'dispensations.prescription_id',
                        $prescription->id
                    )
                    ->where(
                        'dispensation_items.prescription_item_id',
                        $prescriptionItem->id
                    )
                    ->sum('dispensation_items.quantity');

                $remaining = $prescriptionItem->quantity_prescribed
                    - $alreadyDispensed;

                if ($requested['quantity'] > $remaining) {
                    abort(422, 'La cantidad supera lo prescrito.');
                }

                $product = DB::table('products')
                    ->where('id', $requested['product_id'])
                    ->first();

                if (! $product || ! $product->active) {
                    abort(422, 'El medicamento no está disponible.');
                }

                /*
                 * Los medicamentos controlados requieren una
                 * autorización aprobada por un regente distinto
                 * del usuario que realiza la dispensación.
                 */
                if ($product->controlled) {
                    $authorization = ControlledMedicationAuthorization::query()
                        ->where('prescription_id', $prescription->id)
                        ->where('product_id', $requested['product_id'])
                        ->where('status', 'APROBADA')
                        ->whereNull('dispensation_id')
                        ->whereNotNull('approved_by')
                        ->where('approved_by', '!=', $userId)
                        ->whereHas('approver', function ($query) {
                            $query->where('role', 'regente_farmacia');
                        })
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->first();

                    if (! $authorization) {
                        abort(
                            403,
                            'Se requiere una autorización aprobada y disponible por un regente distinto del dispensador.'
                        );
                    }

                    $authorization->dispensation_id = $dispensation->id;
                    $authorization->save();
                }

                $lots = InventoryLot::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $requested['product_id'])
                    ->where('quantity', '>', 0)
                    ->whereDate('expires_at', '>=', $today)
                    ->orderBy('expires_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $available = $lots->sum('quantity');

                if ($available < $requested['quantity']) {
                    abort(422, 'Stock insuficiente para dispensar.');
                }

                $toDispense = $requested['quantity'];

                foreach ($lots as $lot) {
                    if ($toDispense === 0) {
                        break;
                    }

                    $taken = min($lot->quantity, $toDispense);

                    $lot->quantity -= $taken;
                    $lot->save();

                    $dispensation->items()->create([
                        'prescription_item_id' => $prescriptionItem->id,
                        'inventory_lot_id' => $lot->id,
                        'quantity' => $taken,
                    ]);

                    StockMovement::create([
                        'inventory_lot_id' => $lot->id,
                        'user_id' => $userId,
                        'type' => 'dispensation',
                        'quantity_delta' => -$taken,
                        'reference_type' => 'dispensation',
                        'reference_id' => $dispensation->id,
                    ]);

                    $toDispense -= $taken;
                }
            }

            $dispensation->setAttribute('idempotent_replay', false);

            return $dispensation->load('items');
        }, attempts: 3);
    }
}
