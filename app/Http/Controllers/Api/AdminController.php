<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{

    public function getUsers(): JsonResponse
    {
        $users = User::select('id', 'name', 'email', 'role', 'created_at')
            ->orderBy('id')
            ->get();

        return response()->json(['data' => $users]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', Rule::in(['admin', 'regente_farmacia', 'auxiliar_farmacia', 'medico', 'auditor'])],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'Usuario creado exitosamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
            ],
        ], 201);
    }

    public function updateUser(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:6'],
            'role' => ['required', 'string', Rule::in(['admin', 'regente_farmacia', 'auxiliar_farmacia', 'medico', 'auditor'])],
        ]);

        $data = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ];

        if (!empty($validated['password'])) {
            $data['password'] = Hash::make($validated['password']);
        }

        $user->update($data);

        return response()->json([
            'message' => 'Usuario actualizado exitosamente.',
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function deleteUser(User $user): JsonResponse
    {
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'No puedes eliminar tu propia cuenta.'], 422);
        }

        $user->delete();

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:products,code'],
            'name' => ['required', 'string', 'max:255'],
            'controlled' => ['required', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $product = Product::create([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'controlled' => $validated['controlled'],
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Producto creado exitosamente.',
            'data' => $product,
        ], 201);
    }

    public function updateProduct(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('products', 'code')->ignore($product->id)],
            'name' => ['required', 'string', 'max:255'],
            'controlled' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);

        $product->update([
            'code' => strtoupper($validated['code']),
            'name' => $validated['name'],
            'controlled' => $validated['controlled'],
            'active' => $validated['active'],
        ]);

        return response()->json([
            'message' => 'Producto actualizado exitosamente.',
            'data' => $product,
        ]);
    }

    public function deleteProduct(Product $product): JsonResponse
    {
        $product->update(['active' => false]);
        return response()->json(['message' => 'Producto desactivado exitosamente.']);
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:warehouses,name'],
            'active' => ['nullable', 'boolean'],
        ]);

        $warehouse = Warehouse::create([
            'name' => $validated['name'],
            'active' => $validated['active'] ?? true,
        ]);

        return response()->json([
            'message' => 'Bodega creada exitosamente.',
            'data' => $warehouse,
        ], 201);
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('warehouses', 'name')->ignore($warehouse->id)],
            'active' => ['required', 'boolean'],
        ]);

        $warehouse->update([
            'name' => $validated['name'],
            'active' => $validated['active'],
        ]);

        return response()->json([
            'message' => 'Bodega actualizada exitosamente.',
            'data' => $warehouse,
        ]);
    }

    public function deleteWarehouse(Warehouse $warehouse): JsonResponse
    {
        $warehouse->update(['active' => false]);
        return response()->json(['message' => 'Bodega desactivada exitosamente.']);
    }
}
