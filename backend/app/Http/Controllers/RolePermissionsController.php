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
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can assign permissions'], 403);
        }

        $permission = Permission::findOrFail($id);

        try {
            // Validate request data
            $validated = $request->validate([
                'permission_ids' => 'required|array',
                'permission_ids.*' => 'integer|exists:roles,id',
            ]);

            $roleIds = array_values(array_unique($validated['permission_ids']));

            if ($permission->isProtectedAdministrative()) {
                $superAdminRoleId = Role::where('name', 'super_admin')->value('id');

                if (!$superAdminRoleId || !in_array($superAdminRoleId, $roleIds, true)) {
                    throw ValidationException::withMessages([
                        'permission_ids' => ['The super admin role must retain this protected permission'],
                    ]);
                }
            }

            DB::transaction(function () use ($permission, $roleIds) {
                $permission->roles()->sync($roleIds);
            });

            return response()->json($permission->roles()->get());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error assigning permissions'], 500);
        }
    }
}
