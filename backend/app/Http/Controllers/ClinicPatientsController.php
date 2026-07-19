<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicPatients;
use App\Models\Patient;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicPatientsController extends Controller
{
    private const HOSPITAL_SCOPED_READ_ROLES = [
        'hospital_admin',
        'receptionist',
        'doctor',
        'pharmacist',
    ];

    private const MUTATION_ROLES = [
        'super_admin',
        'hospital_admin',
        'receptionist',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $role = $user->role?->name;
        $query = ClinicPatients::with([
            'clinic',
            'clinic.hospital',
            'patient',
            'patient.user',
        ]);

        if ($role === 'patient') {
            $patientId = Patient::where('user_id', $user->id)->value('id');

            if (!$patientId) {
                return $this->forbidden('No patient record is linked to this account');
            }

            $query->where('patient_id', $patientId);
        } elseif ($role !== 'super_admin') {
            if (!$this->hasHospitalReadContext($user, $role)) {
                return $this->forbidden('A valid hospital assignment is required');
            }

            $query->whereHas('clinic', function ($clinicQuery) use ($user) {
                $clinicQuery->where('hospital_id', $user->hospital_id);
            });
        }

        if ($clinicId = $request->integer('clinic_id')) {
            $query->where('clinic_id', $clinicId);
        }

        if ($search = trim((string) $request->input('search', ''))) {
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->whereHas('patient', function ($patientQuery) use ($search) {
                        $patientQuery->where(function ($patientFields) use ($search) {
                            $patientFields->where('name', 'like', '%' . $search . '%')
                                ->orWhere('nic', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%');
                        });
                    })
                    ->orWhereHas('clinic', function ($clinicQuery) use ($search) {
                        $clinicQuery->where('name', 'like', '%' . $search . '%')
                            ->orWhereHas('hospital', function ($hospitalQuery) use ($search) {
                                $hospitalQuery->where('name', 'like', '%' . $search . '%');
                            });
                    });
            });
        }

        return response()->json(
            $query->paginate(
                $request->input('size', 20),
                ['*'],
                'page',
                $request->input('page', 1),
            ),
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$this->hasMutationRole($user)) {
            return $this->forbidden('You are not authorized to manage clinic patients');
        }

        $validated = $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
        ]);

        $clinic = Clinic::findOrFail($validated['clinic_id']);

        if (!$this->canMutateClinic($user, $clinic)) {
            return $this->forbidden('You cannot manage clinic patients for this hospital');
        }

        $this->ensureAssignmentIsUnique(
            $validated['clinic_id'],
            $validated['patient_id'],
        );

        try {
            $clinicPatient = DB::transaction(
                fn() => ClinicPatients::create($validated),
            );

            return response()->json($clinicPatient, 201);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Unable to create clinic patient assignment',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, ClinicPatients $clinicPatients)
    {
        $clinicPatients->loadMissing(['clinic.hospital', 'patient.user']);

        if (!$this->canReadAssignment($request->user(), $clinicPatients)) {
            return $this->forbidden('You cannot view this clinic patient assignment');
        }

        return response()->json($clinicPatients);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ClinicPatients $clinicPatients)
    {
        $user = $request->user();

        if (!$this->hasMutationRole($user)) {
            return $this->forbidden('You are not authorized to manage clinic patients');
        }

        $clinicPatients->loadMissing('clinic');

        if (!$clinicPatients->clinic || !$this->canMutateClinic($user, $clinicPatients->clinic)) {
            return $this->forbidden('You cannot update this clinic patient assignment');
        }

        $validated = $request->validate([
            'clinic_id' => ['required', 'integer', 'exists:clinics,id'],
            'patient_id' => ['required', 'integer', 'exists:patients,id'],
        ]);

        $submittedClinic = Clinic::findOrFail($validated['clinic_id']);

        if (!$this->canMutateClinic($user, $submittedClinic)) {
            return $this->forbidden('You cannot move this assignment to another hospital');
        }

        $this->ensureAssignmentIsUnique(
            $validated['clinic_id'],
            $validated['patient_id'],
            $clinicPatients->id,
        );

        try {
            DB::transaction(function () use ($clinicPatients, $validated) {
                $clinicPatients->update($validated);
            });

            return response()->json($clinicPatients->fresh());
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Unable to update clinic patient assignment',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, ClinicPatients $clinicPatients)
    {
        $user = $request->user();

        if (!$this->hasMutationRole($user)) {
            return $this->forbidden('You are not authorized to manage clinic patients');
        }

        $clinicPatients->loadMissing('clinic');

        if (!$clinicPatients->clinic || !$this->canMutateClinic($user, $clinicPatients->clinic)) {
            return $this->forbidden('You cannot remove this clinic patient assignment');
        }

        try {
            DB::transaction(function () use ($clinicPatients) {
                $clinicPatients->delete();
            });

            return response()->json(null, 204);
        } catch (Exception $exception) {
            return response()->json([
                'message' => 'Unable to remove clinic patient assignment',
            ], 500);
        }
    }

    private function hasHospitalReadContext(User $user, ?string $role): bool
    {
        if (!$user->hospital_id) {
            return false;
        }

        return in_array($role, self::HOSPITAL_SCOPED_READ_ROLES, true)
            || ($role !== null && $role !== 'patient');
    }

    private function canReadAssignment(User $user, ClinicPatients $assignment): bool
    {
        $role = $user->role?->name;

        if ($role === 'super_admin') {
            return true;
        }

        if ($role === 'patient') {
            return Patient::where('user_id', $user->id)
                ->whereKey($assignment->patient_id)
                ->exists();
        }

        return $this->hasHospitalReadContext($user, $role)
            && $assignment->clinic
            && (int) $assignment->clinic->hospital_id === (int) $user->hospital_id;
    }

    private function hasMutationRole(User $user): bool
    {
        return in_array($user->role?->name, self::MUTATION_ROLES, true);
    }

    private function canMutateClinic(User $user, Clinic $clinic): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return in_array($user->role?->name, ['hospital_admin', 'receptionist'], true)
            && $user->hospital_id
            && (int) $user->hospital_id === (int) $clinic->hospital_id;
    }

    private function ensureAssignmentIsUnique(
        int $clinicId,
        int $patientId,
        ?int $exceptAssignmentId = null,
    ): void {
        $query = ClinicPatients::where('clinic_id', $clinicId)
            ->where('patient_id', $patientId);

        if ($exceptAssignmentId) {
            $query->whereKeyNot($exceptAssignmentId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'patient_id' => ['This patient is already assigned to the selected clinic'],
            ]);
        }
    }

    private function forbidden(string $message)
    {
        return response()->json(['message' => $message], 403);
    }
}
