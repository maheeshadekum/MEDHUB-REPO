<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'hospital_id',
        'role_id',
        'reset_password_token',
        'reset_password_token_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'reset_password_token',
        'reset_password_token_expires_at'
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'reset_password_token_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function role()
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function hasRole($roles)
    {
        if (is_array($roles)) {
            return $this->role && in_array($this->role->name, $roles);
        }

        return $this->role && $this->role->name === $roles;
    }

    public function permissions()
    {
        return $this->role ? $this->role->permissions() : collect();
    }

    public function hasPermission($permissions)
    {
        if (is_array($permissions)) {
            return $this->permissions()->whereIn('name', $permissions)->exists();
        }

        return $this->permissions()->where('name', $permissions)->exists();
    }

    public function hospitals()
    {
        return $this->belongsTo(Hospital::class, 'hospital_id');
    }

    public function isResetTokenValid($token)
    {
        return $this->reset_password_token === $token
            && $this->reset_password_token_expires_at
            && $this->reset_password_token_expires_at->isFuture();
    }

    public function clearResetToken()
    {
        $this->update([
            'reset_password_token' => null,
            'reset_password_token_expires_at' => null
        ]);
    }
}
