<?php

namespace App\Http\Controllers;

use App\Models\ClinicDate;
use App\Models\Clinic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClinicDateController extends Controller
{
    /**
     * Display a listing of clinic dates.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && !$user->hospital_id) {
            return response()->json(['message' => 'No hospital is assigned to your account'], 403);
        }

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

        if ($role === 'hospital_admin') {
            $query->whereHas('clinic', function ($clinicQuery) use ($user) {
                $clinicQuery->where('hospital_id', $user->hospital_id);
            });
        }

        // Filter by clinic without bypassing the hospital scope above
        if ($clinicId) {
            $query->where('clinic_id', $clinicId);
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
        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && !$user->hospital_id) {
            return response()->json(['message' => 'No hospital is assigned to your account'], 403);
        }

        $validated = $request->validate([
            'hospital_id' => 'required|integer|exists:hospitals,id',
            'clinic_id' => 'required|integer|exists:clinics,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'required|in:scheduled,completed,cancelled'
        ]);

        $clinic = Clinic::findOrFail($validated['clinic_id']);
        $submittedHospitalId = (int) $validated['hospital_id'];
        $clinicHospitalId = (int) $clinic->hospital_id;

        if ($role === 'hospital_admin'
            && ($clinicHospitalId !== (int) $user->hospital_id
                || $submittedHospitalId !== (int) $user->hospital_id)) {
            return response()->json([
                'message' => 'You can only create Clinic Dates for your hospital'
            ], 403);
        }

        if ($submittedHospitalId !== $clinicHospitalId) {
            return response()->json([
                'message' => 'The selected Clinic does not belong to the selected Hospital',
                'errors' => [
                    'clinic_id' => ['The selected Clinic does not belong to the selected Hospital.'],
                ],
            ], 422);
        }

        $validated['date'] = date('Y-m-d', strtotime($validated['date']));

        $existingClinicDate = ClinicDate::where('clinic_id', $validated['clinic_id'])
            ->where('date', $validated['date'])
            ->where('status', 'scheduled')
            ->exists();

        if ($existingClinicDate) {
            return response()->json([
                'message' => 'A Clinic Date already exists for this Clinic on the selected date.'
            ], 400);
        }

        unset($validated['hospital_id']);
        $clinicDate = DB::transaction(fn() => ClinicDate::create($validated));
        $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

        return response()->json($clinicDate, 201);
    }

    /**
     * Display the specified clinic date.
     */
    public function show($id, Request $request)
    {
        $clinicDate = ClinicDate::with([
            'clinic:id,name,hospital_id,total_hourly_tokens,self_hourly_tokens',
            'clinic.hospital:id,name',
            'clinic.doctor:id,name',
        ])->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        if (!$this->canAccessClinicDate($request, $clinicDate)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json($clinicDate);
    }

    /**
     * Update the specified clinic date.
     */
    public function update(Request $request, $id)
    {
        $clinicDate = ClinicDate::with(['clinic:id,hospital_id', 'clinic.hospital:id,name'])->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        if (!$this->canAccessClinicDate($request, $clinicDate)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'clinic_id' => 'sometimes|integer|exists:clinics,id',
            'hospital_id' => 'sometimes|integer|exists:hospitals,id',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'status' => 'required|in:scheduled,completed,cancelled'
        ]);

        if (array_key_exists('clinic_id', $validated)
            && (int) $validated['clinic_id'] !== (int) $clinicDate->clinic_id) {
            return response()->json([
                'message' => 'The Clinic of a Clinic Date cannot be changed',
                'errors' => [
                    'clinic_id' => ['The Clinic of a Clinic Date cannot be changed.'],
                ],
            ], 422);
        }

        $hospitalId = (int) $clinicDate->clinic->hospital_id;
        if (array_key_exists('hospital_id', $validated)
            && (int) $validated['hospital_id'] !== $hospitalId) {
            return response()->json([
                'message' => 'The Hospital of a Clinic Date cannot be changed',
                'errors' => [
                    'hospital_id' => ['The Hospital of a Clinic Date cannot be changed.'],
                ],
            ], 422);
        }

        unset($validated['clinic_id'], $validated['hospital_id']);
        $validated['date'] = date('Y-m-d', strtotime($validated['date']));

        $existingClinicDate = ClinicDate::where('clinic_id', $clinicDate->clinic_id)
            ->where('date', $validated['date'])
            ->where('status', 'scheduled')
            ->where('id', '!=', $id)
            ->exists();

        if ($existingClinicDate) {
            return response()->json([
                'message' => 'A Clinic Date already exists for this Clinic on the selected date.'
            ], 400);
        }

        DB::transaction(fn() => $clinicDate->update($validated));
        $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

        return response()->json($clinicDate);
    }

    /**
     * Remove the specified clinic date.
     */
    public function destroy($id, Request $request)
    {
        $clinicDate = ClinicDate::with('clinic:id,hospital_id')->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        if (!$this->canAccessClinicDate($request, $clinicDate)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Preserve the existing restriction for Clinic Dates with reservations.
        if ($clinicDate->clinicTokens()->exists()) {
            return response()->json([
                'message' => 'Cannot delete clinic date with existing reservations'
            ], 400);
        }

        DB::transaction(fn() => $clinicDate->delete());

        return response()->json(['message' => 'Clinic date deleted successfully']);
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

        $role = $request->user()->role?->name;
        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin'
            && (!$request->user()->hospital_id
                || (int) $request->user()->hospital_id !== (int) $clinic->hospital_id)) {
            return response()->json([
                'message' => 'You can only view Clinic Dates for your hospital'
            ], 403);
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
        $clinicDate = ClinicDate::with('clinic:id,hospital_id')->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        if (!$this->canAccessClinicDate($request, $clinicDate)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:scheduled,completed,cancelled'
        ]);

        DB::transaction(fn() => $clinicDate->update($validated));

        return response()->json($clinicDate);
    }

    /**
     * Allow global Super Admin access and hospital-scoped Hospital Admin access.
     */
    private function canAccessClinicDate(Request $request, ClinicDate $clinicDate): bool
    {
        $user = $request->user();
        $role = $user->role?->name;

        if ($role === 'super_admin') {
            return true;
        }

        return $role === 'hospital_admin'
            && $user->hospital_id
            && (int) $user->hospital_id === (int) $clinicDate->clinic->hospital_id;
    }
}
