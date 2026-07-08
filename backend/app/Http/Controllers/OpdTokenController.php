<?php

namespace App\Http\Controllers;

use App\Models\OpdToken;
use App\Models\OpdDate;
use App\Models\Patient;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Mail\ReservationConfirmationMail;
use App\Models\User;
use Carbon\Carbon;

class OpdTokenController extends Controller
{
    /**
     * Display a listing of OPD tokens.
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

            $query = OpdToken::with([
                'opdDate:id,hospital_id,date,start_time,end_time',
                'opdDate.hospital:id,name',
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
            ])->where('patient_id', $patientId);
        } elseif ($userRole !== 'super_admin') {
            $query = OpdToken::with([
                'opdDate:id,hospital_id,date,start_time,end_time',
                'opdDate.hospital:id,name',
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
            ])->whereHas('opdDate.hospital', function ($hospitalQuery) use ($request) {
                $hospitalQuery->where('id', $request->user()->hospitals()->first()->id ?? null);
            });
        } else {
            // Super admins can see all tokens
            $query = OpdToken::with([
                'opdDate:id,hospital_id,date,start_time,end_time',
                'opdDate.hospital:id,name',
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
                'prescriptions',
                'prescriptions.medicines' => function ($query) {
                    $query->select('id', 'name', 'dosage', 'frequency', 'days_supply', 'is_external', 'name_of_external_medicine', 'duration', 'prescription_id');
                },
                'prescriptions.doctor:id,name',
                'prescriptions.pharmacist:id,name',
            ]);
        }

        // Filter by OPD date if provided
        if ($date) {
            $query->whereHas('opdDate', function ($opdDateQuery) use ($date) {
                $opdDateQuery->where('date', $date);
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
                })->orWhereHas('opdDate.hospital', function ($hospitalQuery) use ($search) {
                    $hospitalQuery->where('name', 'like', '%' . $search . '%');
                });
            });
        }

        $opdTokens = $query->orderBy('created_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($opdTokens);
    }

    /**
     * Store a newly created OPD token (reservation).
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            $request->validate([
                'opd_date_id' => 'required|exists:opd_dates,id',
                'patient_id' => 'required|exists:patients,id',
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
            ]);

            $opdDate = OpdDate::with(['hospital'])->find($request->opd_date_id);
            $patient = Patient::find($request->patient_id);

            // Check if user has permission
            if ($request->user()->role->name !== 'patient' && $request->user()->role->name !== 'super_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $opdDate->hospital_id) {
                    return response()->json([
                        'message' => 'You can only create reservations for your hospital\'s OPD'
                    ], 403);
                }
            }

            // Determine reservation type
            $type = 'self'; // Default
            if ($request->user()->role->name !== 'patient') {
                $type = 'internal';
            }

            // Validate time slot is within OPD date time range
            if (
                strtotime($request->start_time) < strtotime($opdDate->start_time) ||
                strtotime($request->end_time) > strtotime($opdDate->end_time)
            ) {
                return response()->json([
                    'message' => 'Reservation time must be within OPD date time range'
                ], 400);
            }

            // calculate all slots for the given date
            $slots = $this->generateTimeSlots(
                $opdDate->start_time,
                $opdDate->end_time,
                10, // Default total hourly tokens for OPD
                5,  // Default self hourly tokens for OPD
                $request->opd_date_id,
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
            };

            // Check available tokens (using default values for OPD)
            $totalHourlyTokens = 10; // Default for OPD
            $selfHourlyTokens = 5;   // Default for OPD

            $maxTokensForSlot = $type === 'self' ? $selfHourlyTokens : $totalHourlyTokens;

            $existingTokens = OpdToken::where('opd_date_id', $request->opd_date_id)
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
            $tokens_for_clinic = OpdToken::where('opd_date_id', $request->opd_date_id);
            // get length of the tokens
            $tokenCount = $tokens_for_clinic->count();
            $tokenNumber = $tokenCount + 1;
            $dateOnly = Carbon::parse($opdDate->date)->format('Y-m-d');
            $token = $dateOnly . '-' . str_pad($tokenNumber, 3, '0', STR_PAD_LEFT);

            // Create OPD token
            $opdToken = OpdToken::create([
                'opd_date_id' => $request->opd_date_id,
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
                    Mail::to($user->email)->send(new ReservationConfirmationMail($opdToken, 'opd'));
                } catch (Exception $e) {
                    // Log email error but don't fail the reservation
                    Log::error('Failed to send reservation confirmation email: ' . $e->getMessage());
                }
            }

            $opdToken->load([
                'opdDate:id,hospital_id,date,start_time,end_time',
                'opdDate.hospital:id,name',
                'patient:id',
            ]);

            DB::commit();

            return response()->json($opdToken, 201);
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
     * Display the specified OPD token.
     */
    public function show($id)
    {
        $opdToken = OpdToken::with([
            'opdDate:id,hospital_id,date,start_time,end_time',
            'opdDate.hospital:id,name,address,phone,email',
            'patient:id,user_id',
            'patient.user:id,name,email'
        ])->find($id);

        if (!$opdToken) {
            return response()->json(['message' => 'OPD token not found'], 404);
        }

        return response()->json($opdToken);
    }

