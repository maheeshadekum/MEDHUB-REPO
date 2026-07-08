<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Prescriptions extends Model
{
    /** @use HasFactory<\Database\Factories\PrescriptionsFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'patient_id', // Foreign key to patients table
        'doctor_id', // Foreign key to users table (doctors)
        'hospital_id', // Foreign key to hospitals table
        'pharmacist_id', // Foreign key to users table (pharmacists)
        'date', // Date of the prescription
        'description', // Description of the prescription
        'status', // Status of the prescription (draft, prescribed, dispensed)
        'token_type', // Type of token (e.g., OPD, Clinic)
        'opd_token_id', // Foreign key to opd_tokens table
        'clinic_token_id', // Foreign key to clinic_tokens table
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
        'date' => 'datetime',
    ];

    /**
     * Get the patient that owns the prescription.
     */
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * Get the doctor that created the prescription.
     */
    public function doctor()
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /**
     * Get the hospital where the prescription was created.
     */
    public function hospital()
    {
        return $this->belongsTo(Hospital::class);
    }

    /**
     * Get the pharmacist who dispensed the prescription.
     */
    public function pharmacist()
    {
        return $this->belongsTo(User::class, 'pharmacist_id');
    }

    /**
     * Get the OPD token associated with the prescription.
     */
    public function opdToken()
    {
        return $this->belongsTo(OpdToken::class);
    }

    /**
     * Get the clinic token associated with the prescription.
     */
    public function clinicToken()
    {
        return $this->belongsTo(ClinicToken::class);
    }

    /**
     * Get the medicines associated with the prescription.
     */
    public function medicines()
    {
        return $this->hasMany(Medicines::class, 'prescription_id');
    }
}
