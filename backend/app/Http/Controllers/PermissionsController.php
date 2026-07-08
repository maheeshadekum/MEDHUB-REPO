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
        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $request->validate([
                'name' => 'required|string|unique:permissions,name',
                'description' => 'required|string|max:255',
            ]);

            // create new permission
            $permission = new Permission([
                'name' => $request->name,
                'description' => $request->description,
            ]);
            $permission->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Permission created successfully', 'permission' => $permission], 201);
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

    // update permission
    public function updatePermission(Request $request, $id)
    {
        // start transaction
        DB::beginTransaction();

        try {
            // validate request data
            $request->validate([
                'name' => 'required|string|unique:permissions,name,' . $id,
                'description' => 'required|string|max:255',
            ]);

            // find permission by id
            $permission = Permission::findOrFail($id);
            $permission->name = $request->name;
            $permission->description = $request->description;
            $permission->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Permission updated successfully', 'permission' => $permission], 200);
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
}
