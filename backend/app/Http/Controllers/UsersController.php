<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use App\Mail\AccountCreatedMail;
use App\Models\User;
use App\Models\Role;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    private const STAFF_ROLES = [
        'hospital_admin',
        'doctor',
        'pharmacist',
        'receptionist',
    ];

    private const PEOPLE_ROLES = [
        'super_admin',
        ...self::STAFF_ROLES,
    ];

    private const USER_STATUSES = ['working', 'retired', 'banned'];

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
            $user->role = $role ? $role->name : null;
            $user->hospital_id = $hospital?->id;
            $user->hospital = $hospital ? $hospital->name : null;
            return $user;
        });
        return response()->json($users);
    }

    // Show user by ID
    public function getUser(User $user)
    {
        $user->load(['role:id,name', 'hospitals:id,name']);

        $role = $user->role;
        $hospital = $user->hospitals;
        unset($user->role);
        $user->role = $role ? $role->name : null;
        $user->hospital_id = $hospital?->id;
        $user->hospital = $hospital?->name;

        return response()->json($user);
    }

    // create user
    public function createUser(Request $request)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can create staff accounts'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(self::PEOPLE_ROLES)],
            'hospital_id' => [
                Rule::requiredIf(fn() => in_array($request->input('role'), self::STAFF_ROLES, true)),
                Rule::prohibitedIf(fn() => $request->input('role') === 'super_admin'),
                'nullable',
                'integer',
                'min:1',
                'exists:hospitals,id',
            ],
            'status' => ['sometimes', Rule::in(self::USER_STATUSES)],
        ]);

        try {
            $user = DB::transaction(function () use ($validated) {
                $role = Role::where('name', $validated['role'])->firstOrFail();

                return User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => $validated['password'],
                    'role_id' => $role->id,
                    'hospital_id' => $validated['role'] === 'super_admin'
                        ? null
                        : $validated['hospital_id'],
                    'status' => $validated['status'] ?? 'working',
                ]);
            });

            // Send account created email
            Mail::to($user->email)->send(new AccountCreatedMail($user->email, $validated['password'], env('FRONTEND_URL') . '/login'));

            $user = $this->presentUser($user);

            return response()->json(['message' => 'User registered successfully', 'user' => $user], 201);
        } catch (Exception $e) {
            return response()->json([
                'message' => 'Error creating user',
            ], 500);
        }
    }

    // update user by ID
    public function updateUser(Request $request, User $user)
    {
        $user->loadMissing('role');
        if ($user->hasRole('patient')) {
            return response()->json(['message' => 'Patient accounts must be managed through the Patient workflow'], 403);
        }

        if (!$this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'You are not authorized to edit this user'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', 'string', Rule::in(self::PEOPLE_ROLES)],
            'hospital_id' => [
                Rule::requiredIf(fn() => in_array($request->input('role'), self::STAFF_ROLES, true)),
                Rule::prohibitedIf(fn() => $request->input('role') === 'super_admin'),
                'nullable',
                'integer',
                'min:1',
                'exists:hospitals,id',
            ],
            'status' => ['required', Rule::in(self::USER_STATUSES)],
        ]);

        if ($request->user()->hasRole('hospital_admin') && (
            !in_array($validated['role'], self::STAFF_ROLES, true)
            || $validated['hospital_id'] !== $request->user()->hospital_id
        )) {
            return response()->json(['message' => 'Hospital admins can edit only staff in their assigned hospital'], 403);
        }

        if ($request->user()->is($user) && (
            $validated['role'] !== 'super_admin'
            || $validated['status'] !== 'working'
        )) {
            return response()->json(['message' => 'You cannot demote, ban, or retire your own account'], 403);
        }

        if ($this->wouldRemoveLastWorkingSuperAdmin($user, $validated['role'], $validated['status'])) {
            return response()->json(['message' => 'The system must retain at least one working super admin'], 403);
        }

        try {
            DB::transaction(function () use ($validated, $user) {
                $role = Role::where('name', $validated['role'])->firstOrFail();
                $user->update([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'role_id' => $role->id,
                    'hospital_id' => $validated['role'] === 'super_admin'
                        ? null
                        : $validated['hospital_id'],
                    'status' => $validated['status'],
                ]);
            });

            return response()->json([
                'message' => 'User updated successfully',
                'user' => $this->presentUser($user->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error updating user'], 500);
        }
    }

    // update user status
    public function updateStatus(Request $request, User $user)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::USER_STATUSES)],
        ]);

        $user->loadMissing('role');
        if (!$this->canManageUser($request->user(), $user)) {
            return response()->json(['message' => 'You are not authorized to change this user status'], 403);
        }

        if ($request->user()->is($user) && $validated['status'] !== 'working') {
            return response()->json(['message' => 'You cannot ban or retire your own account'], 403);
        }

        if ($this->wouldRemoveLastWorkingSuperAdmin($user, $user->role->name, $validated['status'])) {
            return response()->json(['message' => 'The system must retain at least one working super admin'], 403);
        }

        try {
            $user->update(['status' => $validated['status']]);

            return response()->json([
                'message' => 'User status updated successfully',
                'user' => $this->presentUser($user->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error updating user status'], 500);
        }
    }

    // assign hospital to user
    public function assignHospital(Request $request, User $user)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can change a user hospital'], 403);
        }

        $user->loadMissing('role');
        if (!in_array($user->role?->name, self::STAFF_ROLES, true)) {
            return response()->json(['message' => 'Only hospital-scoped staff can be assigned to a hospital'], 403);
        }

        $validated = $request->validate([
            'hospital_id' => ['required', 'integer', 'min:1', 'exists:hospitals,id'],
        ]);

        try {
            $user->update(['hospital_id' => $validated['hospital_id']]);

            return response()->json([
                'message' => 'Hospital assigned to user successfully',
                'user' => $this->presentUser($user->fresh()),
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error assigning hospital'], 500);
        }
    }

    private function wouldRemoveLastWorkingSuperAdmin(User $user, string $newRole, string $newStatus): bool
    {
        if (!$user->hasRole('super_admin') || $user->status !== 'working') {
            return false;
        }

        if ($newRole === 'super_admin' && $newStatus === 'working') {
            return false;
        }

        return User::where('status', 'working')
            ->whereHas('role', fn($query) => $query->where('name', 'super_admin'))
            ->count() <= 1;
    }

    private function canManageUser(?User $actor, User $target): bool
    {
        if (!$actor) {
            return false;
        }

        if ($actor->hasRole('super_admin')) {
            return true;
        }

        return $actor->hasRole('hospital_admin')
            && $actor->hospital_id
            && $target->hospital_id === $actor->hospital_id
            && in_array($target->role?->name, self::STAFF_ROLES, true);
    }

    private function presentUser(User $user): User
    {
        $user->load(['role:id,name', 'hospitals:id,name']);
        $role = $user->role;
        $hospital = $user->hospitals;
        unset($user->role);
        $user->role = $role?->name;
        $user->hospital_id = $hospital?->id;
        $user->hospital = $hospital?->name;

        return $user;
    }

    // delete user by ID
    public function deleteUser(Request $request, User $user)
    {
        if (!$request->user()?->hasRole('super_admin')) {
            return response()->json(['message' => 'Only a super admin can delete users'], 403);
        }

        if ($request->user()->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account',
            ], 403);
        }

        if ($user->role && $user->role->name === 'super_admin') {
            return response()->json([
                'message' => 'Super admin accounts cannot be deleted',
            ], 403);
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
