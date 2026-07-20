<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicDate;
use App\Models\ClinicToken;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicDateController extends Controller
{
    /**
     * Display a listing of Clinic Dates inside the authenticated user's Clinic scope.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'clinic_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:clinics,id'],
            'status' => ['sometimes', 'nullable', 'in:scheduled,completed,cancelled'],
            'future_only' => ['sometimes', 'boolean'],
        ]);

        $query = ClinicDate::with(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);
        $scope = $this->applyReadScope($query, $request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        if (!empty($validated['clinic_id'])) {
            $clinic = Clinic::find((int) $validated['clinic_id']);
            $authorization = $this->authorizeClinicRead($request, $clinic);
            if ($authorization) {
                return $authorization;
            }

            $query->where('clinic_id', $clinic->id);
        }

        if (!empty($validated['future_only'])) {
            $query->whereDate('date', '>=', now()->toDateString());
        }

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search) {
                $searchQuery->where('date', 'like', '%' . $search . '%')
                    ->orWhereHas('clinic', function (Builder $clinicQuery) use ($search) {
                        $clinicQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $clinicDates = $query->orderBy('date', 'desc')
            ->orderBy('start_time', 'desc')
            ->paginate(
                (int) ($validated['size'] ?? 20),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1)
            );

        return response()->json($clinicDates);
    }

    /**
     * Store a newly created Clinic Date.
     */
    public function store(Request $request)
    {
        $role = $this->requireMutationRole($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $validated = $request->validate([
            'clinic_id' => ['required', 'integer', 'min:1', 'exists:clinics,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'status' => ['sometimes', 'in:scheduled'],
        ]);

        $clinic = Clinic::find((int) $validated['clinic_id']);
        $authorization = $this->authorizeMutationClinic($request, $clinic, $role);
        if ($authorization) {
            return $authorization;
        }

        $this->ensureScheduledDateIsUnique($clinic->id, $validated['date']);

        $clinicDate = DB::transaction(fn() => ClinicDate::create([
            'clinic_id' => $clinic->id,
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'status' => 'scheduled',
        ]));
        $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

        return response()->json($clinicDate, 201);
    }

    /**
     * Display the specified Clinic Date.
     */
    public function show($id, Request $request)
    {
        $clinicDate = ClinicDate::with([
            'clinic:id,name,hospital_id,doctor_id,total_hourly_tokens,self_hourly_tokens',
            'clinic.hospital:id,name',
            'clinic.doctor:id,name',
        ])->find($id);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $authorization = $this->authorizeClinicRead($request, $clinicDate->clinic);
        if ($authorization) {
            return $authorization;
        }

        return response()->json($clinicDate);
    }

    /**
     * Update the specified Clinic Date without changing its Clinic ownership.
     */
    public function update(Request $request, $id)
    {
        $clinicDate = ClinicDate::with('clinic')->find($id);
        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $role = $this->requireMutationRole($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $authorization = $this->authorizeMutationClinic($request, $clinicDate->clinic, $role);
        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'clinic_id' => ['sometimes', 'integer', 'min:1', 'exists:clinics,id'],
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'status' => ['sometimes', 'in:scheduled,completed,cancelled'],
        ]);

        if (isset($validated['clinic_id']) && (int) $validated['clinic_id'] !== (int) $clinicDate->clinic_id) {
            throw ValidationException::withMessages([
                'clinic_id' => ['Clinic Date ownership cannot be changed.'],
            ]);
        }

        $scheduleChanged = $this->scheduleHasChanged($clinicDate, $validated);
        if ($scheduleChanged && ClinicToken::where('clinic_id', $clinicDate->id)->exists()) {
            throw ValidationException::withMessages([
                'schedule' => ['This Clinic Date has issued tokens and its schedule cannot be changed. Cancel it and create a replacement schedule.'],
            ]);
        }

        $targetStatus = $validated['status'] ?? $clinicDate->status;
        if ($targetStatus === 'scheduled') {
            $this->ensureScheduledDateIsUnique(
                (int) $clinicDate->clinic_id,
                $validated['date'],
                (int) $clinicDate->id
            );
        }

        $attributes = [
            'date' => $validated['date'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
        ];
        if (isset($validated['status'])) {
            $attributes['status'] = $validated['status'];
        }

        DB::transaction(fn() => $clinicDate->update($attributes));
        $clinicDate->load(['clinic:id,name,hospital_id', 'clinic.hospital:id,name']);

        return response()->json($clinicDate);
    }

    /**
     * Hard deletion is disabled to retain scheduling and clinical history.
     */
    public function destroy($id, Request $request)
    {
        $clinicDate = ClinicDate::with('clinic')->find($id);
        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $role = $this->requireMutationRole($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $authorization = $this->authorizeMutationClinic($request, $clinicDate->clinic, $role);
        if ($authorization) {
            return $authorization;
        }

        return response()->json([
            'message' => 'Clinic Date deletion is not supported. Cancel the Clinic Date to retain scheduling and clinical history.',
        ], 422);
    }

    /**
     * Get Clinic Dates for one authorized Clinic.
     */
    public function getByClinic($clinicId, Request $request)
    {
        $clinic = Clinic::find($clinicId);
        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        $authorization = $this->authorizeClinicRead($request, $clinic);
        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:scheduled,completed,cancelled'],
            'from_date' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $query = ClinicDate::where('clinic_id', $clinic->id)
            ->whereDate('date', '>=', $validated['from_date'] ?? now()->toDateString());

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json(
            $query->orderBy('date')->orderBy('start_time')->get()
        );
    }

    /**
     * Update the status of an authorized Clinic Date while retaining related data.
     */
    public function updateStatus(Request $request, $id)
    {
        $clinicDate = ClinicDate::with('clinic')->find($id);
        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $role = $this->requireMutationRole($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $authorization = $this->authorizeMutationClinic($request, $clinicDate->clinic, $role);
        if ($authorization) {
            return $authorization;
        }

        $validated = $request->validate([
            'status' => ['required', 'in:scheduled,completed,cancelled'],
        ]);

        DB::transaction(fn() => $clinicDate->update(['status' => $validated['status']]));

        return response()->json($clinicDate);
    }

    private function applyReadScope(Builder $query, Request $request): Builder|JsonResponse
    {
        $role = $this->roleName($request);

        if ($role === 'super_admin') {
            return $query;
        }

        if (in_array($role, ['hospital_admin', 'receptionist'], true)) {
            $hospitalId = $request->user()?->hospital_id;
            if (!$hospitalId) {
                return $this->forbidden('A hospital assignment is required');
            }

            return $query->whereHas('clinic', fn(Builder $clinicQuery) =>
                $clinicQuery->where('hospital_id', $hospitalId)
            );
        }

        if ($role === 'doctor') {
            return $query->whereHas('clinic', fn(Builder $clinicQuery) =>
                $clinicQuery->where('doctor_id', $request->user()->id)
            );
        }

        if ($role === 'pharmacist') {
            return $this->forbidden();
        }

        if ($role === 'patient') {
            $patient = $this->patientFor($request);
            if (!$patient) {
                return $this->forbidden('A linked patient profile is required');
            }

            return $query->whereHas('clinic.patients', fn(Builder $patientQuery) =>
                $patientQuery->where('patients.id', $patient->id)
            );
        }

        $user = $request->user();
        if (!$user?->hospital_id || !$user->hasPermission('view_clinic')) {
            return $this->forbidden();
        }

        return $query->whereHas('clinic', fn(Builder $clinicQuery) =>
            $clinicQuery->where('hospital_id', $user->hospital_id)
        );
    }

    private function authorizeClinicRead(Request $request, Clinic $clinic): ?JsonResponse
    {
        $role = $this->roleName($request);
        $user = $request->user();

        if ($role === 'super_admin') {
            return null;
        }

        if (in_array($role, ['hospital_admin', 'receptionist'], true)) {
            if (!$user?->hospital_id) {
                return $this->forbidden('A hospital assignment is required');
            }

            return (int) $user->hospital_id === (int) $clinic->hospital_id
                ? null
                : $this->forbidden();
        }

        if ($role === 'doctor') {
            return (int) $clinic->doctor_id === (int) $user?->id
                ? null
                : $this->forbidden();
        }

        if ($role === 'pharmacist') {
            return $this->forbidden();
        }

        if ($role === 'patient') {
            $patient = $this->patientFor($request);
            if (!$patient) {
                return $this->forbidden('A linked patient profile is required');
            }

            return $patient->clinics()->whereKey($clinic->id)->exists()
                ? null
                : $this->forbidden();
        }

        if (!$user?->hospital_id || !$user->hasPermission('view_clinic')) {
            return $this->forbidden();
        }

        return (int) $user->hospital_id === (int) $clinic->hospital_id
            ? null
            : $this->forbidden();
    }

    private function requireMutationRole(Request $request): string|JsonResponse
    {
        $role = $this->roleName($request);

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return $this->forbidden();
        }

        return $role;
    }

    private function authorizeMutationClinic(Request $request, Clinic $clinic, string $role): ?JsonResponse
    {
        if ($role === 'super_admin') {
            return null;
        }

        $hospitalId = $request->user()?->hospital_id;
        if (!$hospitalId) {
            return $this->forbidden('A hospital assignment is required');
        }

        return (int) $hospitalId === (int) $clinic->hospital_id
            ? null
            : $this->forbidden('You can only manage Clinic Dates for your assigned Hospital');
    }

    private function ensureScheduledDateIsUnique(int $clinicId, string $date, ?int $exceptId = null): void
    {
        $duplicate = ClinicDate::where('clinic_id', $clinicId)
            ->whereDate('date', $date)
            ->where('status', 'scheduled')
            ->when($exceptId, fn(Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'date' => ['A scheduled Clinic Date already exists for this Clinic on the selected date.'],
            ]);
        }
    }

    private function scheduleHasChanged(ClinicDate $clinicDate, array $validated): bool
    {
        return $clinicDate->date->format('Y-m-d') !== $validated['date']
            || $clinicDate->start_time->format('H:i') !== $validated['start_time']
            || $clinicDate->end_time->format('H:i') !== $validated['end_time'];
    }

    private function patientFor(Request $request): ?Patient
    {
        return Patient::where('user_id', $request->user()?->id)->first();
    }

    private function roleName(Request $request): ?string
    {
        return $request->user()?->role?->name;
    }

    private function forbidden(string $message = 'Forbidden'): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }
}
