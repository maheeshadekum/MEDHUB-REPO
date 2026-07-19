<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\Hospital;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $query = Clinic::with(['hospital:id,name', 'doctor:id,name,email']);

        $scopedHospitalId = $this->getClinicOptionHospitalScope($request);

        if ($scopedHospitalId === false) {
            return response()->json(['message' => 'A hospital assignment is required'], 403);
        }

        // Filter by hospital if provided
        if (is_int($scopedHospitalId)) {
            if ($hospitalId !== null && (int) $hospitalId !== $scopedHospitalId) {
                return response()->json([
                    'message' => 'You can only view clinics in your hospital'
                ], 403);
            }

            $query->where('hospital_id', $scopedHospitalId);
        } elseif ($hospitalId) {
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
        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && !$user->hospital_id) {
            return response()->json(['message' => 'User does not belong to any hospital'], 403);
        }

        $validated = $this->validateClinic($request);
        $hospitalId = (int) $validated['hospital_id'];

        if ($role === 'hospital_admin' && (int) $user->hospital_id !== $hospitalId) {
            return response()->json(['message' => 'You can only create clinics in your hospital'], 403);
        }

        $this->validateDoctorForHospital((int) $validated['doctor_id'], $hospitalId);

        $clinic = DB::transaction(fn() => Clinic::create($validated));
        $clinic->load(['hospital:id,name', 'doctor:id,name,email']);

        return response()->json($clinic, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $clinic = Clinic::find($id);

        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
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
        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && !$user->hospital_id) {
            return response()->json(['message' => 'User does not belong to any hospital'], 403);
        }

        $clinic = Clinic::find($id);

        if (!$clinic) {
            return response()->json(['message' => 'Clinic not found'], 404);
        }

        $validated = $this->validateClinic($request);
        $hospitalId = (int) $validated['hospital_id'];

        if ($role === 'hospital_admin' && (
            (int) $user->hospital_id !== (int) $clinic->hospital_id ||
            (int) $user->hospital_id !== $hospitalId
        )) {
            return response()->json(['message' => 'You can only update clinics in your hospital'], 403);
        }

        $this->validateDoctorForHospital((int) $validated['doctor_id'], $hospitalId);

        DB::transaction(fn() => $clinic->update($validated));
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

        DB::beginTransaction();

        try {
            // Check if user has permission to delete clinic
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only delete clinics in your hospital'
                    ], 403);
                }
            }

            // Check if clinic has upcoming appointments
            $hasUpcomingAppointments = $clinic->clinicDates()
                ->where('date', '>=', now()->toDateString())
                ->where('status', 'scheduled')
                ->exists();

            if ($hasUpcomingAppointments) {
                return response()->json([
                    'message' => 'Cannot delete clinic with upcoming scheduled appointments'
                ], 400);
            }

            $clinic->delete();

            DB::commit();

            return response()->json(['message' => 'Clinic deleted successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
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

        $scopedHospitalId = $this->getClinicOptionHospitalScope($request);

        if ($scopedHospitalId === false) {
            return response()->json(['message' => 'A hospital assignment is required'], 403);
        }

        if (is_int($scopedHospitalId) && $scopedHospitalId !== (int) $hospitalId) {
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

        // Check if user has permission to view clinics
        if ($request->user()->role->name === 'hospital_admin') {
            $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
            $doctorHospitalIds = $doctor->hospitals->pluck('id')->toArray();

            if (!in_array($userHospitalId, $doctorHospitalIds)) {
                return response()->json([
                    'message' => 'You can only view clinics for doctors in your hospital'
                ], 403);
            }
        }

        $clinics = Clinic::with(['hospital:id,name'])
            ->where('doctor_id', $doctorId)
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

        $user = $request->user();
        $role = $user->role?->name;

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($role === 'hospital_admin' && (int) $user->hospital_id !== (int) $hospitalId) {
            return response()->json(['message' => 'You can only view doctors in your hospital'], 403);
        }

        // Get doctors who belong to this hospital and have doctor role
        $doctors = User::select('id', 'name', 'email')
            ->where('hospital_id', $hospitalId)
            ->whereHas('role', function ($query) {
                $query->where('name', 'doctor');
            })
            ->where('status', 'working')
            ->when($request->input('search'), function ($query, $search) {
                $query->where(function ($doctorQuery) use ($search) {
                    $doctorQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                });
            })
            ->orderBy('name')
            ->get();

        return response()->json($doctors);
    }

    /**
     * Validate clinic data shared by create and update.
     *
     * @return array<string, mixed>
     */
    private function validateClinic(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'hospital_id' => 'required|integer|exists:hospitals,id',
            'description' => 'required|string|max:500',
            'doctor_id' => 'required|integer|exists:users,id',
            'location' => 'required|string|max:255',
            'total_hourly_tokens' => 'required|integer|min:1|max:1000',
            'self_hourly_tokens' => 'required|integer|min:1|max:1000|lte:total_hourly_tokens',
        ]);
    }

    /**
     * Ensure the selected user is a working doctor in the target hospital.
     */
    private function validateDoctorForHospital(int $doctorId, int $hospitalId): void
    {
        $doctorExists = User::whereKey($doctorId)
            ->where('hospital_id', $hospitalId)
            ->where('status', 'working')
            ->whereHas('role', function ($query) {
                $query->where('name', 'doctor');
            })
            ->exists();

        if (!$doctorExists) {
            throw ValidationException::withMessages([
                'doctor_id' => ['Select a working doctor assigned to the selected hospital.'],
            ]);
        }
    }

    /**
     * Resolve Hospital-scoped Clinic option access for approved staff roles.
     */
    private function getClinicOptionHospitalScope(Request $request): int|false|null
    {
        $user = $request->user();

        if (!in_array($user->role?->name, ['hospital_admin', 'receptionist'], true)) {
            return null;
        }

        return $user->hospital_id ? (int) $user->hospital_id : false;
    }
}
