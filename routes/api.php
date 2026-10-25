<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ControlledMedicationAuthorizationController;
use App\Http\Controllers\Api\DispensationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PatientPrescriptionController;
use App\Http\Controllers\Api\WarehouseTransferController;
use Illuminate\Support\Facades\Route;


Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware('auth:sanctum');
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('auth:sanctum');


Route::middleware([
    'auth:sanctum',
    'role:auxiliar_farmacia,regente_farmacia,admin,auditor',
])->prefix('controlled-medication-authorizations')
    ->controller(ControlledMedicationAuthorizationController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('role:auxiliar_farmacia,regente_farmacia,admin');

        Route::post('/{authorization}/approve', 'approve')
            ->middleware('role:regente_farmacia,admin');
    });


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/patients/search', [PatientPrescriptionController::class, 'searchPatients']);
    Route::get('/patients/{patient}/prescriptions', [PatientPrescriptionController::class, 'getPatientPrescriptions']);
    Route::post('/dispensations/preview-fefo', [PatientPrescriptionController::class, 'previewFefo']);
    Route::get('/warehouses', [PatientPrescriptionController::class, 'getWarehouses']);
    Route::get('/products', [PatientPrescriptionController::class, 'getProducts']);

    Route::post('/prescriptions', [PatientPrescriptionController::class, 'storePrescription'])
        ->middleware('role:medico,admin');

    Route::get('/patient-access-logs', [PatientPrescriptionController::class, 'patientAccessLogs'])
        ->middleware('role:auditor,admin');
});

Route::middleware([
    'auth:sanctum',
    'role:auxiliar_farmacia,regente_farmacia,admin',
])->prefix('transfers')
    ->controller(WarehouseTransferController::class)
    ->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::get('/{transfer}', 'show');

        Route::post('/{transfer}/request-approval', 'requestApproval');

        Route::post('/{transfer}/approve', 'approve')
            ->middleware('role:regente_farmacia,admin');

        Route::post('/{transfer}/dispatch', 'dispatch');

        Route::post('/{transfer}/receive', 'receive')
            ->middleware('role:auxiliar_farmacia,regente_farmacia,admin');

        Route::post('/{transfer}/resolve-discrepancy', 'resolveDiscrepancy')
            ->middleware('role:regente_farmacia,admin');

        Route::post('/{transfer}/cancel', 'cancel');
    });


Route::middleware([
    'auth:sanctum',
    'role:auxiliar_farmacia,regente_farmacia,admin',
])->post('/dispensations', [
    DispensationController::class,
    'store',
]);



Route::middleware('auth:sanctum')->group(function () {
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/alerts', [InventoryController::class, 'alerts']);
    Route::get('/kardex', [InventoryController::class, 'kardex']);

    Route::post('/inventory/entries', [InventoryController::class, 'storeEntry'])
        ->middleware('role:auxiliar_farmacia,regente_farmacia,admin');

    Route::post('/inventory/adjustments', [InventoryController::class, 'storeAdjustment'])
        ->middleware('role:regente_farmacia,admin');

    Route::get('/inventory/stock-levels', [InventoryController::class, 'getStockLevels'])
        ->middleware('role:regente_farmacia,admin');
    Route::post('/inventory/stock-levels', [InventoryController::class, 'setStockLevel'])
        ->middleware('role:regente_farmacia,admin');
});



Route::middleware([
    'auth:sanctum',
    'role:admin',
])->prefix('admin')->controller(AdminController::class)->group(function () {
    Route::get('/users', 'getUsers');
    Route::post('/users', 'storeUser');
    Route::put('/users/{user}', 'updateUser');
    Route::delete('/users/{user}', 'deleteUser');

    Route::post('/products', 'storeProduct');
    Route::put('/products/{product}', 'updateProduct');
    Route::delete('/products/{product}', 'deleteProduct');

    Route::post('/warehouses', 'storeWarehouse');
    Route::put('/warehouses/{warehouse}', 'updateWarehouse');
    Route::delete('/warehouses/{warehouse}', 'deleteWarehouse');
});
