<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clinic extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'hospital_id',
        'description',
        'doctor_id',
        'location',
        'total_hourly_tokens',
        'self_hourly_tokens',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_hourly_tokens' => 'integer',
            'self_hourly_tokens' => 'integer',
        ];
    }

    /**
     * Get the hospital that owns the clinic.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * Get the doctor (user) that owns the clinic.
     */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Get the clinic dates for the clinic.
     */
    public function clinicDates(): HasMany
    {
        return $this->hasMany(ClinicDate::class);
    }

    /**
     * Get the clinic tokens for the clinic.
     */
    public function clinicTokens(): HasMany
    {
        return $this->hasMany(ClinicToken::class);
    }

    /**
     * Get the patients associated with the clinic.
     */
    public function patients()
    {
        return $this->belongsToMany(Patient::class, 'clinic_patients', 'clinic_id', 'patient_id')
            ->withTimestamps();
    }
}
