<?php

namespace App\Http\Controllers;

use App\Models\ClinicPatients;
use App\Models\ClinicToken;
use App\Models\Hospital;
use App\Models\OpdToken;
use App\Models\Patient;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class HospitalController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function getHospitals(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        if ($search) {
            $hospitals = Hospital::where('name', 'like', '%' . $search . '%')
                ->orWhere('district', 'like', '%' . $search . '%')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        } else {
            $hospitals = Hospital::orderBy('updated_at', 'desc')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);
        }
        return response()->json($hospitals);
    }

    /**
     * Display the specified resource based on identifier.
     */
    public function showHospital($identifier, Request $request)
    {

        // find hospital by identifier
        $hospital = Hospital::where('identifier', $identifier)->first();

        // if hospital not found, return 404
        if (!$hospital) {
            return response()->json(['message' => 'Hospital not found'], 404);
        }

        // Cast boolean fields to true/false
        $hospital->is_inventory_activated = (bool) $hospital->is_inventory_activated;
        $hospital->is_appointment_activated = (bool) $hospital->is_appointment_activated;

        // Check if user is authenticated
        $user = Auth::guard('sanctum')->check() ? Auth::guard('sanctum')->user() : null;
        $userRole = null;
        $userId = null;
        $isReceptionist = false;

        if ($user) {
            $userRole = $user->role->name ?? $user->role;
            $userId = $user->id;
            $isReceptionist = $userRole === 'receptionist';
        }

        // Get upcoming scheduled clinic dates and OPD dates
        $hospitalData = $hospital->toArray();
        $hospitalData['clinics'] = $this->getFormattedClinics($hospital, $isReceptionist, $userId);
        $hospitalData['opd'] = $this->getFormattedOpdDates($hospital, $isReceptionist);

        return response()->json($hospitalData);
    }

    /**
     * Display the specified resource.
     */
    public function getHospital(Hospital $hospital, Request $request)
    {
        // Cast boolean fields to true/false
        $hospital->is_inventory_activated = (bool) $hospital->is_inventory_activated;
        $hospital->is_appointment_activated = (bool) $hospital->is_appointment_activated;

        // Check if user is authenticated
        $user = $request->user();
        $userRole = null;
        $userId = null;
        $isReceptionist = false;

        if ($user) {
            $userRole = $user->role->name ?? $user->role;
            $userId = $user->id;
            $isReceptionist = $userRole === 'receptionist';
        }

        // Get upcoming scheduled clinic dates and OPD dates
        $hospitalData = $hospital->toArray();
        $hospitalData['clinics'] = $this->getFormattedClinics($hospital, $isReceptionist, $userId);
        $hospitalData['opd'] = $this->getFormattedOpdDates($hospital, $isReceptionist);

        return response()->json($hospitalData);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storeHospital(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {

            // create identifier from name
            // all lowercase, replace spaces with dashes, remove special characters
            $identifier = strtolower($request->name);
            $identifier = preg_replace('/\s+/', '-', $identifier);
            $identifier = preg_replace('/[^a-z0-9\-]/', '', $identifier);
            $request->merge(['identifier' => $identifier]);

            // validate request
            $request->validate([
                'name' => 'required|string|max:255|unique:hospitals,name',
                'identifier' => 'required|string|max:255|unique:hospitals',
                'address' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:10',
                'email' => 'required|email|unique:hospitals,email',
                'district' => 'nullable|string|max:100',
                'location_url' => 'nullable|url',
            ]);

            // get location_lat and location_lng from location_url
            $coordinates = $this->getCoordinatesFromGoogleMapsShortUrl($request->location_url);
            if ($coordinates) {
                $request->merge([
                    'location_lat' => $coordinates['lat'],
                    'location_lng' => $coordinates['lng']
                ]);
            } else {
                return response()->json([
                    'message' => 'Invalid location URL'
                ], 400);
            }

            // create hospital
            $hospital = Hospital::create($request->all());

            // commit transaction
            DB::commit();

            return response()->json($hospital, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function updateHospital(Request $request, Hospital $hospital, $fromManage = false)
    {
        // start transaction
        DB::beginTransaction();

        try {
            // validate request
            $request->validate([
                'name' => 'required|string|max:255|unique:hospitals,name,' . $hospital->id,
                'address' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:10',
                'email' => 'required|email|unique:hospitals,email,' . $hospital->id,
                'district' => 'nullable|string|max:100',
                'location_url' => 'nullable|url',
            ]);

            // if fromManage, validate is_inventory_activated and is_appointment_activated
            if ($fromManage) {
                $request->validate([
                    'is_inventory_activated' => 'boolean',
                    'is_appointment_activated' => 'boolean',
                ]);
            }

            // get location_lat and location_lng from location_url
            $coordinates = $this->getCoordinatesFromGoogleMapsShortUrl($request->location_url);
            if ($coordinates) {
                $request->merge([
                    'location_lat' => $coordinates['lat'],
                    'location_lng' => $coordinates['lng']
                ]);
            } else {
                return response()->json([
                    'message' => 'Invalid location URL'
                ], 400);
            }

            $requestData = $request->all();
            unset($requestData['id']);

            // update hospital
            $hospital->update($requestData);

            // commit transaction
            DB::commit();

            return response()->json($hospital, 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Manage the specified resource in storage.
     */
    public function manageHospital(Request $request)
    {
        if ($request->user()->role?->name !== 'hospital_admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // get hospital by requested user's hospitals relationship
        $hospital = $request->user()->hospitals()->first();

        if (!$hospital) {
            return response()->json(['message' => 'Hospital not found for user'], 404);
        }

        return $this->updateHospitalSettings($request, $hospital);
    }

    /**
     * Update settings for an explicitly selected hospital.
     */
    public function updateHospitalSettings(Request $request, Hospital $hospital)
    {
        $user = $request->user();
        $role = $user->role?->name;

        if ($role === 'hospital_admin' && (int) $user->hospital_id !== (int) $hospital->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (!in_array($role, ['super_admin', 'hospital_admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:hospitals,name,' . $hospital->id,
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:10',
            'email' => 'required|email|unique:hospitals,email,' . $hospital->id,
            'district' => 'nullable|string|max:100',
            'location_url' => 'required|url',
            'is_inventory_activated' => 'required|boolean',
            'is_appointment_activated' => 'required|boolean',
        ]);

        $coordinates = $this->getCoordinatesFromGoogleMapsShortUrl($validated['location_url']);
        if (!$coordinates) {
            return response()->json(['message' => 'Invalid location URL'], 400);
        }

        $validated['location_lat'] = $coordinates['lat'];
        $validated['location_lng'] = $coordinates['lng'];

        DB::beginTransaction();

        try {
            $hospital->update($validated);
            DB::commit();

            return response()->json($hospital, 200);
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroyHospital(Hospital $hospital)
    {
        // start transaction
        DB::beginTransaction();

        try {
            // delete hospital
            $hospital->delete();

            // commit transaction
            DB::commit();

            return response()->json(['message' => 'Hospital deleted successfully'], 200);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get coordinates from Google Maps short URL.
     *
     * @param string $shortUrl
     * @return array|null
     */
    static function getCoordinatesFromGoogleMapsShortUrl($shortUrl)
    {
        // Follow redirects and get the final URL
        $response = Http::withOptions(['allow_redirects' => false])->get($shortUrl);

        // If the response is a redirect
        if ($response->status() == 302 || $response->status() == 301) {
            $finalUrl = $response->header('Location');

            // Try to match coordinates in the final URL
            preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl, $matches);

            if (count($matches) === 3) {
                return [
                    'lat' => $matches[1],
                    'lng' => $matches[2]
                ];
            }
        }

        return null;
    }

    /**
     * Get formatted clinics with upcoming scheduled dates and slots.
     *
     * @param Hospital $hospital
     * @param bool $isReceptionist
     * @param int|null $userId
     * @return array
     */
    private function getFormattedClinics(Hospital $hospital, bool $isReceptionist, ?int $userId = null): array
    {
        $clinics = $hospital->clinics()->with(['clinicDates' => function ($query) {
            $query->where('date', '>=', now()->toDateString())
                ->where('status', 'scheduled')
                ->orderBy('date')
                ->orderBy('start_time');
        }])->get();

        $formattedClinics = [];

        foreach ($clinics as $clinic) {
            // Check if user has access to this clinic
            $accessGranted = false;
            if ($userId) {
                // First, get the patient record for this user
                $patient = Patient::where('user_id', $userId)->first();

                if ($patient) {
                    // Check if patient has access through clinic_patients table
                    $accessGranted = ClinicPatients::where('patient_id', $patient->id)
                        ->where('clinic_id', $clinic->id)
                        ->exists();
                }
            }

            $clinicData = [
                'name' => $clinic->name,
                'description' => $clinic->description,
                'access_granted' => $accessGranted,
                'dates' => []
            ];

            foreach ($clinic->clinicDates as $clinicDate) {
                // Skip if required fields are missing
                if (!$clinicDate->date || !$clinicDate->start_time || !$clinicDate->end_time) {
                    continue;
                }

                try {
                    $date = $clinicDate->date->format('Y-m-d');
                    $slots = $this->generateTimeSlots(
                        $clinicDate->start_time,
                        $clinicDate->end_time,
                        $clinic->total_hourly_tokens ?? 10,
                        $clinic->self_hourly_tokens ?? 5,
                        $clinicDate->id,
                        'clinic',
                        $isReceptionist,
                        $date
                    );

                    if (!isset($clinicData['dates'][$date])) {
                        $clinicData['dates'][$date] = [];
                    }

                    $clinicData['dates'][$date] = array(
                        "slots" => array_merge($clinicData['dates'][$date]['slots'] ?? [], array_values($slots)),
                        "date_id" => $clinicDate->id,
                    );
                } catch (Exception $e) {
                    Log::error('Error processing clinic date', [
                        'clinic_date_id' => $clinicDate->id,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            $formattedClinics[] = $clinicData;
        }

        return $formattedClinics;
    }

    /**
     * Get formatted OPD dates with upcoming scheduled dates and slots.
     *
     * @param Hospital $hospital
     * @param bool $isReceptionist
     * @return array
     */
    private function getFormattedOpdDates(Hospital $hospital, bool $isReceptionist): array
    {
        $opdDates = $hospital->opdDates()
            ->where('date', '>=', now()->toDateString())
            ->where('status', 'scheduled')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $formattedOpd = [];

        foreach ($opdDates as $opdDate) {
            // Skip if required fields are missing
            if (!$opdDate->date || !$opdDate->start_time || !$opdDate->end_time) {
                continue;
            }

            try {
                $date = $opdDate->date->format('Y-m-d');

                // For OPD, we need to get default hourly tokens from hospital or use default values
                $totalHourlyTokens = 10; // Default value since OPD doesn't have these fields
                $selfHourlyTokens = 5;   // Default value since OPD doesn't have these fields

                $slots = $this->generateTimeSlots(
                    $opdDate->start_time,
                    $opdDate->end_time,
                    $totalHourlyTokens,
                    $selfHourlyTokens,
                    $opdDate->id,
                    'opd',
                    $isReceptionist,
                    $date
                );

                if (!isset($formattedOpd[$date])) {
                    $formattedOpd[$date] = [];
                }

                $formattedOpd[$date] = array(
                    "slots" => array_merge($formattedOpd[$date]['slots'] ?? [], array_values($slots)),
                    "date_id" => $opdDate->id,
                );
            } catch (Exception $e) {
                Log::error('Error processing OPD date', [
                    'opd_date_id' => $opdDate->id,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        return $formattedOpd;
    }

    /**
     * Generate time slots based on start and end time.
     *
     * @param string $startTime
     * @param string $endTime
     * @param int $totalHourlyTokens
     * @param int $selfHourlyTokens
     * @param int $dateId
     * @param string $type
     * @param bool $isReceptionist
     * @param string $date
     * @return array
     */
    public function generateTimeSlots(string $startTime, string $endTime, int $totalHourlyTokens, int $selfHourlyTokens, int $dateId, string $type, bool $isReceptionist, string $date): array
    {
        $slots = [];

        // Validate and sanitize time inputs
        if (empty($startTime) || empty($endTime)) {
            return $slots;
        }

        try {
            // Try different time formats
            $start = $this->parseTimeString($startTime);
            $end = $this->parseTimeString($endTime);

            if (!$start || !$end) {
                return $slots;
            }
        } catch (Exception $e) {
            // Log the error and return empty slots if time parsing fails
            Log::error('Time parsing error in generateTimeSlots', [
                'startTime' => $startTime,
                'endTime' => $endTime,
                'error' => $e->getMessage()
            ]);
            return $slots;
        }

        $slotNumber = 1;

        // Check if the date is today to filter out past time slots
        $isToday = $date === now('Asia/Colombo')->toDateString();
        $currentTime = now('Asia/Colombo');

        while ($start->lt($end)) {
            $slotEnd = $start->copy()->addHour();

            // If the slot end exceeds the actual end time, adjust it
            if ($slotEnd->gt($end)) {
                $slotEnd = $end->copy();
            }

            // If it's today and the slot start time has passed, skip this slot
            if ($isToday) {
                $slotStartDateTime = Carbon::parse($date . ' ' . $start->format('H:i:s'), 'Asia/Colombo');
                if ($slotStartDateTime->lte($currentTime)) {
                    $start->addHour();
                    $slotNumber++;
                    continue;
                }
            }

            // If the slot end exceeds the actual end time, adjust for partial slot
            if ($slotEnd->eq($end)) {
                $remainingMinutes = $start->diffInMinutes($end);
                $percentage = $remainingMinutes / 60; // Calculate percentage of full hour

                $availableSlots = (int) ceil($selfHourlyTokens * $percentage);
                $totalAvailableSlots = (int) ceil($totalHourlyTokens * $percentage);
            } else {
                $availableSlots = $selfHourlyTokens;
                $totalAvailableSlots = $totalHourlyTokens;
            }

            // Calculate used tokens for this slot
            $usedTokens = $this->getUsedTokensForSlot($dateId, $type, $start->format('H:i:s'), $slotEnd->format('H:i:s'));

            $slotData = [
                'start_time' => $start->format('H:i'),
                'end_time' => $slotEnd->format('H:i'),
                'available_slots' => max(0, $availableSlots - $usedTokens['self'])
            ];

            // Only receptionist gets total_available_slots
            if ($isReceptionist) {
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
     *
     * @param int $dateId
     * @param string $type
     * @param string $startTime
     * @param string $endTime
     * @return array
     */
    private function getUsedTokensForSlot(int $dateId, string $type, string $startTime, string $endTime): array
    {
        if ($type === 'clinic') {
            // For clinic tokens, clinic_id references clinic_dates table
            $tokens = ClinicToken::where('clinic_id', $dateId)
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
        } else {
            $tokens = OpdToken::where('opd_date_id', $dateId)
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
        }

        $selfUsed = $tokens->where('type', 'self')->count();
        $totalUsed = $tokens->count();

        return [
            'self' => $selfUsed,
            'total' => $totalUsed
        ];
    }

    /**
     * Parse time string with multiple format attempts.
     *
     * @param string $timeString
     * @return Carbon|null
     */
    public static function parseTimeString(string $timeString): ?Carbon
    {
        // If it's a full datetime string, extract just the time part
        if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2})$/', $timeString, $matches)) {
            $timeString = $matches[1];
        }

        // List of possible time formats
        $formats = ['H:i:s', 'H:i', 'H:i:s.u', 'h:i A', 'h:i:s A'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $timeString);
            } catch (Exception $e) {
                // Continue to next format
                continue;
            }
        }

        // If all formats fail, try parsing as a simple time
        try {
            return Carbon::parse($timeString);
        } catch (Exception $e) {
            Log::warning('Failed to parse time string', [
                'timeString' => $timeString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
