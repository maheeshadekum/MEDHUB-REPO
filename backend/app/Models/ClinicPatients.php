<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClinicPatients extends Model
{
    /** @use HasFactory<\Database\Factories\ClinicPatientsFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'patient_id',
    ];

    public function clinic()
    {
        return $this->belongsTo(Clinic::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }
}
