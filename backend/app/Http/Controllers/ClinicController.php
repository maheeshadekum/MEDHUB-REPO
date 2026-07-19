<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClinicController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $hospitalId = $request->input('hospital_id');

        $scope = $this->resolveClinicReadScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        if ($hospitalId !== null && !$this->canUseHospitalFilter($scope, (int) $hospitalId)) {
            return response()->json([
                'message' => 'You can only view clinics in your authorized scope'
            ], 403);
        }

        $query = $this->applyClinicReadScope(
            Clinic::with(['hospital:id,name', 'doctor:id,name,email']),
            $scope
        );

        // An approved hospital filter can only narrow the authorized query.
        if ($hospitalId) {
            $query->where('hospital_id', $hospitalId);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('location', 'like', '%' . $search . '%')
                    ->orWhereHas('hospital', function ($hospitalQuery) use ($search) {
                        $hospitalQuery->where('name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('doctor', function ($doctorQuery) use ($search) {
                        $doctorQuery->where('name', 'like', '%' . $search . '%');
                    });
            });
        }

        $clinics = $query->orderBy('updated_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($clinics);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $role = $this->requireClinicManager($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        $this->trimClinicStringInputs($request);
        $validated = $request->validate($this->clinicValidationRules($role === 'super_admin'));
        $hospitalId = $this->resolveManagementHospitalId($request, $role, $validated);
        if ($hospitalId instanceof JsonResponse) {
            return $hospitalId;
        }

        $attributes = $this->normalizedClinicAttributes($validated);
        $this->validateClinicDoctor((int) $attributes['doctor_id'], $hospitalId);
        $this->ensureClinicIsNotDuplicate($hospitalId, $attributes['name'], $attributes['location']);
        $attributes['hospital_id'] = $hospitalId;

        $clinic = DB::transaction(fn() => Clinic::create($attributes));
        $clinic->load(['hospital:id,name', 'doctor:id,name,email']);

        return response()->json($clinic, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id, Request $request)
    {
        if (!Clinic::whereKey($id)->exists()) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        $scope = $this->resolveClinicReadScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $clinic = $this->applyClinicReadScope(Clinic::query(), $scope)->find($id);
        if (!$clinic) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Load relationships
        $clinic->load([
            'hospital:id,name,address,phone,email',
            'doctor:id,name,email',
            'clinicDates' => function ($query) {
                $query->where('date', '>=', now()->toDateString())
                    ->where('status', 'scheduled')
                    ->orderBy('date')
                    ->orderBy('start_time');
            }
        ]);

        return response()->json($clinic);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $clinic = Clinic::find($id);

        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        $role = $this->requireClinicManager($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        if ($role === 'hospital_admin') {
            if (!$request->user()->hospital_id) {
                return response()->json(['message' => 'A hospital assignment is required'], 403);
            }

            if ((int) $request->user()->hospital_id !== (int) $clinic->hospital_id) {
                return response()->json(['message' => 'You can only update clinics in your hospital'], 403);
            }
        }

        if ($request->filled('hospital_id') && (int) $request->input('hospital_id') !== (int) $clinic->hospital_id) {
            throw ValidationException::withMessages([
                'hospital_id' => ['Clinic hospital ownership cannot be changed.'],
            ]);
        }

        $this->trimClinicStringInputs($request);
        $validated = $request->validate($this->clinicValidationRules(false));
        $attributes = $this->normalizedClinicAttributes($validated);
        $this->validateClinicDoctor((int) $attributes['doctor_id'], (int) $clinic->hospital_id);
        $this->ensureClinicIsNotDuplicate(
            (int) $clinic->hospital_id,
            $attributes['name'],
            $attributes['location'],
            (int) $clinic->id
        );

        DB::transaction(fn() => $clinic->update($attributes));
        $clinic->load(['hospital:id,name', 'doctor:id,name,email']);

        return response()->json($clinic);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id, Request $request)
    {
        $clinic = Clinic::find($id);

        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        $role = $this->requireClinicManager($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        if ($role === 'hospital_admin') {
            if (!$request->user()->hospital_id) {
                return response()->json(['message' => 'A hospital assignment is required'], 403);
            }

            if ((int) $request->user()->hospital_id !== (int) $clinic->hospital_id) {
                return response()->json(['message' => 'You can only manage clinics in your hospital'], 403);
            }
        }

        return response()->json([
            'message' => 'Clinic deletion is not supported. Clinics must be retained to protect clinical history.',
        ], 422);
    }

    /**
     * Get clinics by hospital.
     */
    public function getClinicsByHospital($hospitalId, Request $request)
    {
        // Validate hospital exists
        $hospital = Hospital::find($hospitalId);
        if (!$hospital) {
            return response()->json(['message' => 'Hospital not found'], 404);
        }

        $scope = $this->resolveClinicReadScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        if (!in_array($scope['type'], ['global', 'hospital'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($scope['type'] === 'hospital' && $scope['hospital_id'] !== (int) $hospitalId) {
            return response()->json([
                'message' => 'You can only view clinics in your hospital'
            ], 403);
        }

        $clinics = Clinic::with(['doctor:id,name,email'])
            ->where('hospital_id', $hospitalId)
            ->orderBy('name')
            ->get();

        return response()->json($clinics);
    }

    /**
     * Get clinics by doctor.
     */
    public function getClinicsByDoctor($doctorId, Request $request)
    {
        // Validate doctor exists
        $doctor = User::find($doctorId);
        if (!$doctor) {
            return response()->json(['message' => 'Doctor not found'], 404);
        }

        $scope = $this->resolveClinicReadScope($request);
        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        if ($scope['type'] === 'doctor') {
            if ($scope['doctor_id'] !== (int) $doctorId) {
                return response()->json([
                    'message' => 'You can only view your assigned clinics'
                ], 403);
            }
        } elseif ($scope['type'] === 'hospital') {
            if ((int) $doctor->hospital_id !== $scope['hospital_id']) {
                return response()->json([
                    'message' => 'You can only view clinics for doctors in your hospital'
                ], 403);
            }
        } elseif ($scope['type'] !== 'global') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $clinics = $this->applyClinicReadScope(
            Clinic::with(['hospital:id,name']),
            $scope
        )->where('doctor_id', $doctorId)
            ->orderBy('name')
            ->get();

        return response()->json($clinics);
    }

    /**
     * Get available doctors for a hospital.
     */
    public function getAvailableDoctors($hospitalId, Request $request)
    {
        // Validate hospital exists
        $hospital = Hospital::find($hospitalId);
        if (!$hospital) {
            return response()->json(['message' => 'Hospital not found'], 404);
        }

        $role = $this->requireClinicManager($request);
        if ($role instanceof JsonResponse) {
            return $role;
        }

        if ($role === 'hospital_admin') {
            if (!$request->user()->hospital_id) {
                return response()->json(['message' => 'A hospital assignment is required'], 403);
            }

            if ((int) $request->user()->hospital_id !== (int) $hospitalId) {
                return response()->json(['message' => 'You can only view doctors in your hospital'], 403);
            }
        }

        // Get doctors who belong to this hospital and have doctor role
        $doctors = User::select('id', 'name', 'email')
            ->where('hospital_id', $hospitalId)
            ->whereHas('role', function ($query) {
                $query->where('name', 'doctor');
            })
            ->where('status', 'working')
            ->orderBy('name')
            ->get();

        return response()->json($doctors);
    }

    private function requireClinicManager(Request $request): string|JsonResponse
    {
        $role = $request->user()?->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $role;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function clinicValidationRules(bool $requireHospital): array
    {
        return [
            'hospital_id' => [Rule::requiredIf($requireHospital), 'nullable', 'integer', 'min:1', 'exists:hospitals,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:500'],
            'doctor_id' => ['required', 'integer', 'min:1', 'exists:users,id'],
            'location' => ['required', 'string', 'max:255'],
            'total_hourly_tokens' => ['required', 'integer', 'min:1', 'max:1000'],
            'self_hourly_tokens' => ['required', 'integer', 'min:1', 'max:1000', 'lte:total_hourly_tokens'],
        ];
    }

    private function resolveManagementHospitalId(Request $request, string $role, array $validated): int|JsonResponse
    {
        if ($role === 'super_admin') {
            return (int) $validated['hospital_id'];
        }

        if (!$request->user()->hospital_id) {
            return response()->json(['message' => 'A hospital assignment is required'], 403);
        }

        $hospitalId = (int) $request->user()->hospital_id;
        if (isset($validated['hospital_id']) && (int) $validated['hospital_id'] !== $hospitalId) {
            throw ValidationException::withMessages([
                'hospital_id' => ['You can only create clinics in your assigned hospital.'],
            ]);
        }

        return $hospitalId;
    }

    private function validateClinicDoctor(int $doctorId, int $hospitalId): void
    {
        $isValidDoctor = User::whereKey($doctorId)
            ->where('hospital_id', $hospitalId)
            ->where('status', 'working')
            ->whereHas('role', fn(Builder $query) => $query->where('name', 'doctor'))
            ->exists();

        if (!$isValidDoctor) {
            throw ValidationException::withMessages([
                'doctor_id' => ['Select a working doctor from the Clinic hospital.'],
            ]);
        }
    }

    private function ensureClinicIsNotDuplicate(
        int $hospitalId,
        string $name,
        string $location,
        ?int $exceptClinicId = null
    ): void {
        $duplicate = Clinic::where('hospital_id', $hospitalId)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
            ->whereRaw('LOWER(TRIM(location)) = ?', [mb_strtolower($location)])
            ->when($exceptClinicId, fn(Builder $query) => $query->where('id', '!=', $exceptClinicId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'name' => ['A Clinic with this name and location already exists in the Hospital.'],
                'location' => ['A Clinic with this name and location already exists in the Hospital.'],
            ]);
        }
    }

    /**
     * @return array{name: string, description: string, doctor_id: int, location: string, total_hourly_tokens: int, self_hourly_tokens: int}
     */
    private function normalizedClinicAttributes(array $validated): array
    {
        return [
            'name' => trim($validated['name']),
            'description' => trim($validated['description']),
            'doctor_id' => (int) $validated['doctor_id'],
            'location' => trim($validated['location']),
            'total_hourly_tokens' => (int) $validated['total_hourly_tokens'],
            'self_hourly_tokens' => (int) $validated['self_hourly_tokens'],
        ];
    }

    private function trimClinicStringInputs(Request $request): void
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'description' => is_string($request->input('description')) ? trim($request->input('description')) : $request->input('description'),
            'location' => is_string($request->input('location')) ? trim($request->input('location')) : $request->input('location'),
        ]);
    }

    /**
     * Resolve the authoritative Clinic read scope for the authenticated user.
     *
     * @return array{type: string, hospital_id?: int, doctor_id?: int, patient_id?: int}|JsonResponse
     */
    private function resolveClinicReadScope(Request $request): array|JsonResponse
    {
        $user = $request->user();
        $role = $user->role?->name;

        if ($role === 'super_admin') {
            return ['type' => 'global'];
        }

        if (in_array($role, ['hospital_admin', 'receptionist'], true)) {
            return $this->hospitalScopeOrForbidden($user->hospital_id);
        }

        if ($role === 'doctor') {
            return [
                'type' => 'doctor',
                'doctor_id' => (int) $user->id,
                'hospital_id' => $user->hospital_id ? (int) $user->hospital_id : null,
            ];
        }

        if ($role === 'pharmacist') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'patient') {
            $patientId = Patient::where('user_id', $user->id)->value('id');
            if (!$patientId) {
                return response()->json(['message' => 'A patient profile is required'], 403);
            }

            return ['type' => 'patient', 'patient_id' => (int) $patientId];
        }

        return $this->hospitalScopeOrForbidden($user->hospital_id);
    }

    /**
     * @return array{type: string, hospital_id: int}|JsonResponse
     */
    private function hospitalScopeOrForbidden($hospitalId): array|JsonResponse
    {
        if (!$hospitalId) {
            return response()->json(['message' => 'A hospital assignment is required'], 403);
        }

        return ['type' => 'hospital', 'hospital_id' => (int) $hospitalId];
    }

    /**
     * Apply authorization constraints before any filters, search, or pagination.
     *
     * @param array{type: string, hospital_id?: int|null, doctor_id?: int, patient_id?: int} $scope
     */
    private function applyClinicReadScope(Builder $query, array $scope): Builder
    {
        return match ($scope['type']) {
            'hospital' => $query->where('hospital_id', $scope['hospital_id']),
            'doctor' => $query->where('doctor_id', $scope['doctor_id']),
            'patient' => $query->whereHas('patients', function (Builder $patientQuery) use ($scope) {
                $patientQuery->where('patients.id', $scope['patient_id']);
            }),
            default => $query,
        };
    }

    /**
     * A hospital filter may narrow a scope but must never broaden it.
     *
     * @param array{type: string, hospital_id?: int|null} $scope
     */
    private function canUseHospitalFilter(array $scope, int $hospitalId): bool
    {
        if ($scope['type'] === 'global' || $scope['type'] === 'patient') {
            return true;
        }

        if ($scope['type'] === 'hospital') {
            return $scope['hospital_id'] === $hospitalId;
        }

        if ($scope['type'] === 'doctor') {
            return isset($scope['hospital_id']) && $scope['hospital_id'] === $hospitalId;
        }

        return false;
    }
}
