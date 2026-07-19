<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PermissionsController extends Controller
{
    // get all permissions
    public function getPermissions(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        if ($search) {
            // search permissions by name
            $permissions = Permission::where('name', 'like', '%' . $search . '%')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        } else {
            // get all permissions
            $permissions = Permission::orderBy('updated_at', 'desc')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        }

        return response()->json($permissions);
    }

    // create new permission
    public function createPermission(Request $request)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can create permissions'], 403);
        }

        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => 'required|string|max:255',
            ]);

            $this->validateAvailableName($validated['name']);

            // create new permission
            $permission = new Permission([
                'name' => $validated['name'],
                'description' => $validated['description'],
            ]);
            $permission->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Permission created successfully', 'permission' => $permission], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['message' => 'Unable to create permission'], 500);
        }
    }

    // update permission
    public function updatePermission(Request $request, $id)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can update permissions'], 403);
        }

        $permission = Permission::findOrFail($id);
        $request->merge(['name' => trim((string) $request->input('name', ''))]);

        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'description' => 'required|string|max:255',
            ]);

            if ($permission->isProtectedAdministrative() && $validated['name'] !== $permission->name) {
                throw ValidationException::withMessages([
                    'name' => ['Protected administrative permission names cannot be changed'],
                ]);
            }

            $this->validateAvailableName($validated['name'], $permission);

            $permission->name = $validated['name'];
            $permission->description = $validated['description'];
            $permission->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Permission updated successfully', 'permission' => $permission], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 422);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['message' => 'Unable to update permission'], 500);
        }
    }

    private function validateAvailableName(string $name, ?Permission $currentPermission = null): void
    {
        $query = Permission::whereRaw('LOWER(name) = ?', [strtolower($name)]);

        if ($currentPermission) {
            $query->whereKeyNot($currentPermission->id);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'name' => ['The permission name has already been taken'],
            ]);
        }
    }
}