    /**
     * Update the specified OPD token.
     */
    public function update(Request $request, $id)
    {
        $opdToken = OpdToken::find($id);

        if (!$opdToken) {
            return response()->json(['message' => 'OPD token not found'], 404);
        }

        DB::beginTransaction();

        try {
            $request->validate([
                'start_time' => 'required|date_format:H:i',
                'end_time' => 'required|date_format:H:i|after:start_time',
            ]);

            $opdDate = OpdDate::with(['hospital'])->find($opdToken->opd_date_id);

            // Add null check for safety
            if (!$opdDate) {
                return response()->json([
                    'message' => 'Associated OPD date not found'
                ], 404);
            }

            // Check OPD date is not in the past
            $opdDateOnly = Carbon::parse($opdDate->date)->startOfDay(); // Explicitly set to start of day
            $today = now('Asia/Colombo')->startOfDay(); // Get today's date at start of day

            if ($opdDateOnly->lt($today)) {
                return response()->json([
                    'message' => 'Cannot update reservation for a past OPD date'
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

            // Validate time slot is within OPD date time range
            if (strtotime($request->start_time) < strtotime($opdDate->start_time) || strtotime($request->end_time) > strtotime($opdDate->end_time)) {
                return response()->json([
                    'message' => 'Reservation time must be within OPD date time range'
                ], 400);
            }

            // calculate all slots for the given date
            $slots = $this->generateTimeSlots(
                $opdDate->start_time,
                $opdDate->end_time,
                10, // Default total hourly tokens for OPD
                5,  // Default self hourly tokens for OPD
                $request->opd_date_id,
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

            $opdToken->update($request->only(['tokens', 'start_time', 'end_time']));
            $opdToken->load([
                'opdDate:id,hospital_id,date,start_time,end_time',
                'opdDate.hospital:id,name',
                'patient:id,user_id,nic,name',
                'patient.user:id,name,email',
            ]);

            DB::commit();

            return response()->json($opdToken);
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
     * Remove the specified OPD token.
     */
    public function destroy($id, Request $request)
    {
        $opdToken = OpdToken::with(['opdDate.hospital'])->find($id);

        if (!$opdToken) {
            return response()->json(['message' => 'OPD token not found'], 404);
        }

        DB::beginTransaction();

        try {
            // Check if user has permission
            if ($request->user()->role->name === 'hospital_admin') {
                $userHospitalId = $request->user()->hospitals()->first()->id ?? null;
                if ($userHospitalId !== $opdToken->opdDate->hospital_id) {
                    return response()->json([
                        'message' => 'You can only cancel reservations for your hospital\'s OPD'
                    ], 403);
                }
            }

            $opdToken->delete();

            DB::commit();

            return response()->json(['message' => 'OPD reservation cancelled successfully']);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get available time slots for an OPD date.
     */
    public function getAvailableSlots($opdDateId, Request $request)
    {
        $opdDate = OpdDate::with(['hospital'])->where('status', 'scheduled')->find($opdDateId);

        if (!$opdDate) {
            return response()->json(['message' => 'OPD date not found'], 404);
        }

        $userRole = $request->user()->role->name ?? null;
        $isPatient = $userRole === 'patient';

        // Use default token values for OPD
        $totalHourlyTokens = 10;
        $selfHourlyTokens = 5;

        // Generate time slots
        $slots = $this->generateTimeSlots(
            $opdDate->start_time,
            $opdDate->end_time,
            $totalHourlyTokens,
            $selfHourlyTokens,
            $opdDateId,
            $isPatient
        );

        return response()->json(['slots' => array_merge($slots)]);
    }

    /**
     * Generate time slots for an OPD date.
     */
    private function generateTimeSlots($startTime, $endTime, $totalHourlyTokens, $selfHourlyTokens, $opdDateId, $isPatient)
    {
        $slots = [];
        $start = HospitalController::parseTimeString($startTime);
        $end = HospitalController::parseTimeString($endTime);

        $slotNumber = 1;

        while ($start->lt($end)) {
            $slotEnd = $start->copy()->addHour();

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
            $usedTokens = $this->getUsedTokensForSlot($opdDateId, $start->format('H:i:s'), $slotEnd->format('H:i:s'));

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
    private function getUsedTokensForSlot($opdDateId, $startTime, $endTime)
    {
        $tokens = OpdToken::where('opd_date_id', $opdDateId)
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
