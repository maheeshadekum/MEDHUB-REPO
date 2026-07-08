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
        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $request->validate([
                'name' => 'required|string|unique:roles,name',
            ]);

            // create new role
            $role = new Role([
                'name' => $request->name,
            ]);
            $role->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Role created successfully', 'role' => $role], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
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
        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $request->validate([
                'name' => 'required|string|unique:roles,name,' . $role->id,
            ]);

            $role->name = $request->name;
            $role->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Role updated successfully', 'role' => $role], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // delete role
    public function deleteRole(Role $role)
    {
        $systemRoles = [
            'super_admin',
            'hospital_admin',
            'doctor',
            'pharmacist',
            'receptionist',
            'patient',
        ];

        if (in_array($role->name, $systemRoles, true)) {
            return response()->json([
                'message' => 'System roles cannot be deleted',
            ], 400);
        }

        if ($role->users()->exists()) {
            return response()->json([
                'message' => 'Cannot delete a role that is assigned to users',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $role->delete();

            DB::commit();

            return response()->json(null, 204);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
