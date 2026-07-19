<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RolesController extends Controller
{
    // get all roles
    public function getRoles(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        if ($search) {
            // search roles by name or permissions
            $roles = Role::where('name', 'like', '%' . $search . '%')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        } else {
            // get all roles with permissions
            $roles = Role::orderBy('updated_at', 'desc')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        }

        return response()->json($roles);
    }

    // create new role
    public function createRole(Request $request)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can create roles'], 403);
        }

        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
            ]);

            $this->validateAvailableCustomName($validated['name']);

            // create new role
            $role = new Role([
                'name' => $validated['name'],
            ]);
            $role->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Role created successfully', 'role' => $role], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['message' => 'Unable to create role'], 500);
        }
    }

    // get role by ID
    public function getRole(Role $role)
    {
        return response()->json($role);
    }

    // update role
    public function updateRole(Request $request, Role $role)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can update roles'], 403);
        }

        if ($role->isSystem()) {
            return response()->json(['message' => 'System roles cannot be renamed'], 422);
        }

        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
            ]);

            $this->validateAvailableCustomName($validated['name'], $role);

            $role->name = $validated['name'];
            $role->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Role updated successfully', 'role' => $role], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['message' => 'Unable to update role'], 500);
        }
    }

    // delete role
    public function deleteRole(Request $request, Role $role)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can delete roles'], 403);
        }

        if ($role->isSystem()) {
            return response()->json([
                'message' => 'System roles cannot be deleted',
            ], 422);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a role that is assigned to users',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $role->delete();

            DB::commit();

            return response()->json(null, 204);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['message' => 'Unable to delete role'], 500);
        }
    }

    private function validateAvailableCustomName(string $name, ?Role $currentRole = null): void
    {
        if (Role::isSystemName($name)) {
            throw ValidationException::withMessages([
                'name' => ['System role names are reserved'],
            ]);
        }

        $query = Role::whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($currentRole) {
            $query->whereKeyNot($currentRole->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['The role name has already been taken'],
            ]);
        }
    }
}
