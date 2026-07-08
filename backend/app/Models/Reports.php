<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reports extends Model
{
    /** @use HasFactory<\Database\Factories\ReportsFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
       'prescription_id', // Foreign key to prescriptions table
       'report_type', // Type of report (e.g., blood test, X-ray)
       'file_path', // Path to the report file (if applicable)
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
}
