<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ControlledMedicationAuthorization;
use App\Models\InventoryLot;
use App\Models\Patient;
use App\Models\PatientAccessLog;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientPrescriptionController extends Controller
{
    /**
     * Buscar pacientes (con enmascaramiento para rol 'auditor' y bitácora de acceso).
     */
    public function searchPatients(Request $request): JsonResponse
    {
        $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $isAuditor = $user->role === 'auditor';
        $search = $request->query('query');

        $query = Patient::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('document_ref', 'ilike', "%{$search}%");
            });
        }

        $patients = $query->limit(20)->get();

        foreach ($patients as $patient) {
            PatientAccessLog::create([
                'user_id' => $user->id,
                'patient_id' => $patient->id,
                'action' => 'search_view',
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        }

        $formatted = $patients->map(function ($patient) use ($isAuditor) {
            return [
                'id' => $patient->id,
                'document_ref' => $isAuditor
                    ? $this->maskDocument($patient->document_ref)
                    : $patient->document_ref,
                'name' => $isAuditor
                    ? $this->maskName($patient->name)
                    : $patient->name,
                'created_at' => $patient->created_at,
            ];
        });

        return response()->json([
            'data' => $formatted,
        ]);
    }

    /**
     * Consultar prescripciones activas de un paciente, calculando lo dispensado acumulado.
     */
    public function getPatientPrescriptions(Request $request, int $patientId): JsonResponse
    {
        $user = $request->user();
        $isAuditor = $user->role === 'auditor';

        $patient = Patient::findOrFail($patientId);

        PatientAccessLog::create([
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'action' => 'prescriptions_view',
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        $today = now()->toDateString();

        $prescriptions = Prescription::query()
            ->with(['items.product', 'prescriber:id,name'])
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->whereDate('valid_from', '<=', $today)
            ->whereDate('valid_until', '>=', $today)
            ->get();

        $data = $prescriptions->map(function ($prescription) {
            $items = $prescription->items->map(function ($item) use ($prescription) {
                $dispensed = (int) DB::table('dispensation_items')
                    ->join('dispensations', 'dispensations.id', '=', 'dispensation_items.dispensation_id')
                    ->where('dispensations.prescription_id', $prescription->id)
                    ->where('dispensation_items.prescription_item_id', $item->id)
                    ->sum('dispensation_items.quantity');

                $remaining = max(0, $item->quantity_prescribed - $dispensed);

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_code' => $item->product->code,
                    'product_name' => $item->product->name,
                    'controlled' => (bool) $item->product->controlled,
                    'quantity_prescribed' => $item->quantity_prescribed,
                    'quantity_dispensed' => $dispensed,
                    'quantity_remaining' => $remaining,
                ];
            });

            return [
                'id' => $prescription->id,
                'prescribed_by_name' => $prescription->prescriber?->name ?? 'Médico',
                'valid_from' => $prescription->valid_from->toDateString(),
                'valid_until' => $prescription->valid_until->toDateString(),
                'status' => $prescription->status,
                'items' => $items,
            ];
        });

        return response()->json([
            'patient' => [
                'id' => $patient->id,
                'name' => $isAuditor ? $this->maskName($patient->name) : $patient->name,
                'document_ref' => $isAuditor ? $this->maskDocument($patient->document_ref) : $patient->document_ref,
            ],
            'prescriptions' => $data,
        ]);
    }

    /**
     * Crear prescripción médica (Médico o Admin).
     */
    public function storePrescription(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:valid_from'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity_prescribed' => ['required', 'integer', 'min:1'],
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $prescription = Prescription::create([
                'patient_id' => $validated['patient_id'],
                'prescribed_by' => $request->user()->id,
                'valid_from' => $validated['valid_from'],
                'valid_until' => $validated['valid_until'],
                'status' => 'active',
            ]);

            foreach ($validated['items'] as $item) {
                PrescriptionItem::create([
                    'prescription_id' => $prescription->id,
                    'product_id' => $item['product_id'],
                    'quantity_prescribed' => $item['quantity_prescribed'],
                ]);
            }

            PatientAccessLog::create([
                'user_id' => $request->user()->id,
                'patient_id' => $validated['patient_id'],
                'action' => 'prescription_created',
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);

            $prescription->load(['items.product', 'patient']);

            return response()->json([
                'message' => 'Prescripción creada exitosamente.',
                'data' => $prescription,
            ], 201);
        });
    }

    /**
     * Bitácora de accesos a datos de pacientes (Auditor y Admin) con datos enmascarados (RN-10).
     */
    public function patientAccessLogs(Request $request): JsonResponse
    {
        $logs = PatientAccessLog::with(['user:id,name,email,role', 'patient:id,name,document_ref'])
            ->orderByDesc('id')
            ->paginate(25);

        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                    'role' => $log->user->role,
                ] : null,
                'patient' => $log->patient ? [
                    'id' => $log->patient->id,
                    'name' => $this->maskName($log->patient->name),
                    'document_ref' => $this->maskDocument($log->patient->document_ref),
                ] : null,
                'action' => $log->action,
                'ip_address' => $log->ip_address,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        });

        return response()->json($logs);
    }

    /**
     * Preview FEFO para dispensación: calcula qué lotes se consumirían según orden FEFO.
     */
    public function previewFefo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $today = now()->toDateString();

        $lots = InventoryLot::query()
            ->where('warehouse_id', $validated['warehouse_id'])
            ->where('product_id', $validated['product_id'])
            ->where('quantity', '>', 0)
            ->whereDate('expires_at', '>=', $today)
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();

        $allocatedLots = [];
        $remaining = $validated['quantity'];
        $totalAvailable = $lots->sum('quantity');

        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($lot->quantity, $remaining);
            $allocatedLots[] = [
                'lot_id' => $lot->id,
                'lot_number' => $lot->lot_number,
                'expires_at' => $lot->expires_at->toDateString(),
                'available_quantity' => $lot->quantity,
                'allocated_quantity' => $take,
            ];
            $remaining -= $take;
        }

        return response()->json([
            'product_id' => $validated['product_id'],
            'warehouse_id' => $validated['warehouse_id'],
            'requested_quantity' => $validated['quantity'],
            'total_available' => $totalAvailable,
            'can_fulfill' => $totalAvailable >= $validated['quantity'],
            'shortage' => max(0, $remaining),
            'allocated_lots' => $allocatedLots,
        ]);
    }

    /**
     * Lista de bodegas activas para selectores en UI
     */
    public function getWarehouses(): JsonResponse
    {
        $warehouses = Warehouse::where('active', true)->select('id', 'name')->get();
        return response()->json(['data' => $warehouses]);
    }

    /**
     * Lista de productos activos para selectores en UI
     */
    public function getProducts(): JsonResponse
    {
        $products = Product::where('active', true)->select('id', 'code', 'name', 'controlled')->get();
        return response()->json(['data' => $products]);
    }

    /**
     * Enmascara un nombre (ej: "María García" -> "M***a G***a")
     */
    private function maskName(string $name): string
    {
        $parts = explode(' ', $name);
        $masked = array_map(function ($part) {
            $len = mb_strlen($part);
            if ($len <= 2) {
                return str_repeat('*', $len);
            }
            return mb_substr($part, 0, 1) . str_repeat('*', $len - 2) . mb_substr($part, -1);
        }, $parts);

        return implode(' ', $masked);
    }

    /**
     * Enmascara documento (ej: "1234567890" -> "******7890")
     */
    private function maskDocument(string $doc): string
    {
        $len = mb_strlen($doc);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . mb_substr($doc, -4);
    }
}
