<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolePermissionsController extends Controller
{

    // get all permissions with roles
    public function getRolePermissions()
    {
        $permissions = RolePermission::with(['permission', 'role'])
            ->get()
            ->groupBy('permission.name');
        $permissions = $permissions->map(function ($items, $key) {
            $permission = $items->first()->permission;
            $roles = $items->pluck('role')->flatten()->unique('id');
            return [
                'name' => $key,
                'id' => $permission->id,
                'roles' => $roles->map(function ($role) {
                    return [
                        'id' => $role->id,
                        'name' => $role->name,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json($permissions);
    }

    // Assign permissions to a role
    public function assignPermission(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Validate request data
            $request->validate([
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'exists:permissions,id',
            ]);

            // get the permission by id
            $permission = Permission::find($id);

            // Check if permission exists
            if (!$permission) {
                return response()->json(['message' => 'Permission not found'], 404);
            }

            $permission->roles()->sync($request->permission_ids);

            DB::commit();

            return response()->json($permission->roles);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error assigning permissions', 'error' => $e->getMessage()], 500);
        }
    }
}
