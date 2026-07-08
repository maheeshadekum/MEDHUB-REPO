<?php

namespace App\Http\Controllers;

use App\Models\ClinicToken;
use App\Models\ClinicDate;
use App\Models\Patient;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Mail\ReservationConfirmationMail;
use App\Models\ClinicPatients;
use Carbon\Carbon;

class ClinicTokenController extends Controller
{
    /**
     * Display a listing of clinic tokens.
     */
    public function index(Request $request)
    {
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);
        $date = $request->input('date');
        $type = $request->input('type');

        // Validate date format if provided
        if ($date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'Invalid date format. Use YYYY-MM-DD.'], 400);
        }

        $query = null;

        // get user role
        $userRole = $request->user()->role->name ?? null;

        // if role is patient, only show their own tokens
        if ($userRole === 'patient') {
            $patientId = Patient::where('user_id', $request->user()->id)->value('id');
            if (!$patientId) {
                return response()->json(['message' => 'Patient not found'], 404);
            }

            $query = ClinicToken::with([
                'clinicDate:id,clinic_id,date,start_time,end_time',
                'clinicDate.clinic:id,name,hospital_id',
                "clinicDate.clinic.hospital:id,name",
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
                'prescriptions.hospital:id,name'
            ])->where('patient_id', $patientId);
        } elseif ($userRole !== 'super_admin') {
            $query = ClinicToken::with([
                'clinicDate:id,clinic_id,date,start_time,end_time',
                'clinicDate.clinic:id,name,hospital_id',
                "clinicDate.clinic.hospital:id,name",
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
                'prescriptions.hospital:id,name'
            ])->whereHas('clinicDate.clinic.hospital', function ($hospitalQuery) use ($request) {
                $hospitalQuery->where('id', $request->user()->hospitals()->first()->id ?? null);
            });
        } else {
            // Super admins can see all tokens
            $query = ClinicToken::with([
                'clinicDate:id,clinic_id,date,start_time,end_time',
                'clinicDate.clinic:id,name,hospital_id',
                "clinicDate.clinic.hospital:id,name",
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
                'prescriptions.hospital:id,name'
            ]);
        }

        // Filter by clinic date if provided
        if ($date) {
            $query->whereHas('clinicDate', function ($clinicDateQuery) use ($date) {
                $clinicDateQuery->where('date', $date);
            });
        }

        // Filter by type if provided
        if ($type) {
            $query->where('type', $type);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('patient', function ($patientQuery) use ($search) {
                    $patientQuery->where('nic', 'like', '%' . $search . '%');
                })->orWhereHas('patient.user', function ($patientQuery) use ($search) {
                    $patientQuery->where('name', 'like', '%' . $search . '%')
                        ->orWhere('email', 'like', '%' . $search . '%');
                })->orWhereHas('clinicDate.clinic', function ($clinicQuery) use ($search) {
                    $clinicQuery->where('name', 'like', '%' . $search . '%');
                });
            });
        }

        $clinicTokens = $query->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($clinicTokens);
    }

    /**
     * Store a newly created clinic token (reservation).
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'clinic_date_id' => 'required|exists:clinic_dates,id',
                'patient_id' => 'required|exists:patients,id',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
            ]);

            $clinicDate = ClinicDate::with(['clinic'])->find($request->clinic_date_id);
            $patient = Patient::find($request->patient_id);

            // Check if patient exists
            if (!$patient) {
                return response()->json(['message' => 'Patient not found'], 404);
            }

            // Check patient enrolled to clinic 
            $patientEnrolled = ClinicPatients::where('clinic_id', $clinicDate->clinic_id)
                ->where('patient_id', $request->patient_id)
                ->exists();

            if (!$patientEnrolled) {
                return response()->json(['message' => 'Patient is not enrolled in this clinic'], 403);
            }

            // Check if user has permission
            if ($request->user()->role->name !== 'super_admin' && $request->user()->role->name !== 'patient') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinicDate->clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only create reservations for your hospital\'s clinics'
                    ], 403);
                }
            }

            // Determine reservation type
            $type = 'self'; // Default
            if ($request->user()->role->name !== 'patient') {
                $type = 'internal';
            }

            // Validate time slot is within clinic date time range
            if (
                strtotime($request->start_time) < strtotime($clinicDate->start_time) ||
                strtotime($request->end_time) > strtotime($clinicDate->end_time)
            ) {
                return response()->json([
                    'message' => 'Reservation time must be within clinic date time range'
                ], 400);
            }

            // calculate all slots for the given date
            $slots = $this->generateTimeSlots(
                $clinicDate->start_time,
                $clinicDate->end_time,
                $clinicDate->clinic->total_hourly_tokens, // Default total hourly tokens for clinic
                $clinicDate->clinic->self_hourly_tokens,  // Default self hourly tokens for
                $request->clinic_date_id,
                $request->user()->role->name === 'patient',
            );

            // Check if the requested time slot is available
            $requestedSlot = null;
            foreach ($slots as $slot) {
                if ($slot['start_time'] === $request->start_time && $slot['end_time'] === $request->end_time) {
                    $requestedSlot = $slot;
                    break;
                }
            }

            if (!$requestedSlot || $requestedSlot['available_slots'] <= 0) {
                return response()->json([
                    'message' => 'Selected time slot is not available'
                ], 400);
            }

            // Check available tokens
            $timeSlotDuration = (strtotime($request->end_time) - strtotime($request->start_time)) / 3600; // in hours
            $maxTokensForSlot = $type === 'self'
                ? $clinicDate->clinic->self_hourly_tokens * $timeSlotDuration
                : $clinicDate->clinic->total_hourly_tokens * $timeSlotDuration;

            $existingTokens = ClinicToken::where('clinic_id', $request->clinic_id)
                ->where('start_time', $request->start_time)
                ->where('end_time', $request->end_time)
                ->where('type', $type)
                ->count();

            if (($existingTokens + 1) > $maxTokensForSlot) {
                return response()->json([
                    'message' => 'Not enough available tokens for this time slot'
                ], 400);
            }

            // create token number
            $tokens_for_clinic = ClinicToken::where('clinic_id', $request->clinic_date_id);
            // get length of the tokens
            $tokenCount = $tokens_for_clinic->count();
            $tokenNumber = $tokenCount + 1;
            $dateOnly = Carbon::parse($clinicDate->date)->format('Y-m-d');
            $token = $dateOnly . '-' . str_pad($tokenNumber, 3, '0', STR_PAD_LEFT);

            // Create clinic token
            $clinicToken = ClinicToken::create([
                'clinic_id' => $request->clinic_date_id,
                'patient_id' => $request->patient_id,
                'type' => $type,
                'token_number' => $token,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
            ]);

            // Send confirmation email for self reservations
            if ($type === 'self') {
                // get user from patient id
                $user = User::where("id", $patient->user_id)->first();
                try {
                    Mail::to($user->email)->send(new ReservationConfirmationMail($clinicToken, 'clinic'));
                } catch (Exception $e) {
                    // Log email error but don't fail the reservation
                    Log::error('Failed to send reservation confirmation email: ' . $e->getMessage());
                }
            }

            $clinicToken->load([
                'clinicDate:id,clinic_id,date,start_time,end_time',
                'clinicDate.clinic:id,name',
                'patient:id'
            ]);

            DB::commit();

            return response()->json($clinicToken, 201);
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
     * Display the specified clinic token.
     */
    public function show($id)
    {
        $clinicToken = ClinicToken::with([
            'clinicDate:id,clinic_id,date,start_time,end_time',
            'clinicDate.clinic:id,name,hospital_id,total_hourly_tokens,self_hourly_tokens',
            'clinicDate.clinic.hospital:id,name',
            'patient:id,user_id',
            'patient.user:id,name,email'
        ])->find($id);

        if (!$clinicToken) {
            return response()->json(['message' => 'Clinic token not found'], 404);
        }

        return response()->json($clinicToken);
    }

    /**
     * Update the specified clinic token.
     */
    public function update(Request $request, $id)
    {
        $clinicToken = ClinicToken::find($id);

        if (!$clinicToken) {
            return response()->json(['message' => 'Clinic token not found'], 404);
        }

        DB::beginTransaction();

        try {
            $request->validate([
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
            ]);

            $clinicDate = ClinicDate::with(['clinic'])->find($clinicToken->clinic_id);

            // Add null check for safety
            if (!$clinicDate) {
                return response()->json([
                    'message' => 'Associated clinic date not found'
                ], 404);
            }

            // Check clinic date is not in the past
            $clinicDateOnly = Carbon::parse($clinicDate->date)->startOfDay(); // Explicitly set to start of day
            $today = now('Asia/Colombo')->startOfDay(); // Get today's date at start of day

            if ($clinicDateOnly->lt($today)) {
                return response()->json([
                    'message' => 'Cannot update reservation for a past clinic date'
                ], 400);
            }

            // validate start and end time not in the past
            $currentTime = now('Asia/Colombo');
            if (
                strtotime($request->start_time) < $currentTime->timestamp ||
                strtotime($request->end_time) < $currentTime->timestamp
            ) {
                return response()->json([
                    'message' => 'Reservation time cannot be in the past'
                ], 400);
            }

            // Validate time slot is within clinic date time range
            if (strtotime($request->start_time) < strtotime($clinicDate->start_time) || strtotime($request->end_time) > strtotime($clinicDate->end_time)) {
                return response()->json([
                    'message' => 'Reservation time must be within clinic date time range'
                ], 400);
            }

            // calculate all slots for the given date
            $slots = $this->generateTimeSlots(
                $clinicDate->start_time,
                $clinicDate->end_time,
                $clinicDate->clinic->total_hourly_tokens, // Default total hourly tokens for clinic
                $clinicDate->clinic->self_hourly_tokens,  // Default self hourly tokens for
                $clinicDate->id,
                $request->user()->role->name === 'patient',
            );

            // Check if the requested time slot is available
            $requestedSlot = null;
            foreach ($slots as $slot) {
                if ($slot['start_time'] === $request->start_time && $slot['end_time'] === $request->end_time) {
                    $requestedSlot = $slot;
                    break;
                }
            }

            if (!$requestedSlot || $requestedSlot['available_slots'] <= 0) {
                return response()->json([
                    'message' => 'Selected time slot is not available'
                ], 400);
            }

            $clinicToken->update($request->only(['tokens', 'start_time', 'end_time']));
            $clinicToken->load([
                'clinicDate:id,clinic_id,date,start_time,end_time',
                'clinicDate.clinic:id,name',
                'patient:id,user_id,nic,name',
                'patient.user:id,email,name',
                'prescriptions'
            ]);

            DB::commit();

            return response()->json($clinicToken);
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
     * Remove the specified clinic token.
     */
    public function destroy($id, Request $request)
    {
        $clinicToken = ClinicToken::with(['clinicDate.clinic'])->find($id);

        if (!$clinicToken) {
            return response()->json(['message' => 'Clinic token not found'], 404);
        }

        DB::beginTransaction();

        try {
            // Check if user has permission
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $clinicToken->clinicDate->clinic->hospital_id) {
                    return response()->json([
                        'message' => 'You can only cancel reservations for your hospital\'s clinics'
                    ], 403);
                }
            }

            $clinicToken->delete();

            DB::commit();

            return response()->json(['message' => 'Clinic reservation cancelled successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get available time slots for a clinic date.
     */
    public function getAvailableSlots($clinicDateId, Request $request)
    {
        $clinicDate = ClinicDate::with(['clinic'])->where('status', 'scheduled')->find($clinicDateId);

        if (!$clinicDate) {
            return response()->json(['message' => 'Clinic date not found'], 404);
        }

        $userRole = $request->user()->role->name ?? null;
        $isReceptionist = $userRole === 'patient';

        // Generate time slots (similar to HospitalController logic)
        $slots = $this->generateTimeSlots(
            $clinicDate->start_time,
            $clinicDate->end_time,
            $clinicDate->clinic->total_hourly_tokens,
            $clinicDate->clinic->self_hourly_tokens,
            $clinicDateId,
            $isReceptionist
        );

        return response()->json(['slots' => array_merge($slots)]);
    }

    /**
     * Generate time slots for a clinic date.
     */
    private function generateTimeSlots($startTime, $endTime, $totalHourlyTokens, $selfHourlyTokens, $clinicDateId, $isPatient)
    {
        $slots = [];
        $start = HospitalController::parseTimeString($startTime);
        $end = HospitalController::parseTimeString($endTime);

        $slotNumber = 1;

        // Get current time in Sri Lankan timezone
        $currentTime = now('Asia/Colombo');

        // Get the clinic date to build proper datetime for comparison
        $clinicDate = ClinicDate::find($clinicDateId);
        $dateOnly = Carbon::parse($clinicDate->date)->format('Y-m-d');

        while ($start->lt($end)) {
            $slotEnd = $start->copy()->addHour();
            // Create proper datetime object for the slot in Sri Lankan timezone
            $slotStartDateTime = Carbon::parse($dateOnly . ' ' . $start->format('H:i:s'), 'Asia/Colombo');
            // check if start time is after the current time
            if ($slotStartDateTime->lte($currentTime)) {
                Log::info([
                    'message' => 'Skipping slot as start time is in the past',
                    'start_time' => $start->format('H:i'),
                    'current_time' => $currentTime->format('H:i')
                ]);
                $start->addHour();
                $slotNumber++;
                continue;
            }

            if ($slotEnd->gt($end)) {
                $remainingMinutes = $start->diffInMinutes($end);
                $percentage = $remainingMinutes / 60;

                $availableSlots = (int) ceil($selfHourlyTokens * $percentage);
                $totalAvailableSlots = (int) ceil($totalHourlyTokens * $percentage);

                $slotEnd = $end->copy();
            } else {
                $availableSlots = $selfHourlyTokens;
                $totalAvailableSlots = $totalHourlyTokens;
            }

            // Calculate used tokens for this slot
            $usedTokens = $this->getUsedTokensForSlot($clinicDateId, $start->format('H:i:s'), $slotEnd->format('H:i:s'));

            $slotData = [
                'start_time' => $start->format('H:i'),
                'end_time' => $slotEnd->format('H:i'),
                'available_slots' => max(0, $availableSlots - $usedTokens['self'])
            ];

            if (!$isPatient) {
                $slotData['total_available_slots'] = max(0, $totalAvailableSlots - $usedTokens['total']);
            }

            $slots[$slotNumber] = $slotData;

            $start->addHour();
            $slotNumber++;
        }

        return $slots;
    }

    /**
     * Get used tokens for a specific time slot.
     */
    private function getUsedTokensForSlot($clinicDateId, $startTime, $endTime)
    {
        $tokens = ClinicToken::where('clinic_id', $clinicDateId)
            ->where(function ($query) use ($startTime, $endTime) {
                $query->where(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '>=', $startTime)
                        ->where('start_time', '<', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    $q->where('end_time', '>', $startTime)
                        ->where('end_time', '<=', $endTime);
                })->orWhere(function ($q) use ($startTime, $endTime) {
                    $q->where('start_time', '<=', $startTime)
                        ->where('end_time', '>=', $endTime);
                });
            })
            ->get();

        $selfUsed = $tokens->where('type', 'self')->count();
        $totalUsed = $tokens->count();

        return [
            'self' => $selfUsed,
            'total' => $totalUsed
        ];
    }
}
