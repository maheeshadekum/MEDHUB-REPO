<?php

namespace App\Http\Controllers;


use App\Models\Patient;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientsController extends Controller
{
    // get all patients
    public function getPatients(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        if ($search) {
            $patients = Patient::where('name', 'like', '%' . $search . '%')
                ->orWhere('nic', 'like', '%' . $search . '%')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        } else {
            $patients = Patient::orderBy('updated_at', 'desc')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        }
        return response()->json($patients);
    }

    // get patient by NIC
    public function getPatientByNic($nic)
    {
        // find patient by NIC
        $patient = Patient::where('nic', $nic)->first();

        if (!$patient) {
            return response()->json(['message' => 'Patient not found'], 404);
        }

        return response()->json($patient);
    }

    // create patient
    public function createPatient(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate patient data
            $request->validate([
                'nic' => 'required|string',
                'phone' => 'nullable|string|max:10',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|string',
                'address' => 'nullable|string',
                'name' => 'required|string|max:255',
            ]);

            // check if patient already exists
            if (Patient::where('nic', $request->nic)->exists()) {
                return response()->json(['message' => 'Patient with this NIC already exists'], 400);
            }

            $patient = new Patient([
                'nic' => $request->nic,
                'phone' => $request->phone,
                'date_of_birth' => date('Y-m-d', strtotime($request->date_of_birth)),
                'gender' => $request->gender,
                'address' => $request->address,
                'name' => $request->name,
            ]);
            // save patient
            $patient->save();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Patient created successfully', 'patient' => $patient], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error creating patient',
            ], 500);
        }
    }

    // update patient
    public function updatePatient(Patient $patient, Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // validate patient data
            $request->validate([
                'phone' => 'nullable|string|max:10',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|string',
                'address' => 'nullable|string',
                'name' => 'required|string|max:255',
                'nic' => 'required|string|unique:patients,nic,' . $patient->id,
            ]);

            // format date_of_birth
            if ($request->date_of_birth) {
                $request->merge([
                    'date_of_birth' => date('Y-m-d', strtotime($request->date_of_birth)),
                ]);
            }

            // update patient data
            $patient->update($request->only([
                'phone',
                'date_of_birth',
                'gender',
                'address',
                'name',
                'nic',
            ]));

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Patient updated successfully', 'patient' => $patient], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();

            return response()->json([
                'message' => 'Error updating patient',
            ], 500);
        }
    }
}
