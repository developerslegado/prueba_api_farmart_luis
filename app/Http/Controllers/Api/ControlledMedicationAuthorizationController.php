<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ControlledMedicationAuthorization;
use App\Models\Prescription;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlledMedicationAuthorizationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ControlledMedicationAuthorization::with([
            'prescription.patient:id,name,document_ref',
            'product:id,code,name',
            'requester:id,name,role',
            'approver:id,name,role',
        ])->latest('id');

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->has('prescription_id')) {
            $query->where('prescription_id', $request->query('prescription_id'));
        }

        $authorizations = $query->paginate(20);

        if ($request->user()->role === 'auditor') {
            $authorizations->getCollection()->transform(function ($item) {
                if ($item->prescription && $item->prescription->patient) {
                    $item->prescription->patient->name = '*** Paciente Protegido ***';
                    $item->prescription->patient->document_ref = '******';
                }
                return $item;
            });
        }

        return response()->json($authorizations);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'prescription_id' => ['required', 'integer', 'exists:prescriptions,id'],
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $authorization = DB::transaction(function () use ($request, $data) {
            $prescription = Prescription::query()
                ->lockForUpdate()
                ->findOrFail($data['prescription_id']);

            $product = Product::query()->findOrFail($data['product_id']);

            if (! $product->controlled) {
                throw ValidationException::withMessages([
                    'product_id' => 'Este medicamento no requiere autorización especial.',
                ]);
            }

            if (! $prescription->items()
                ->where('product_id', $product->id)
                ->exists()) {
                throw ValidationException::withMessages([
                    'product_id' => 'El medicamento no pertenece a esta fórmula médica.',
                ]);
            }

            if (
                strtolower((string) $prescription->status) !== 'active'
                || ($prescription->valid_from && $prescription->valid_from->isFuture())
                || ($prescription->valid_until && $prescription->valid_until->isPast())
            ) {
                throw ValidationException::withMessages([
                    'prescription_id' => 'La fórmula médica no está vigente.',
                ]);
            }

            $pending = ControlledMedicationAuthorization::query()
                ->where('prescription_id', $prescription->id)
                ->where('product_id', $product->id)
                ->where('requested_by', $request->user()->id)
                ->where('status', 'PENDIENTE')
                ->exists();

            if ($pending) {
                throw ValidationException::withMessages([
                    'product_id' => 'Ya existe una solicitud pendiente para este medicamento.',
                ]);
            }

            return ControlledMedicationAuthorization::create([
                'prescription_id' => $prescription->id,
                'product_id' => $product->id,
                'requested_by' => $request->user()->id,
                'status' => 'PENDIENTE',
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Solicitud de autorización creada.',
            'data' => $authorization,
        ], 201);
    }

    public function approve(Request $request, int $authorization): JsonResponse
    {
        if ($request->user()->role !== 'regente_farmacia') {
            return response()->json([
                'message' => 'Solo un regente de farmacia puede aprobar esta autorización.',
            ], 403);
        }

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $approved = DB::transaction(function () use ($request, $authorization, $data) {
            $record = ControlledMedicationAuthorization::query()
                ->lockForUpdate()
                ->findOrFail($authorization);

            if ($record->status !== 'PENDIENTE') {
                throw ValidationException::withMessages([
                    'authorization' => 'La solicitud ya fue procesada.',
                ]);
            }

            if ((int) $record->requested_by === (int) $request->user()->id) {
                throw ValidationException::withMessages([
                    'authorization' => 'No puedes aprobar tu propia solicitud.',
                ]);
            }

            if ($record->dispensation_id !== null) {
                throw ValidationException::withMessages([
                    'authorization' => 'Esta autorización ya fue utilizada.',
                ]);
            }

            $record->status = 'APROBADA';
            $record->approved_by = $request->user()->id;
            $record->approved_at = now();

            if (! empty($data['notes'])) {
                $record->notes = $data['notes'];
            }

            $record->save();

            return $record->refresh();
        });

        return response()->json([
            'message' => 'Autorización aprobada.',
            'data' => $approved,
        ]);
    }
}
