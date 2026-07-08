<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Permission;

class PermissionPolicy
{
    /**
     * Determine if the user can view any permissions.
     */
    public function viewAny(User $user)
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can view the permission.
     */
    public function view(User $user, Permission $permission)
    {
        return $user->hasRole('super_admin') || $user->hasPermission($permission->name);
    }

    /**
     * Determine if the user can create permissions.
     */
    public function create(User $user)
    {
        return $user->hasRole('super_admin');
    }

    /**
     * Determine if the user can update the permission.
     */
    public function update(User $user, Permission $permission)
    {
        return $user->hasRole('super_admin') || $user->hasPermission($permission->name);
    }

    /**
     * Determine if the user can delete the permission.
     */
    public function delete(User $user, Permission $permission)
    {
        return $user->hasRole('super_admin') || $user->hasPermission($permission->name);
    }
}
