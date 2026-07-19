<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasFactory;

    public const SYSTEM_ROLE_NAMES = [
        'super_admin',
        'hospital_admin',
        'doctor',
        'pharmacist',
        'receptionist',
        'patient',
    ];

    protected $fillable = [
        'name',
    ];

    public function isSystem(): bool
    {
        return self::isSystemName($this->name);
    }

    public static function isSystemName(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::SYSTEM_ROLE_NAMES, true);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    public function users()
    {
        return $this->hasMany(User::class, 'role_id');
    }
}
