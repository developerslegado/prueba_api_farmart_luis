<?php

namespace Database\Seeders;

use App\Models\InventoryLot;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Usuarios por rol
        User::firstOrCreate(
            ['email' => 'admin@farmart.com'],
            [
                'name' => 'Administrador Sistema',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        User::firstOrCreate(
            ['email' => 'regente@farmart.com'],
            [
                'name' => 'Regente Farmacia',
                'password' => Hash::make('password'),
                'role' => 'regente_farmacia',
            ]
        );

        User::firstOrCreate(
            ['email' => 'auxiliar@farmart.com'],
            [
                'name' => 'Auxiliar Farmacia',
                'password' => Hash::make('password'),
                'role' => 'auxiliar_farmacia',
            ]
        );

        User::firstOrCreate(
            ['email' => 'medico@farmart.com'],
            [
                'name' => 'Dr. Juan Pérez',
                'password' => Hash::make('password'),
                'role' => 'medico',
            ]
        );

        User::firstOrCreate(
            ['email' => 'auditor@farmart.com'],
            [
                'name' => 'Auditor Externo',
                'password' => Hash::make('password'),
                'role' => 'auditor',
            ]
        );

        // 3 Bodegas
        $warehouses = [
            ['name' => 'Farmacia Central', 'active' => true],
            ['name' => 'Farmacia Urgencias', 'active' => true],
            ['name' => 'Bodega Hospitalización', 'active' => true],
        ];

        foreach ($warehouses as $warehouse) {
            Warehouse::firstOrCreate(['name' => $warehouse['name']], $warehouse);
        }

        $central = Warehouse::where('name', 'Farmacia Central')->first();
        $urgencias = Warehouse::where('name', 'Farmacia Urgencias')->first();
        $hospitalizacion = Warehouse::where('name', 'Bodega Hospitalización')->first();

        // 6 Medicamentos (incluyendo uno de control especial)
        $products = [
            [
                'code' => 'MED-001',
                'name' => 'Paracetamol 500mg',
                'controlled' => false,
                'active' => true,
            ],
            [
                'code' => 'MED-002',
                'name' => 'Amoxicilina 500mg',
                'controlled' => false,
                'active' => true,
            ],
            [
                'code' => 'MED-003',
                'name' => 'Ibuprofeno 400mg',
                'controlled' => false,
                'active' => true,
            ],
            [
                'code' => 'MED-004',
                'name' => 'Omeprazol 20mg',
                'controlled' => false,
                'active' => true,
            ],
            [
                'code' => 'MED-005',
                'name' => 'Diazepam 10mg',
                'controlled' => true, // Control especial
                'active' => true,
            ],
            [
                'code' => 'MED-006',
                'name' => 'Ceftriaxona 1g',
                'controlled' => false,
                'active' => true,
            ],
        ];

        foreach ($products as $product) {
            Product::firstOrCreate(['code' => $product['code']], $product);
        }

        $paracetamol = Product::where('code', 'MED-001')->first();
        $amoxicilina = Product::where('code', 'MED-002')->first();
        $diazepam = Product::where('code', 'MED-005')->first();

        // Lotes para cada producto (2-3 lotes cada uno)
        $today = now();
        $yesterday = now()->subDay();
        $nextMonth = now()->addMonth();
        $twoMonths = now()->addMonths(2);
        $vencido = now()->subMonths(6);
        $venceEn30Dias = now()->addDays(30);

        // Paracetamol - 3 lotes
        InventoryLot::firstOrCreate(
            ['product_id' => $paracetamol->id, 'warehouse_id' => $central->id, 'lot_number' => 'PAC-2024-001'],
            [
                'quantity' => 100,
                'expires_at' => $twoMonths,
            ]
        );
        InventoryLot::firstOrCreate(
            ['product_id' => $paracetamol->id, 'warehouse_id' => $central->id, 'lot_number' => 'PAC-2024-002'],
            [
                'quantity' => 50,
                'expires_at' => $nextMonth,
            ]
        );
        InventoryLot::firstOrCreate(
            ['product_id' => $paracetamol->id, 'warehouse_id' => $central->id, 'lot_number' => 'PAC-2024-003'],
            [
                'quantity' => 30,
                'expires_at' => $vencido,
            ]
        );

        // Amoxicilina - 2 lotes
        InventoryLot::firstOrCreate(
            ['product_id' => $amoxicilina->id, 'warehouse_id' => $central->id, 'lot_number' => 'AMX-2024-001'],
            [
                'quantity' => 80,
                'expires_at' => $twoMonths,
            ]
        );
        InventoryLot::firstOrCreate(
            ['product_id' => $amoxicilina->id, 'warehouse_id' => $central->id, 'lot_number' => 'AMX-2024-002'],
            [
                'quantity' => 40,
                'expires_at' => $venceEn30Dias,
            ]
        );

        // Diazepam (control especial) - 2 lotes
        InventoryLot::firstOrCreate(
            ['product_id' => $diazepam->id, 'warehouse_id' => $central->id, 'lot_number' => 'DIA-2024-001'],
            [
                'quantity' => 20,
                'expires_at' => $twoMonths,
            ]
        );
        InventoryLot::firstOrCreate(
            ['product_id' => $diazepam->id, 'warehouse_id' => $central->id, 'lot_number' => 'DIA-2024-002'],
            [
                'quantity' => 15,
                'expires_at' => $nextMonth,
            ]
        );

        // Lotes en otras bodegas
        InventoryLot::firstOrCreate(
            ['product_id' => $paracetamol->id, 'warehouse_id' => $urgencias->id, 'lot_number' => 'PAC-URG-001'],
            [
                'quantity' => 60,
                'expires_at' => $twoMonths,
            ]
        );
        InventoryLot::firstOrCreate(
            ['product_id' => $paracetamol->id, 'warehouse_id' => $hospitalizacion->id, 'lot_number' => 'PAC-HOSP-001'],
            [
                'quantity' => 150,
                'expires_at' => $twoMonths,
            ]
        );

        // 3 Pacientes sintéticos
        $patients = [
            [
                'name' => 'María García López',
                'document_ref' => '1234567890',
            ],
            [
                'name' => 'Carlos Rodríguez Mendoza',
                'document_ref' => '0987654321',
            ],
            [
                'name' => 'Ana María Torres',
                'document_ref' => '1122334455',
            ],
        ];

        foreach ($patients as $patient) {
            Patient::firstOrCreate(['document_ref' => $patient['document_ref']], $patient);
        }

        $maria = Patient::where('document_ref', '1234567890')->first();
        $carlos = Patient::where('document_ref', '0987654321')->first();
        $ana = Patient::where('document_ref', '1122334455')->first();

        // Prescripciones vigentes
        $validFrom = now()->subDays(5);
        $validUntil = now()->addDays(5);

        // Prescripción 1: María - Paracetamol y Amoxicilina
        $prescripcion1 = Prescription::firstOrCreate(
            ['patient_id' => $maria->id, 'prescribed_by' => 4], // Dr. Juan Pérez
            [
                'status' => 'active',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]
        );

        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $prescripcion1->id, 'product_id' => $paracetamol->id],
            [
                'quantity_prescribed' => 20,
            ]
        );
        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $prescripcion1->id, 'product_id' => $amoxicilina->id],
            [
                'quantity_prescribed' => 30,
            ]
        );

        // Prescripción 2: Carlos - Paracetamol
        $prescripcion2 = Prescription::firstOrCreate(
            ['patient_id' => $carlos->id, 'prescribed_by' => 4],
            [
                'status' => 'active',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]
        );

        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $prescripcion2->id, 'product_id' => $paracetamol->id],
            [
                'quantity_prescribed' => 15,
            ]
        );

        // Prescripción 3: Ana - Diazepam (control especial)
        $prescripcion3 = Prescription::firstOrCreate(
            ['patient_id' => $ana->id, 'prescribed_by' => 4],
            [
                'status' => 'active',
                'valid_from' => $validFrom,
                'valid_until' => $validUntil,
            ]
        );

        PrescriptionItem::firstOrCreate(
            ['prescription_id' => $prescripcion3->id, 'product_id' => $diazepam->id],
            [
                'quantity_prescribed' => 10,
            ]
        );

        // Movimientos iniciales de entrada para cada lote existente (RN-06)
        $admin = User::where('role', 'admin')->first();
        foreach (InventoryLot::all() as $lot) {
            \App\Models\StockMovement::firstOrCreate(
                [
                    'inventory_lot_id' => $lot->id,
                    'type' => 'entry',
                    'reference_type' => 'initial_seed',
                    'reference_id' => $lot->id,
                ],
                [
                    'user_id' => $admin?->id ?? 1,
                    'quantity_delta' => $lot->quantity,
                    'reason' => 'Inventario inicial del sistema',
                ]
            );
        }

        // Niveles de stock mínimo por bodega y producto (RN-11)
        foreach (Warehouse::all() as $wh) {
            foreach (Product::all() as $prod) {
                \App\Models\WarehouseStockLevel::firstOrCreate(
                    [
                        'warehouse_id' => $wh->id,
                        'product_id' => $prod->id,
                    ],
                    [
                        'minimum_stock' => $prod->controlled ? 15 : 25,
                    ]
                );
            }
        }
    }
}
