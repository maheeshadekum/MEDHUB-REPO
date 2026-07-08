<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OpdDate extends Model
{
    /** @use HasFactory<\Database\Factories\OpdDateFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'hospital_id',
        'date',
        'start_time',
        'end_time',
        'status',
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
            'date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'total_hourly_tokens' => 'integer',
            'self_hourly_tokens' => 'integer',
        ];
    }

    /**
     * Get the hospital that owns the OPD date.
     */
    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * Get the OPD tokens for the OPD date.
     */
    public function opdTokens(): HasMany
    {
        return $this->hasMany(OpdToken::class);
    }
}
