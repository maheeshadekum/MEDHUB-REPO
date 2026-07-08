<?php

namespace App\Http\Controllers;

use App\Models\ClinicToken;
use App\Models\OpdToken;
use App\Models\Patient;
use App\Models\Prescriptions;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrescriptionsController extends Controller
{

    // get prescriptions
    public function getPrescriptions(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $status = $request->input('status', null);

        // get user from request
        $user = $request->user();
        $userRole = $user->role->name ?? $user->role; // Handle both role object and string

        // Base query with common relationships
        $baseQuery = Prescriptions::with([
            'patient:id,name,nic',
            'doctor:id,name',
            'pharmacist:id,name',
            'hospital:id,name',
            'medicines' => function ($query) {
                $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
            }
        ]);

        // Apply role-based filtering
        if ($userRole === 'patient') {
            // Patient can only see their own prescriptions
            $patient = Patient::where('user_id', $user->id)->first();

            if (!$patient) {
                return response()->json(['message' => 'Patient profile not found'], 404);
            }

            $prescriptions = $baseQuery->where('patient_id', $patient->id);

            // Patient sorting: newest first
            $prescriptions = $prescriptions->orderBy('created_at', 'desc');
        } elseif ($userRole === 'pharmacist') {
            // Pharmacist sees hospital prescriptions with 'prescribed' status first
            $hospitalId = $user->hospitals()->first()->id ?? $user->hospital_id;

            if (!$hospitalId) {
                return response()->json(['message' => 'User does not belong to any hospital'], 403);
            }

            $prescriptions = $baseQuery->where('hospital_id', $hospitalId);

            // Pharmacist sorting: 'prescribed' status first, then by date (newest first)
            $prescriptions = $prescriptions->orderByRaw("
            CASE 
                WHEN status = 'prescribed' THEN 1 
                ELSE 2 
            END ASC
            ")->orderBy('created_at', 'desc');
        } elseif ($userRole === 'doctor') {
            // Doctor sees hospital prescriptions with 'draft' status first
            $hospitalId = $user->hospitals()->first()->id ?? $user->hospital_id;

            if (!$hospitalId) {
                return response()->json(['message' => 'User does not belong to any hospital'], 403);
            }

            $prescriptions = $baseQuery->where('hospital_id', $hospitalId);

            // Doctor sorting: 'draft' status first, then by date (newest first)
            $prescriptions = $prescriptions->orderByRaw("
            CASE 
                WHEN status = 'draft' THEN 1 
                ELSE 2 
            END ASC
            ")->orderBy('created_at', 'desc');
        } elseif ($userRole !== 'super_admin') {
            // Other hospital staff (hospital_admin, receptionist, etc.)
            $hospitalId = $user->hospitals()->first()->id ?? $user->hospital_id;

            if (!$hospitalId) {
                return response()->json(['message' => 'User does not belong to any hospital'], 403);
            }

            $prescriptions = $baseQuery->where('hospital_id', $hospitalId);

            // Other roles sorting: newest first
            $prescriptions = $prescriptions->orderBy('created_at', 'desc');
        } else {
            // Super admin sees all prescriptions
            $prescriptions = $baseQuery;

            // Super admin sorting: newest first
            $prescriptions = $prescriptions->orderBy('created_at', 'desc');
        }

        // Apply status filter if provided
        if ($status) {
            $prescriptions = $prescriptions->where('status', $status);
        }

        // Apply search filter
        if ($search) {
            $prescriptions = $prescriptions->where(function ($query) use ($search) {
                $query->where('description', 'like', '%' . $search . '%')
                    ->orWhereHas('patient', function ($patientQuery) use ($search) {
                        $patientQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('nic', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('doctor', function ($doctorQuery) use ($search) {
                        $doctorQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhere('doctor_id', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('hospital', function ($hospitalQuery) use ($search) {
                        $hospitalQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        // Execute pagination
        $prescriptions = $prescriptions->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($prescriptions);
    }

    // get prescription by ID
    public function getPrescriptionById($id)
    {
        // check if user is patient and if so, check if the prescription belongs to that patient
        $user = request()->user();
        $prescription = Prescriptions::with(
            'patient:id,name,nic',
            'medicines:id,name,dosage,frequency,days_supply,is_external,name_of_external_medicine,duration,prescription_id',
            'user:id,name,doctor_id'
        )
            ->where('id', $id);

        if ($user->role === 'patient') {
            $patient = Patient::where('user_id', $user->id)->first();
            $prescription = $prescription->where('patient_id', $patient->id)->first();
        } else {
            $prescription = $prescription->first();
        }

        if (!$prescription) {
            return response()->json(['message' => 'Prescription not found'], 404);
        }

        return response()->json($prescription);
    }

    // create prescription
    public function createPrescription(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            $request->validate([
                'patient_id' => 'required|exists:patients,id',
                'opd_token_id' => 'nullable|exists:opd_tokens,id',
                'clinic_token_id' => 'nullable|exists:clinic_tokens,id',
            ]);
            // check both opd_token_id and clinic_token_id are not provided
            if (!$request->opd_token_id && !$request->clinic_token_id) {
                return response()->json(['message' => 'Either OPD token or clinic token must be provided'], 400);
            }
            // add token type and date based on provided date
            if ($request->opd_token_id) {
                $date = OpdToken::find($request->opd_token_id)->opdDate->date ?? null;
                $request->merge(['token_type' => 'opd', 'date' => $date]);
            } elseif ($request->clinic_token_id) {
                $date = ClinicToken::find($request->clinic_token_id)->clinicDate->date ?? null;
                $request->merge(['token_type' => 'clinic', 'date' => $date]);
            }

            // hospital_id get from user
            $user = $request->user();
            $hospitalId = $user->hospital_id;
            if (!$hospitalId) {
                return response()->json(['message' => 'User does not belong to any hospital'], 400);
            }

            // add hospital_id to request
            $request->merge(['hospital_id' => $hospitalId]);

            $prescription = new Prescriptions([
                'patient_id' => $request->patient_id,
                'hospital_id' => $request->hospital_id,
                'date' => date('Y-m-d', strtotime($request->date)),
                'status' => $request->status ?? 'draft',
                'opd_token_id' => $request->opd_token_id,
                'clinic_token_id' => $request->clinic_token_id,
                'token_type' => $request->token_type ?? 'opd', // default to opd if not provided
            ]);
            // save prescription
            $prescription->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Prescription created successfully', 'prescription' => $prescription], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error creating prescription', 'error' => $e->getMessage()], 500);
        }
    }

    // update prescription
    public function updatePrescription(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            $request->validate([
                'patient_id' => 'required|exists:patients,id',
                'opd_token_id' => 'nullable|exists:opd_tokens,id',
                'clinic_token_id' => 'nullable|exists:clinic_tokens,id',
            ]);

            // check both opd_token_id and clinic_token_id are not provided
            if (!$request->opd_token_id && !$request->clinic_token_id) {
                return response()->json(['message' => 'Either OPD token or clinic token must be provided'], 400);
            }
            // add token type and date based on provided date
            if ($request->opd_token_id) {
                $date = OpdToken::find($request->opd_token_id)->date;
                $request->merge(['token_type' => 'opd', 'date' => $date]);
            } elseif ($request->clinic_token_id) {
                $date = ClinicToken::find($request->clinic_token_id)->date;
                $request->merge(['token_type' => 'clinic', 'date' => $date]);
            }

            // hospital_id get from user
            $user = $request->user();
            $hospitalId = $user->hospital_id;

            if (!$hospitalId) {
                return response()->json(['message' => 'User does not belong to any hospital'], 400);
            }

            // add hospital_id to request
            $request->merge(['hospital_id' => $hospitalId]);

            $prescription = Prescriptions::find($id);
            if (!$prescription) {
                return response()->json(['message' => 'Prescription not found'], 404);
            }

            // update prescription data
            $prescription->update($request->only([
                'patient_id',
                'hospital_id',
                'date',
                'opd_token_id',
                'clinic_token_id',
                'token_type',
            ]));

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Prescription updated successfully', 'prescription' => $prescription], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error updating prescription', 'error' => $e->getMessage()], 500);
        }
    }

    // delete prescription
    public function deletePrescription($id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            $prescription = Prescriptions::find($id);
            if (!$prescription) {
                return response()->json(['message' => 'Prescription not found'], 404);
            }

            $prescription->delete();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Prescription deleted successfully'], 200);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error deleting prescription', 'error' => $e->getMessage()], 500);
        }
    }
}
