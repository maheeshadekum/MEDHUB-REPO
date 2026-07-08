<?php

namespace App\Http\Controllers;

use App\Models\ClinicPatients;
use App\Models\Patient;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicPatientsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $clinicId = $request->input('clinic_id');

        $query = ClinicPatients::with(['clinic', 'patient', 'patient.user', 'clinic.hospital']);

        $role = $request->user()->role->name;

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('patient', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%')
                        ->whereHas('user', function ($userQuery) use ($search) {
                            $userQuery->where('email', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%');
                        });
                })->orWhereHas('clinic', function ($query) use ($search) {
                    $query->where('name', 'like', '%' . $search . '%');
                });
            });
        }

        if ($clinicId) {
            $query->where('clinic_id', $clinicId);
        }

        // if user role is patient, filter by patient_id
        if ($role === 'patient') {
            $patientId = Patient::where('user_id', $request->user()->id)->value('id');
            if ($patientId) {
                $query->where('patient_id', $patientId);
            } else {
                return response()->json(['message' => 'Patient ID is required for patient role.'], 400);
            }
        } else if ($role !== 'super_admin') {
            // if user role is not super_admin, filter by hospital_id which is the clinic_id in this context
            $hospitalId = $request->user()->hospital_id;
            if ($hospitalId) {
                $query->whereHas('clinic', function ($q) use ($hospitalId) {
                    $q->where('hospital_id', $hospitalId);
                });
            } else {
                return response()->json(['message' => 'Hospital ID is required for this role.'], 400);
            }
        }

        $clinicPatients = $query->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($clinicPatients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            // Start a transaction
            DB::beginTransaction();

            // Validate the request data
            $request->validate([
                'clinic_id' => 'required|exists:clinics,id',
                'patient_id' => 'required|exists:patients,id',
            ]);

            $clinicPatient = ClinicPatients::create([
                'clinic_id' => $request->input('clinic_id'),
                'patient_id' => $request->input('patient_id'),
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json($clinicPatient, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ClinicPatients $clinicPatients)
    {
        return response()->json($clinicPatients->load(['clinic', 'patient', 'patient.user']));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClinicPatients $clinicPatients)
    {
        try {
            // Start a transaction
            DB::beginTransaction();

            // Validate the request data
            $request->validate([
                'clinic_id' => 'required|exists:clinics,id',
                'patient_id' => 'required|exists:patients,id',
            ]);

            $clinicPatients->update([
                'clinic_id' => $request->input('clinic_id'),
                'patient_id' => $request->input('patient_id'),
            ]);

            // Commit the transaction
            DB::commit();

            return response()->json($clinicPatients);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ClinicPatients $clinicPatients)
    {
        try {
            // Start a transaction
            DB::beginTransaction();

            $clinicPatients->delete();

            // Commit the transaction
            DB::commit();

            return response()->json(['message' => 'Clinic patient deleted successfully.'], 204);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
