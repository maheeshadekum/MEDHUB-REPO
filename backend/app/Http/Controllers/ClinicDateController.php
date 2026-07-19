<?php

namespace App\Http\Controllers;

use App\Models\ClinicDate;
use App\Models\Clinic;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicDateController extends Controller
{
    /**
     * Display a listing of clinic dates.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $clinicId = $request->input('clinic_id');
        $status = $request->input('status');
        $futureOnly = $request->input('future_only', false);

        $query = ClinicDate::with(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

        // If future only
        if ($futureOnly) {
            $query->where('date', '>=', now());
        }

        // Filter by clinic if provided
        if ($clinicId) {
            $query->where('clinic_id', $clinicId);
        } elseif ($request->user()->role->name !== 'super_admin') {
            // Hospital employees only see dates from their hospital's clinics
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            if ($userHospitalId) {
                $query->whereHas('clinic', function ($q) use ($userHospitalId) {
                    $q->where('hospital_id', $userHospitalId);
                });
            }
        }

        // Filter by status if provided
        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('date', 'like', '%' . $search . '%')
                    ->orWhereHas('clinic', function ($clinicQuery) use ($search) {
                        $clinicQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $clinicDates = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($clinicDates);
    }

    /**
     * Store a newly created clinic date.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'clinic_id' => 'required|exists:clinics,id',
                'date' => 'required|date|after_or_equal:today',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'status' => 'in:scheduled,completed,cancelled'
            ]);

            // get date only 
            $request->merge(['date' => date('Y-m-d', strtotime($request->date))]);

            // check date is already exists for the hospital
            $existingClinicDate = ClinicDate::where('clinic_id', $request->clinic_id)
                ->where('date', $request->date)
                ->where('status', 'scheduled')
                ->exists();

            if ($existingClinicDate) {
                return response()->json([
                    'message' => 'An clinic date already exists for this hospital on the selected date.'
                ], 400);
            }

            // Check if user has permission to create clinic date
            $clinic = Clinic::find($request->clinic_id);
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only create clinic dates for your hospital\'s clinics'
                    ], 403);
                }
            }

            $clinicDate = ClinicDate::create($request->all());
            $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

            DB::commit();

            return response()->json($clinicDate, 201);
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
     * Display the specified clinic date.
     */
    public function show($id)
    {
        $clinicDate = ClinicDate::with([
            'clinic:id,name,hospital_id,total_hourly_tokens,self_hourly_tokens',
            'clinic.hospital:id,name',
            'clinic.doctor:id,name',
        ])->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        return response()->json($clinicDate);
    }

    /**
     * Update the specified clinic date.
     */
    public function update(Request $request, $id)
    {
        $clinicDate = ClinicDate::find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        DB::beginTransaction();

        try {
            $request->validate([
                'clinic_id' => 'required|exists:clinics,id',
                'date' => 'required|date|after_or_equal:today',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
                'status' => 'in:scheduled,completed,cancelled'
            ]);

            // get date only 
            $request->merge(['date' => date('Y-m-d', strtotime($request->date))]);

            // check date is already exists for the hospital
            $existingClinicDate = ClinicDate::where('clinic_id', $request->clinic_id)
                ->where('date', $request->date)
                ->where('status', 'scheduled')
                ->where('id', '!=', $id)
                ->exists();

            if ($existingClinicDate) {
                return response()->json([
                    'message' => 'An clinic date already exists for this hospital on the selected date.'
                ], 400);
            }

            // Check if user has permission to update clinic date
            $clinic = Clinic::find($request->clinic_id);
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only update clinic dates for your hospital\'s clinics'
                    ], 403);
                }
            }

            $clinicDate->update($request->all());
            $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

            DB::commit();

            return response()->json($clinicDate);
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
     * Remove the specified clinic date.
     */
    public function destroy($id, Request $request)
    {
        $clinicDate = ClinicDate::find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        DB::beginTransaction();

        try {
            // Check if user has permission to delete clinic date
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinicDate->clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only delete clinic dates for your hospital\'s clinics'
                    ], 403);
                }
            }

            // Check if clinic date has tokens
            if ($clinicDate->clinicTokens()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete clinic date with existing reservations'
                ], 400);
            }

            $clinicDate->delete();

            DB::commit();

            return response()->json(['message' => 'Clinic date deleted successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get clinic dates by clinic.
     */
    public function getByClinic($clinicId, Request $request)
    {
        $clinic = Clinic::find($clinicId);
        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        // Check permission
        if ($request->user()->role->name === 'hospital_admin') {
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            if ($userHospitalId !== $clinic->hospital_id) {
                return response()->json([
                    'message' => 'You can only view clinic dates for your hospital\'s clinics'
                ], 403);
            }
        }

        $status = $request->input('status');
        $fromDate = $request->input('from_date', now()->toDateString());

        $query = ClinicDate::where('clinic_id', $clinicId)
            ->where('date', '>=', $fromDate);

        if ($status) {
            $query->where('status', $status);
        }

        $clinicDates = $query->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return response()->json($clinicDates);
    }

    /**
     * Update the status of a clinic date.
     */
    public function updateStatus(Request $request, $id)
    {
        $clinicDate = ClinicDate::find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $request->validate([
            'status' => 'required|in:scheduled,completed,cancelled'
        ]);

        DB::beginTransaction();

        try {
            // Check if user has permission to update clinic date
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            if ($userHospitalId !== $clinicDate->clinic->hospital_id) {
                return response()->json([
                    'message' => 'You can only update clinic dates for your hospital\'s clinics'
                ], 403);
            }

            $clinicDate->update($request->only('status'));
            DB::commit();

            return response()->json($clinicDate);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
