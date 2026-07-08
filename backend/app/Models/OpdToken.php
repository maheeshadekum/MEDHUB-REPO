<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpdToken extends Model
{
    /** @use HasFactory<\Database\Factories\OpdTokenFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'patient_id',
        'opd_date_id',
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
     * Get the patient that owns the OPD token.
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the OPD date that owns the OPD token.
     */
    public function opdDate(): BelongsTo
    {
        return $this->belongsTo(OpdDate::class);
    }

    /**
     * Get the prescriptions associated with the OPD token.
     */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescriptions::class, 'opd_token_id');
    }
}
