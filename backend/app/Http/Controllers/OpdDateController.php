<?php

namespace App\Http\Controllers;

use App\Models\OpdDate;
use App\Models\Hospital;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OpdDateController extends Controller
{
    /**
     * Display a listing of OPD dates.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $hospitalId = $request->input('hospital_id');
        $status = $request->input('status');
        $futureOnly = $request->input('future_only', false);

        $query = OpdDate::with(['hospital:id,name']);

        // If future only
        if ($futureOnly) {
            $query->where('date', '>=', now());
        }

        // Filter by hospital if provided
        if ($hospitalId) {
            $query->where('hospital_id', $hospitalId);
        } elseif ($request->user()->role->name !== 'super_admin') {
            // Hospital employees only see dates from their hospital
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            if ($userHospitalId) {
                $query->where('hospital_id', $userHospitalId);
            }
        }

        // Filter by status if provided
        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('date', 'like', '%' . $search . '%')
                    ->orWhereHas('hospital', function ($hospitalQuery) use ($search) {
                        $hospitalQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $opdDates = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($opdDates);
    }

    /**
     * Store a newly created OPD date.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && !$user->hospital_id) {
            return response()->json([
                'message' => 'No hospital is assigned to your account'
            ], 403);
        }

        $validated = $request->validate([
            'hospital_id' => 'required|exists:hospitals,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'in:scheduled,completed,cancelled'
        ]);

        if ($role === 'hospital_admin' && (int) $validated['hospital_id'] !== (int) $user->hospital_id) {
            return response()->json([
                'message' => 'You can only create OPD dates for your hospital'
            ], 403);
        }

        $validated['date'] = date('Y-m-d', strtotime($validated['date']));

        // check date is already exists for the hospital
        $existingOpdDate = OpdDate::where('hospital_id', $validated['hospital_id'])
            ->where('date', $validated['date'])
            ->where('status', 'scheduled')
            ->exists();

        if ($existingOpdDate) {
            return response()->json([
                'message' => 'An OPD date already exists for this hospital on the selected date.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $opdDate = OpdDate::create($validated);
            $opdDate->load(['hospital:id,name']);

            DB::commit();

            return response()->json($opdDate, 201);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified OPD date.
     */
    public function show($id)
    {
        $opdDate = OpdDate::with([
            'hospital:id,name,address,phone,email',
        ])->find($id);

        if (!$opdDate) {
            return response()->json(['message' => 'OPD date not found'], 404);
        }

        return response()->json($opdDate);
    }

    /**
     * Update the specified OPD date.
     */
    public function update(Request $request, $id)
    {
        $opdDate = OpdDate::find($id);

        if (!$opdDate) {
            return response()->json(['message' => 'OPD date not found'], 404);
        }

        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin') {
            if (!$user->hospital_id || (int) $user->hospital_id !== (int) $opdDate->hospital_id) {
                return response()->json([
                    'message' => 'You can only update OPD dates for your hospital'
                ], 403);
            }

            if ($request->has('hospital_id') && (int) $request->hospital_id !== (int) $opdDate->hospital_id) {
                return response()->json([
                    'message' => 'The hospital of an OPD date cannot be changed'
                ], 403);
            }
        }

        $validated = $request->validate([
            'hospital_id' => 'sometimes|integer|exists:hospitals,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'in:scheduled,completed,cancelled'
        ]);

        if (array_key_exists('hospital_id', $validated)
            && (int) $validated['hospital_id'] !== (int) $opdDate->hospital_id) {
            return response()->json([
                'message' => 'The hospital of an OPD date cannot be changed',
                'errors' => [
                    'hospital_id' => ['The hospital of an OPD date cannot be changed.'],
                ],
            ], 422);
        }

        unset($validated['hospital_id']);
        $validated['date'] = date('Y-m-d', strtotime($validated['date']));

        // check date is already exists for the hospital
        $existingOpdDate = OpdDate::where('hospital_id', $opdDate->hospital_id)
            ->where('date', $validated['date'])
            ->where('status', 'scheduled')
            ->where('id', '!=', $id)
            ->exists();

        if ($existingOpdDate) {
            return response()->json([
                'message' => 'An OPD date already exists for this hospital on the selected date.'
            ], 400);
        }

        try {
            DB::beginTransaction();

            $opdDate->update($validated);
            $opdDate->load(['hospital:id,name']);

            DB::commit();

            return response()->json($opdDate);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified OPD date.
     */
    public function destroy($id, Request $request)
    {
        $opdDate = OpdDate::find($id);

        if (!$opdDate) {
            return response()->json(['message' => 'OPD date not found'], 404);
        }

        DB::beginTransaction();

        try {
            // Check if user has permission to delete OPD date
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $opdDate->hospital_id) {
                    return response()->json([
                        'message' => 'You can only delete OPD dates for your hospital'
                    ], 403);
                }
            }

            // Check if OPD date has tokens
            if ($opdDate->opdTokens()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete OPD date with existing reservations'
                ], 400);
            }

            $opdDate->delete();

            DB::commit();

            return response()->json(['message' => 'OPD date deleted successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get OPD dates by hospital.
     */
    public function getByHospital($hospitalId, Request $request)
    {
        $hospital = Hospital::find($hospitalId);
        if (!$hospital) {
            return response()->json(['message' => 'Hospital not found'], 404);
        }

        // Check permission
        if ($request->user()->role->name === 'hospital_admin') {
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            if ($userHospitalId !== (int)$hospitalId) {
                return response()->json([
                    'message' => 'You can only view OPD dates for your hospital'
                ], 403);
            }
        }

        $status = $request->input('status');
        $fromDate = $request->input('from_date', now()->toDateString());

        $query = OpdDate::where('hospital_id', $hospitalId)
            ->where('date', '>=', $fromDate);

        if ($status) {
            $query->where('status', $status);
        }

        $opdDates = $query->orderBy('date')
            ->orderBy('start_time')
            ->get();

        return response()->json($opdDates);
    }

    /**
     * Update the status of an OPD date.
     */
    public function updateStatus(Request $request, $id)
    {
        $opdDate = OpdDate::find($id);

        if (!$opdDate) {
            return response()->json(['message' => 'OPD date not found'], 404);
        }

        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin'
            && (!$user->hospital_id || (int) $user->hospital_id !== (int) $opdDate->hospital_id)) {
            return response()->json([
                'message' => 'You can only update OPD dates for your hospital'
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:scheduled,completed,cancelled'
        ]);

        try {
            DB::beginTransaction();

            $opdDate->update($validated);
            DB::commit();

            return response()->json($opdDate);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
