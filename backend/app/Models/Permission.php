<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    public const PROTECTED_ADMIN_PERMISSION_NAMES = [
        'view_roles',
        'create_roles',
        'update_roles',
        'view_permissions',
        'create_permissions',
        'update_permissions',
        'view_role_permissions',
        'assign_permissions',
        'view_users',
        'create_users',
        'update_users',
    ];

    protected $fillable = [
        'name',
        'description',
    ];

    public function isProtectedAdministrative(): bool
    {
        return self::isProtectedAdministrativeName($this->name);
    }

    public static function isProtectedAdministrativeName(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::PROTECTED_ADMIN_PERMISSION_NAMES, true);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_permission');
    }
}
