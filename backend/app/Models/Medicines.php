<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medicines extends Model
{
    /** @use HasFactory<\Database\Factories\MedicinesFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'prescription_id',
        'drug_id',
        'is_external',
        'dosage',
        'days_supply',
        'name_of_external_medicine',
        'frequency',
        'duration'
    ];

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
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'frequency' => 'array',
        'is_external' => 'boolean',
        'dosage' => 'integer',
        'days_supply' => 'integer',
        'duration' => 'string',
    ];

    public function prescription()
    {
        return $this->belongsTo(Prescriptions::class, 'prescription_id');
    }

    public function drug()
    {
        return $this->belongsTo(Inventories::class, 'drug_id');
    }
}
