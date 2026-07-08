<?php

namespace App\Http\Controllers;

use App\Models\Medicines;
use App\Models\Inventories;
use App\Models\InventoryBatch;
use App\Models\Prescriptions;
use App\Mail\MedicineReleaseMail;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MedicinesController extends Controller
{
    // Add medicines : multiple medicines can be added at once with automatic inventory deduction
    public function addMedicines(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // get doctor from request auth
            $doctor = $request->user();

            $request->validate([
                'medicines' => 'required|array',
                'medicines.*.name' => 'nullable|string|max:255',
                'medicines.*.dosage' => 'required|integer|min:1',
                'medicines.*.days_supply' => 'required|integer|min:1',
                'medicines.*.is_external' => 'boolean',
                'medicines.*.name_of_external_medicine' => 'nullable|string|max:255',
                'medicines.*.frequency' => 'nullable|array',
                'medicines.*.frequency.morning' => 'boolean',
                'medicines.*.frequency.afternoon' => 'boolean',
                'medicines.*.frequency.night' => 'boolean',
                'medicines.*.frequency.if_needed' => 'boolean',
                'medicines.*.duration' => 'nullable|string|max:255',
                'prescription_id' => 'required|exists:prescriptions,id',
            ]);

            // check hospital id for the doctor
            if (!$doctor->hospital_id) {
                return response()->json(['message' => 'Doctor does not belong to any hospital'], 403);
            }

            // check if the prescription's hospital matches the doctor's hospital
            $prescription = Prescriptions::where('id', $request->input('prescription_id'))
                ->where('hospital_id', $doctor->hospital_id)
                ->first();

            if (!$prescription) {
                return response()->json(['message' => 'Prescription not found or does not belong to your hospital'], 404);
            }

            $medicines = [];

            foreach ($request->input('medicines') as $medicineData) {
                $drugId = null;
                $inventory = null;

                // Only process inventory for internal medicines (not external)
                if (!isset($medicineData['is_external']) || !$medicineData['is_external']) {
                    // Find matching inventory for this medicine
                    $inventory = Inventories::where('hospital_id', $doctor->hospital_id)
                        ->where(function ($q) use ($medicineData) {
                            $q->where('drug_name', 'like', '%' . $medicineData['name'] . '%')
                                ->orWhere('brand_name', 'like', '%' . $medicineData['name'] . '%');
                        })
                        ->first();

                    if (!$inventory) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Medicine '{$medicineData['name']}' is not available in inventory. Consider marking it as external medicine."
                        ], 400);
                    }

                    $drugId = $inventory->id;

                    // Calculate frequency multiplier based on frequency object
                    $frequencyMultiplier = 1;
                    if (isset($medicineData['frequency'])) {
                        $frequency = $medicineData['frequency'];

                        if (isset($frequency['if_needed']) && $frequency['if_needed']) {
                            // If if_needed is true, multiply by 4 and ignore other frequency settings
                            $frequencyMultiplier = 4;
                        } else {
                            // Count how many times per day (morning, afternoon, night)
                            $timesPerDay = 0;
                            if (isset($frequency['morning']) && $frequency['morning']) $timesPerDay++;
                            if (isset($frequency['afternoon']) && $frequency['afternoon']) $timesPerDay++;
                            if (isset($frequency['night']) && $frequency['night']) $timesPerDay++;

                            $frequencyMultiplier = max(1, $timesPerDay); // At least 1 if no specific times set
                        }
                    }

                    // Calculate total quantity needed (dosage * days_supply * frequency_multiplier)
                    $totalQuantityNeeded = $medicineData['dosage'] * $medicineData['days_supply'] * $frequencyMultiplier;

                    // Get available batches ordered by expiry date (nearest expiry first)
                    $availableBatches = InventoryBatch::where('inventory_id', $inventory->id)
                        ->where('quantity', '>', 0)
                        ->orderBy('expiry_date', 'asc')
                        ->get();

                    $totalAvailable = $availableBatches->sum('quantity');

                    if ($totalAvailable < $totalQuantityNeeded) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Insufficient quantity for '{$medicineData['name']}'. Available: {$totalAvailable}, Required: {$totalQuantityNeeded} (dosage: {$medicineData['dosage']} × days: {$medicineData['days_supply']} × frequency: {$frequencyMultiplier})"
                        ], 400);
                    }

                    // Deduct quantities from multiple batches if needed (nearest expiry first)
                    $remainingQuantity = $totalQuantityNeeded;
                    foreach ($availableBatches as $batch) {
                        if ($remainingQuantity <= 0) break;

                        $deductQuantity = min($batch->quantity, $remainingQuantity);

                        // Update batch quantity
                        $batch->quantity -= $deductQuantity;
                        $batch->save();

                        $remainingQuantity -= $deductQuantity;
                    }
                }

                // Create medicine record
                $medicine = Medicines::create([
                    'name' => $medicineData['name'],
                    'prescription_id' => $request->input('prescription_id'),
                    'drug_id' => $drugId,
                    'is_external' => $medicineData['is_external'] ?? false,
                    'dosage' => $medicineData['dosage'],
                    'days_supply' => $medicineData['days_supply'],
                    'name_of_external_medicine' => $medicineData['name_of_external_medicine'] ?? null,
                    'frequency' => isset($medicineData['frequency']) ? json_encode($medicineData['frequency']) : null,
                    'duration' => $medicineData['duration'] ?? null
                ]);

                $medicines[] = $medicine;
            }

            // update prescription's doctor_id and status to 'prescribed'
            Prescriptions::where('id', $request->input('prescription_id'))
                ->update([
                    'doctor_id' => $doctor->id,
                    'status' => 'prescribed',
                ]);

            // commit transaction
            DB::commit();

            return response()->json([
                'message' => 'Medicines added successfully and inventory updated',
                'medicines' => $medicines,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error adding medicines', 'error' => $e->getMessage()], 500);
        }
    }

    // Release medicine : release all prescribed medicines and update prescription status.
    public function release(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate request
            $request->validate([
                'prescription_id' => 'required|exists:prescriptions,id',
            ]);

            $prescriptionId = $request->input('prescription_id');

            // Get user id
            $userId = $request->user()->id;

            // Find all prescribed medicines
            $medicines = Medicines::where('prescription_id', $prescriptionId)->get();

            if ($medicines->isEmpty()) {
                return response()->json(['message' => 'No prescribed medicines found'], 404);
            }

            // Update prescription status
            Prescriptions::where('id', $prescriptionId)
                ->update(['status' => 'dispensed', 'pharmacist_id' => $userId]);

            // Get prescription data with all relationships for email
            $prescription = Prescriptions::with([
                'patient:id,user_id,name,nic',
                'patient.user:id,name,email',
                'doctor:id,name',
                'pharmacist:id,name',
                'hospital:id,name,address,phone,email',
                'medicines:id,name,dosage,frequency,days_supply,is_external,name_of_external_medicine,duration,prescription_id'
            ])->find($prescriptionId);

            // Send email if patient has user account with email
            if ($prescription && $prescription->patient && $prescription->patient->user_id) {
                $patient = $prescription->patient;
                $user = $patient->user;
                $hospital = $prescription->hospital;

                if ($user && $user->email) {
                    try {
                        Mail::to($user->email)->send(new MedicineReleaseMail($prescription, $patient, $hospital));
                        Log::info('Medicine release email sent successfully', [
                            'prescription_id' => $prescriptionId,
                            'patient_email' => $user->email,
                            'patient_name' => $patient->name
                        ]);
                    } catch (Exception $e) {
                        // Log email error but don't fail the medicine release
                        Log::error('Failed to send medicine release email', [
                            'prescription_id' => $prescriptionId,
                            'patient_email' => $user->email,
                            'error' => $e->getMessage()
                        ]);
                    }
                } else {
                    Log::info('Medicine release email not sent - patient has no email', [
                        'prescription_id' => $prescriptionId,
                        'patient_name' => $patient->name
                    ]);
                }
            }

            // Commit transaction
            DB::commit();

            $response = ['message' => 'Medicines dispensed successfully'];

            // Add email status to response
            if (isset($user) && $user->email) {
                $response['email_sent'] = true;
                $response['email_recipient'] = $user->email;
            } else {
                $response['email_sent'] = false;
                $response['note'] = 'Email notification not sent - patient has no registered email address';
            }

            return response()->json($response, 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Medicines dispensed failed', 'error' => $e->getMessage()], 500);
        }
    }
}
