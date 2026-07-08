<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\AccountCreatedMail;
use App\Models\User;
use App\Models\Role;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UsersController extends Controller
{
    public function users(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $role = $request->input('role', null);

        if ($search) {
            // if requested user's role is hospital_admin, filter users by hospital
            if ($request->user()->role->name === 'hospital_admin') {
                $hospitalId = $request->user()->hospitals()->first()->id;
                $users = User::with('role:id,name')
                    ->with('hospitals:id,name')
                    ->with('role:id,name')
                    ->whereHas('hospitals', function ($query) use ($hospitalId) {
                        $query->where('id', $hospitalId);
                    })
                    ->whereHas('role', function ($query) use ($role) {
                        if ($role) {
                            $query->where('name', $role);
                        }
                    })
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhereHas('role', function ($query) use ($search) {
                                $query->where('name', 'like', '%' . $search . '%');
                            });
                    })
                    ->paginate($pageSize, ['*'], 'page', $pageNumber);
            } else {
                $users = User::with('role:id,name')
                    ->with('hospitals:id,name')
                    ->where(function ($query) use ($search) {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%')
                            ->orWhereHas('role', function ($query) use ($search) {
                                $query->where('name', 'like', '%' . $search . '%');
                            });
                    })
                    ->orderBy('updated_at', 'desc')
                    ->paginate($pageSize, ['*'], 'page', $pageNumber);
            }
        } else {
            if ($request->user()->role->name === 'hospital_admin') {
                $hospitalId = $request->user()->hospitals()->first()->id;
                $users = User::with('role:id,name')
                    ->with('hospitals:id,name')
                    ->with('role:id,name')
                    ->whereHas('hospitals', function ($query) use ($hospitalId) {
                        $query->where('id', $hospitalId);
                    })
                    ->whereHas('role', function ($query) use ($role) {
                        if ($role) {
                            $query->where('name', $role);
                        }
                    })
                    ->orderBy('updated_at', 'desc')
                    ->paginate($pageSize, ['*'], 'page', $pageNumber);
            } else {
                $users = User::with('role:id,name')
                    ->with('hospitals:id,name')
                    ->orderBy('updated_at', 'desc')
                    ->paginate($pageSize, ['*'], 'page', $pageNumber);
            }
        }

        // remove role object from each user and add role name
        $users->getCollection()->transform(function ($user) {
            $role = $user->role;
            $hospital = $user->hospitals;
            unset($user->role);
            unset($user->hospital);
            $user->role = $role ? $role->name : null;
            $user->hospital = $hospital ? $hospital->name : null;
            return $user;
        });
        return response()->json($users);
    }

    // Show user by ID
    public function getUser(User $user)
    {
        $user->load('role:id,name');

        // remove role object from each user and add role name
        $role = $user->role;
        unset($user->role);
        $user->role = $role ? $role->name : null;

        return response()->json($user);
    }

    // create user
    public function createUser(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // get available roles name from roles table
            $roles = Role::all();

            // create role names array without 'patient'
            $roleNames = $roles->pluck('name')->reject(fn($name) => $name === 'patient');

            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255',
                'password' => 'required|string|min:8|confirmed',
                'role' => 'required|string|in:' . $roleNames->implode(','),
            ]);

            // check email already exists
            if (User::where('email', $request->email)->exists()) {
                return response()->json(['message' => 'Email already exists'], 400);
            }

            $user = new User([
                'name' => $request->name,
                'email' => $request->email,
                'password' => bcrypt($request->password),
                'role_id' => Role::where('name', $request->role)->first()->id,
            ]);

            $user->save();

            // commit transaction
            DB::commit();

            // Send account created email
            Mail::to($user->email)->send(new AccountCreatedMail($user->email, $request->password, env('FRONTEND_URL') . '/login'));

            return response()->json(['message' => 'User registered successfully'], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error creating user',
            ], 500);
        }
    }

    // update user by ID
    public function updateUser(Request $request, User $user)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate request data
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
                'role' => 'required|exists:roles,name',
            ]);

            // update user data
            $user->name = $request->name;
            $user->email = $request->email;
            $user->role_id = Role::where('name', $request->role)->first()->id;
            $user->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'User updated successfully', 'user' => $user], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json(['error' => $e->validator->errors()->first()], 400);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // update user status
    public function updateStatus(Request $request, User $user)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate request data
            $request->validate([
                'status' => 'required|in:working,retired,banned',
            ]);

            $user->status = $request->status;
            $user->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'User status updated successfully', 'user' => $user], 200);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // assign hospital to user
    public function assignHospital(Request $request, User $user)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate request data
            $request->validate([
                'hospital_id' => 'required|exists:hospitals,id',
            ]);

            $user->hospital_id = $request->hospital_id;
            $user->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Hospital assigned to user successfully', 'user' => $user], 200);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // delete user by ID
    public function deleteUser(Request $request, User $user)
    {
        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account',
            ], 400);
        }

        if ($user->role && $user->role->name === 'super_admin') {
            $superAdminCount = User::whereHas('role', function ($query) {
                $query->where('name', 'super_admin');
            })->count();

            if ($superAdminCount <= 1) {
                return response()->json([
                    'message' => 'Cannot delete the last super admin',
                ], 400);
            }
        }

        try {
            DB::beginTransaction();

            $user->delete();

            DB::commit();

            return response()->json(null, 204);
        } catch (Exception $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
