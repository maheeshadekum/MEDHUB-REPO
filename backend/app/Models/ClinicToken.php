<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClinicToken extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'clinic_id',
        'patient_id',
        'type',
        'token_number',
        'start_time',
        'end_time',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
        ];
    }

    /**
     * Get the clinic date that owns the clinic token.
     */
    public function clinicDate(): BelongsTo
    {
        return $this->belongsTo(ClinicDate::class, 'clinic_id');
    }

    /**
     * Get the patient that owns the clinic token.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the prescriptions associated with the clinic token.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescriptions::class, 'clinic_token_id');
    }
}
