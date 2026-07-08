<?php

namespace App\Http\Controllers;

use App\Models\Pharmacy;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PharmacyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // get search, page size and page number from request
        $search = $request->input('search', '');
        $district = $request->input('district', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        // query pharmacies with pagination and search
        $query = Pharmacy::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($district) {
            $query->where('district', 'like', "%{$district}%");
        }

        $pharmacies = $query->paginate($pageSize, ['*'], 'page', $pageNumber);

        return response()->json($pharmacies);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // start transaction
        DB::beginTransaction();

        try {
            // validate request
            $request->validate([
                'name' => 'required|string|max:255|unique:pharmacies,name',
                'address' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:10',
                'email' => 'required|email|unique:pharmacies,email',
                'district' => 'nullable|string|max:100',
                'location_url' => 'nullable|url',
            ]);

            // get location_lat and location_lng from location_url
            $coordinates = HospitalController::getCoordinatesFromGoogleMapsShortUrl($request->location_url);
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

            // create pharmacy
            $pharmacy = Pharmacy::create($request->all());
            // commit transaction
            DB::commit();

            return response()->json($pharmacy, 201);
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
     * Display the specified resource.
     */
    public function show(Pharmacy $pharmacy)
    {
        return response()->json($pharmacy);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Pharmacy $pharmacy)
    {
        // start transaction
        DB::beginTransaction();

        try {
            // validate request
            $request->validate([
                'name' => 'required|string|max:255|unique:pharmacies,name,' . $pharmacy->id,
                'address' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:10',
                'email' => 'required|email|unique:pharmacies,email,' . $pharmacy->id,
                'district' => 'nullable|string|max:100',
                'location_url' => 'nullable|url',
            ]);

            // get location_lat and location_lng from location_url
            $coordinates = HospitalController::getCoordinatesFromGoogleMapsShortUrl($request->location_url);
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

            // update pharmacy
            $pharmacy->update($request->all());
            // commit transaction
            DB::commit();

            return response()->json($pharmacy);
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
     * Remove the specified resource from storage.
     */
    public function destroy(Pharmacy $pharmacy)
    {
        // start transaction
        DB::beginTransaction();

        try {
            $pharmacy->delete();
            // commit transaction
            DB::commit();

            return response()->json(null, 204);
        } catch (Exception $e) {
            // rollback transaction on error
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
