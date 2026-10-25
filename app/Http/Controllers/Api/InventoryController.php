<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryLot;
use App\Models\StockMovement;
use App\Models\WarehouseStockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'search' => ['sometimes', 'string', 'max:180'],
            'expires_before' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = InventoryLot::query()
            ->with([
                'warehouse:id,name,active',
                'product:id,code,name,controlled,active',
            ])
            ->orderBy('warehouse_id')
            ->orderBy('product_id')
            ->orderBy('expires_at')
            ->orderBy('id');

        if (isset($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (isset($filters['product_id'])) {
            $query->where('product_id', $filters['product_id']);
        }

        if (isset($filters['expires_before'])) {
            $query->whereDate('expires_at', '<=', $filters['expires_before']);
        }

        if (isset($filters['search'])) {
            $search = mb_strtolower($filters['search']);
            $pattern = '%' . $search . '%';

            $query->where(function ($query) use ($pattern) {
                $query->whereRaw('LOWER(lot_number) LIKE ?', [$pattern])
                    ->orWhereHas('product', function ($productQuery) use ($pattern) {
                        $productQuery
                            ->whereRaw('LOWER(name) LIKE ?', [$pattern])
                            ->orWhereRaw('LOWER(code) LIKE ?', [$pattern]);
                    });
            });
        }

        return response()->json($query->paginate($filters['per_page'] ?? 20));
    }

    public function kardex(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'inventory_lot_id' => ['sometimes', 'integer', 'exists:inventory_lots,id'],
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'type' => ['sometimes', 'string', 'max:30'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = StockMovement::query()
            ->with([
                'inventoryLot:id,warehouse_id,product_id,lot_number,expires_at',
                'inventoryLot.product:id,code,name',
                'inventoryLot.warehouse:id,name',
                'user:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['inventory_lot_id'])) {
            $query->where('inventory_lot_id', $filters['inventory_lot_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        if (isset($filters['warehouse_id']) || isset($filters['product_id'])) {
            $query->whereHas('inventoryLot', function ($lotQuery) use ($filters) {
                if (isset($filters['warehouse_id'])) {
                    $lotQuery->where('warehouse_id', $filters['warehouse_id']);
                }
                if (isset($filters['product_id'])) {
                    $lotQuery->where('product_id', $filters['product_id']);
                }
            });
        }

        return response()->json($query->paginate($filters['per_page'] ?? 20));
    }

    /**
     * Entrada de Stock (RN-06)
     */
    public function storeEntry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'lot_number' => ['required', 'string', 'max:80'],
            'expires_at' => ['required', 'date', 'after_or_equal:today'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $lot = InventoryLot::firstOrCreate(
                [
                    'warehouse_id' => $validated['warehouse_id'],
                    'product_id' => $validated['product_id'],
                    'lot_number' => trim($validated['lot_number']),
                ],
                [
                    'expires_at' => $validated['expires_at'],
                    'quantity' => 0,
                ]
            );

            $lot->increment('quantity', $validated['quantity']);

            $movement = StockMovement::create([
                'inventory_lot_id' => $lot->id,
                'user_id' => $request->user()->id,
                'type' => 'entry',
                'quantity_delta' => $validated['quantity'],
                'reference_type' => 'manual_entry',
                'reference_id' => null,
                'reason' => $validated['reason'] ?? 'Entrada manual de inventario',
            ]);

            return response()->json([
                'message' => 'Entrada registrada exitosamente.',
                'lot' => $lot->load(['product', 'warehouse']),
                'movement' => $movement,
            ], 201);
        }, 3);
    }

    /**
     * Ajuste de Inventario (RN-06 - Solo regente o admin, con motivo obligatorio)
     */
    public function storeAdjustment(Request $request): JsonResponse
    {
        if (!in_array($request->user()->role, ['regente_farmacia', 'admin'], true)) {
            return response()->json([
                'message' => 'Solo un regente de farmacia o administrador puede realizar ajustes de inventario.',
            ], 403);
        }

        $validated = $request->validate([
            'inventory_lot_id' => ['required', 'integer', 'exists:inventory_lots,id'],
            'quantity_delta' => ['required', 'integer', 'not_in:0'],
            'reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $lot = InventoryLot::query()
                ->lockForUpdate()
                ->findOrFail($validated['inventory_lot_id']);

            $newQuantity = $lot->quantity + $validated['quantity_delta'];
            if ($newQuantity < 0) {
                return response()->json([
                    'message' => "El ajuste dejaría el stock en negativo ({$newQuantity}). Operación denegada.",
                ], 422);
            }

            $lot->quantity = $newQuantity;
            $lot->save();

            $movement = StockMovement::create([
                'inventory_lot_id' => $lot->id,
                'user_id' => $request->user()->id,
                'type' => 'adjustment',
                'quantity_delta' => $validated['quantity_delta'],
                'reference_type' => 'manual_adjustment',
                'reference_id' => null,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'Ajuste de inventario aplicado exitosamente.',
                'lot' => $lot->load(['product', 'warehouse']),
                'movement' => $movement,
            ]);
        }, 3);
    }

    /**
     * Alertas de stock mínimo por bodega + producto (RN-11) y vencimientos <= 90 días
     */
    public function alerts(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'warehouse_id' => ['sometimes', 'integer', 'exists:warehouses,id'],
        ]);

        $expiryThreshold = now()->addDays(90);
        $today = now()->toDateString();

        $lotQuery = InventoryLot::query()
            ->with(['warehouse:id,name', 'product:id,code,name,controlled'])
            ->where('quantity', '>', 0);

        if (isset($filters['warehouse_id'])) {
            $lotQuery->where('warehouse_id', $filters['warehouse_id']);
        }

        $expiringSoon = (clone $lotQuery)
            ->whereDate('expires_at', '<=', $expiryThreshold)
            ->whereDate('expires_at', '>=', $today)
            ->get()
            ->map(function ($lot) {
                return [
                    'type' => 'expiring_soon',
                    'message' => "Lote {$lot->lot_number} vence el " . $lot->expires_at->format('Y-m-d'),
                    'warehouse' => $lot->warehouse->name,
                    'product' => $lot->product->name,
                    'days_until_expiry' => now()->diffInDays($lot->expires_at),
                    'quantity' => $lot->quantity,
                    'expires_at' => $lot->expires_at->format('Y-m-d'),
                ];
            });

        $expired = (clone $lotQuery)
            ->whereDate('expires_at', '<', $today)
            ->get()
            ->map(function ($lot) {
                return [
                    'type' => 'expired',
                    'message' => "Lote {$lot->lot_number} vencido el " . $lot->expires_at->format('Y-m-d'),
                    'warehouse' => $lot->warehouse->name,
                    'product' => $lot->product->name,
                    'quantity' => $lot->quantity,
                    'expires_at' => $lot->expires_at->format('Y-m-d'),
                ];
            });

        $levelsQuery = WarehouseStockLevel::query()->with(['warehouse:id,name', 'product:id,code,name']);
        if (isset($filters['warehouse_id'])) {
            $levelsQuery->where('warehouse_id', $filters['warehouse_id']);
        }
        $stockLevels = $levelsQuery->get();

        $lowStock = [];
        foreach ($stockLevels as $level) {
            $currentValidStock = (int) InventoryLot::query()
                ->where('warehouse_id', $level->warehouse_id)
                ->where('product_id', $level->product_id)
                ->whereDate('expires_at', '>=', $today)
                ->sum('quantity');

            if ($currentValidStock < $level->minimum_stock) {
                $lowStock[] = [
                    'type' => 'low_stock',
                    'warehouse_id' => $level->warehouse_id,
                    'warehouse' => $level->warehouse->name,
                    'product_id' => $level->product_id,
                    'product' => $level->product->name,
                    'current_stock' => $currentValidStock,
                    'minimum_stock' => $level->minimum_stock,
                    'shortage' => $level->minimum_stock - $currentValidStock,
                    'message' => "{$level->product->name} en {$level->warehouse->name}: {$currentValidStock} unds vigentes (mínimo: {$level->minimum_stock})",
                ];
            }
        }

        return response()->json([
            'expiring_soon' => $expiringSoon,
            'expired' => $expired,
            'low_stock' => $lowStock,
            'summary' => [
                'expiring_soon_count' => $expiringSoon->count(),
                'expired_count' => $expired->count(),
                'low_stock_count' => count($lowStock),
            ],
        ]);
    }

    /**
     * CRUD de Niveles de Stock Mínimo por Bodega y Producto (RN-11)
     */
    public function getStockLevels(Request $request): JsonResponse
    {
        $levels = WarehouseStockLevel::with(['warehouse:id,name', 'product:id,code,name'])->get();
        return response()->json(['data' => $levels]);
    }

    public function setStockLevel(Request $request): JsonResponse
    {
        if (!in_array($request->user()->role, ['admin', 'regente_farmacia'], true)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'minimum_stock' => ['required', 'integer', 'min:0'],
        ]);

        $level = WarehouseStockLevel::updateOrCreate(
            [
                'warehouse_id' => $validated['warehouse_id'],
                'product_id' => $validated['product_id'],
            ],
            [
                'minimum_stock' => $validated['minimum_stock'],
            ]
        );

        return response()->json([
            'message' => 'Nivel de stock mínimo actualizado.',
            'data' => $level->load(['warehouse', 'product']),
        ]);
    }
}
