<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hospital extends Model
{
    /** @use HasFactory<\Database\Factories\HospitalFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'identifier', // acts like username for hospital
        'address',
        'phone',
        'email',
        'district',
        'location_url',
        'location_lat',
        'location_lng',
        'is_inventory_activated',
        'is_appointment_activated',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_inventory_activated' => 'boolean',
            'is_appointment_activated' => 'boolean',
        ];
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    /**
     * Get the clinics for the hospital.
     */
    public function clinics(): HasMany
    {
        return $this->hasMany(Clinic::class);
    }

    /**
     * Get the OPD dates for the hospital.
     */
    public function opdDates(): HasMany
    {
        return $this->hasMany(OpdDate::class);
    }
}
