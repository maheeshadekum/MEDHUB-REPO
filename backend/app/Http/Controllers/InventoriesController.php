<?php

namespace App\Http\Controllers;

use App\Models\Inventories;
use App\Models\InventoryBatch;
use App\Models\Hospital;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoriesController extends Controller
{
    // get inventories by hospital with nearby hospital suggestions
    public function getInventories(Request $request, $hospital)
    {
        try {
            // Find hospital by ID or identifier
            $hospitalRecord = Hospital::where('id', $hospital)
                ->orWhere('identifier', $hospital)
                ->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $search = $request->input('search', '');
            $pageSize = $request->input('size', 20);
            $pageNumber = $request->input('page', 1);

            // Get current user to check if they are a pharmacist
            $user = $request->user();
            $isPharmacist = $user && $user->hasRole('pharmacist');

            $query = Inventories::where('hospital_id', $hospitalRecord->id)
                ->with(['batches' => function ($batchQuery) {
                    $batchQuery->where('quantity', '>', 0)->orderBy('expiry_date', 'asc');
                }]);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('drug_name', 'like', '%' . $search . '%')
                        ->orWhere('brand_name', 'like', '%' . $search . '%')
                        ->orWhere('type', 'like', '%' . $search . '%');
                });
            }

            $inventories = $query->orderBy('updated_at', 'desc')
                ->paginate($pageSize, ['*'], 'page', $pageNumber);

            // Transform the data based on user role
            $inventories->getCollection()->transform(function ($inventory) use ($isPharmacist, $hospitalRecord, $search) {
                $totalQuantity = $inventory->batches->sum('quantity');

                $inventoryData = [
                    'id' => $inventory->id,
                    'drug_name' => $inventory->drug_name,
                    'brand_name' => $inventory->brand_name,
                    'weight' => $inventory->weight,
                    'type' => $inventory->type,
                    'available_quantity' => $totalQuantity,
                ];

                if ($isPharmacist) {
                    // Pharmacists can see all batch details
                    $inventoryData['batches'] = $inventory->batches->map(function ($batch) {
                        return [
                            'id' => $batch->id,
                            'batch_number' => $batch->batch_number,
                            'expiry_date' => $batch->expiry_date,
                            'quantity' => $batch->quantity,
                        ];
                    });
                } else {
                    // Non-pharmacists get empty batches array
                    $inventoryData['batches'] = [];
                }

                // Add nearby suggestions for each inventory item
                if ($search && $totalQuantity === 0) {
                    // If this specific medicine has 0 quantity, find nearby hospitals with this medicine
                    $inventoryData['nearby_suggestions'] = $this->findNearbyHospitalsWithSpecificMedicine(
                        $hospitalRecord,
                        $inventory->drug_name,
                        $inventory->brand_name,
                        $inventory->type
                    );
                } else {
                    // If medicine is available, add empty array
                    $inventoryData['nearby_suggestions'] = [];
                }

                return $inventoryData;
            });

            return response()->json($inventories);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error fetching inventories', 'error' => $e->getMessage()], 500);
        }
    }


    public function getInventory(Request $request)
    {
        if ($request->user()->hasRole('super_admin') && !$request->user()->hospital_id) {
            return $this->getAllInventories($request);
        }

        return $this->getInventories($request, $request->user()->hospital_id);
    }

    private function getAllInventories(Request $request)
    {
        $search = $request->input('search', '');
        $pageSize = $request->input('size', 20);
        $pageNumber = $request->input('page', 1);

        $query = Inventories::with([
            'hospital:id,name',
            'batches' => function ($batchQuery) {
                $batchQuery->where('quantity', '>', 0)->orderBy('expiry_date', 'asc');
            }
        ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('drug_name', 'like', '%' . $search . '%')
                    ->orWhere('brand_name', 'like', '%' . $search . '%')
                    ->orWhere('type', 'like', '%' . $search . '%');
            });
        }

        $inventories = $query->orderBy('updated_at', 'desc')
            ->paginate($pageSize, ['*'], 'page', $pageNumber);

        $inventories->getCollection()->transform(function ($inventory) {
            return [
                'id' => $inventory->id,
                'drug_name' => $inventory->drug_name,
                'brand_name' => $inventory->brand_name,
                'weight' => $inventory->weight,
                'type' => $inventory->type,
                'hospital_id' => $inventory->hospital_id,
                'hospital' => $inventory->hospital,
                'available_quantity' => $inventory->batches->sum('quantity'),
                'batches' => [],
                'nearby_suggestions' => [],
            ];
        });

        return response()->json($inventories);
    }

    /**
     * Find nearby hospitals that have a specific medicine (exact match)
     */
    private function findNearbyHospitalsWithSpecificMedicine($currentHospital, $drugName, $brandName, $type)
    {
        // Check if current hospital has location data
        if (!$currentHospital->location_lat || !$currentHospital->location_lng) {
            return [];
        }

        // Get hospitals with the exact medicine (excluding current hospital)
        $hospitalsWithMedicine = DB::table('hospitals')
            ->join('inventories', 'hospitals.id', '=', 'inventories.hospital_id')
            ->join('inventory_batch', 'inventories.id', '=', 'inventory_batch.inventory_id')
            ->where('hospitals.id', '!=', $currentHospital->id)
            ->where('inventories.drug_name', $drugName)
            ->where('inventories.brand_name', $brandName)
            ->where('inventories.type', $type)
            ->where('inventory_batch.quantity', '>', 0) // Only hospitals with available stock
            ->whereNotNull('hospitals.location_lat')
            ->whereNotNull('hospitals.location_lng')
            ->select(
                'hospitals.id',
                'hospitals.name',
                'hospitals.identifier',
                'hospitals.address',
                'hospitals.phone',
                'hospitals.location_lat',
                'hospitals.location_lng',
                'hospitals.location_url',
                DB::raw('SUM(inventory_batch.quantity) as total_quantity')
            )
            ->groupBy(
                'hospitals.id',
                'hospitals.name',
                'hospitals.identifier',
                'hospitals.address',
                'hospitals.phone',
                'hospitals.location_lat',
                'hospitals.location_lng',
                'hospitals.location_url',
            )
            ->get();

        if ($hospitalsWithMedicine->isEmpty()) {
            return [];
        }

        // Calculate distances and sort by distance
        $currentLat = $currentHospital->location_lat;
        $currentLng = $currentHospital->location_lng;

        $hospitalsWithDistance = $hospitalsWithMedicine->map(function ($hospital) use ($currentLat, $currentLng, $drugName, $brandName, $type) {
            $distance = $this->calculateDistance(
                $currentLat,
                $currentLng,
                $hospital->location_lat,
                $hospital->location_lng
            );

            return [
                'hospital' => [
                    'id' => $hospital->id,
                    'name' => $hospital->name,
                    'identifier' => $hospital->identifier,
                    'address' => $hospital->address,
                    'phone' => $hospital->phone,
                    'location_url' => $hospital->location_url,
                ],
                'distance_km' => round($distance, 2),
                'total_quantity' => $hospital->total_quantity,
                'medicine' => [
                    'drug_name' => $drugName,
                    'type' => $type,
                    'brand_name' => $brandName,
                ]
            ];
        });

        // Sort by distance and take top 3
        return $hospitalsWithDistance
            ->sortBy('distance_km')
            ->take(3)
            ->values()
            ->toArray();
    }

    /**
     * Calculate distance between two points using Haversine formula
     * Returns distance in kilometers
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $earthRadius = 6371; // Earth's radius in kilometers

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    // get inventory by ID and hospital
    public function getInventoryById($hospital, $id)
    {
        try {
            // Find hospital by ID or identifier
            $hospitalRecord = Hospital::where('id', $hospital)
                ->orWhere('identifier', $hospital)
                ->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $inventory = Inventories::where('hospital_id', $hospitalRecord->id)
                ->where('id', $id)
                ->first();

            if (!$inventory) {
                return response()->json(['message' => 'Inventory not found'], 404);
            }

            return response()->json($inventory);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error fetching inventory', 'error' => $e->getMessage()], 500);
        }
    }

    // get near expiry inventories by hospital
    public function getNearExpiryInventories(Request $request)
    {
        try {
            if ($request->user()->hasRole('super_admin') && !$request->user()->hospital_id) {
                return response()->json([
                    "thirty_days" => [],
                    "fourteen_days" => []
                ]);
            }

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            // Get current date
            $currentDate = now();
            $thirtyDaysFromNow = now()->addDays(30);
            $fourteenDaysFromNow = now()->addDays(14);

            // Get batches that are near expiry (14 days from now)
            $nearExpiryBatchesFourteenDays = InventoryBatch::whereHas('inventory', function ($query) use ($hospitalRecord) {
                $query->where('hospital_id', $hospitalRecord->id);
            })
                ->where('expiry_date', '>', $currentDate)
                ->where('expiry_date', '<=', $fourteenDaysFromNow)
                ->where('quantity', '>', 0)
                ->with('inventory')
                ->orderBy('expiry_date', 'asc')
                ->get();

            $nearExpiryBatchesFourteenDays->transform(function ($batch) {
                return [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'quantity' => $batch->quantity,
                    'days_to_expiry' => now()->diffInDays($batch->expiry_date),
                    'medicine' => [
                        'id' => $batch->inventory->id,
                        'drug_name' => $batch->inventory->drug_name,
                        'brand_name' => $batch->inventory->brand_name,
                        'type' => $batch->inventory->type,
                        'weight' => $batch->inventory->weight,
                    ]
                ];
            });

            // Get batches that are near expiry (30 days from 14 days)
            $nearExpiryBatchesThirtyDays = InventoryBatch::whereHas('inventory', function ($query) use ($hospitalRecord) {
                $query->where('hospital_id', $hospitalRecord->id);
            })
                ->where('expiry_date', '>', $fourteenDaysFromNow)
                ->where('expiry_date', '<=', $thirtyDaysFromNow)
                ->where('quantity', '>', 0)
                ->with('inventory')
                ->orderBy('expiry_date', 'asc')
                ->get();

            // Transform the data to include medicine info
            $nearExpiryBatchesThirtyDays->transform(function ($batch) {
                return [
                    'batch_id' => $batch->id,
                    'batch_number' => $batch->batch_number,
                    'expiry_date' => $batch->expiry_date,
                    'quantity' => $batch->quantity,
                    'days_to_expiry' => now()->diffInDays($batch->expiry_date),
                    'medicine' => [
                        'id' => $batch->inventory->id,
                        'drug_name' => $batch->inventory->drug_name,
                        'brand_name' => $batch->inventory->brand_name,
                        'type' => $batch->inventory->type,
                        'weight' => $batch->inventory->weight,
                    ]
                ];
            });

            return response()->json([
                "thirty_days" => $nearExpiryBatchesThirtyDays,
                "fourteen_days" => $nearExpiryBatchesFourteenDays
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error fetching near expiry inventories', 'error' => $e->getMessage()], 500);
        }
    }

    // get low stock inventories by hospital
    public function getLowStockInventories(Request $request)
    {
        try {
            if ($request->user()->hasRole('super_admin') && !$request->user()->hospital_id) {
                return response()->json([
                    "low_stock_inventories" => []
                ]);
            }

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            // low stock threshold can be adjusted as needed
            $lowStockThreshold = 10000;

            // Get low stock inventories for the hospital (considering total quantity across all batches)
            // Also need to add total remaining quantity across all batches
            $lowStockInventories = Inventories::where('hospital_id', $hospitalRecord->id)
                ->whereHas('batches', function ($query) {
                    $query->where('quantity', '>', 0);
                })
                ->with(['batches' => function ($batchQuery) {
                    $batchQuery->where('quantity', '>', 0)->orderBy('expiry_date', 'asc');
                }])
                ->get()
                ->filter(function ($inventory) use ($lowStockThreshold) {
                    return $inventory->batches->sum('quantity') <= $lowStockThreshold;
                })
                ->values()
                ->transform(function ($inventory) {
                    $inventory->total_available_quantity = $inventory->batches->sum('quantity');
                    return $inventory;
                });

            return response()->json([
                "low_stock_inventories" => $lowStockInventories
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => 'Error fetching low stock inventories', 'error' => $e->getMessage()], 500);
        }
    }

    // create inventory for specific hospital
    public function addInventory(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $request->validate([
                'drug_name' => 'required|string|max:255',
                'brand_name' => 'required|string|max:255',
                'weight' => 'nullable|numeric',
                'type' => 'required|string|max:100',
            ]);

            // Check if drug already exists for this hospital
            $existingInventory = Inventories::where('hospital_id', $hospitalRecord->id)
                ->where('drug_name', $request->input('drug_name'))
                ->where('brand_name', $request->input('brand_name'))
                ->where('type', $request->input('type'))
                ->first();

            if ($existingInventory) {
                return response()->json([
                    'message' => 'Inventory with this drug name, brand name and type already exists for this hospital'
                ], 400);
            }

            // create inventory
            $inventory = new Inventories([
                'hospital_id' => $hospitalRecord->id,
                'drug_name' => $request->drug_name,
                'brand_name' => $request->brand_name,
                'weight' => $request->weight,
                'type' => $request->type,
            ]);

            // save inventory
            $inventory->save();

            // commit inventory
            DB::commit();
            return response()->json([
                'message' => 'Inventory created successfully',
                'inventory' => $inventory
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error creating inventory', 'error' => $e->getMessage()], 500);
        }
    }

    // add batch to inventory
    public function addBatch(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $request->validate([
                'inventory_id' => 'required|integer|exists:inventories,id',
                'batch_number' => 'required|string|max:255',
                'expiry_date' => 'required|date',
                'quantity' => 'required|integer|min:1'
            ]);

            // Check if inventory belongs to user's hospital
            $inventory = Inventories::where('id', $request->inventory_id)
                ->where('hospital_id', $hospitalRecord->id)
                ->first();

            if (!$inventory) {
                return response()->json(['message' => 'Inventory not found or does not belong to your hospital'], 404);
            }

            // Check if batch with same number already exists for this inventory
            if ($request->batch_number) {
                $existingBatch = InventoryBatch::where('inventory_id', $request->inventory_id)
                    ->where('batch_number', $request->batch_number)
                    ->first();

                if ($existingBatch) {
                    return response()->json([
                        'message' => 'Batch with this number already exists for this medicine'
                    ], 400);
                }
            }

            // convert date with Carbon
            $expiryDate = Carbon::parse($request->expiry_date)->format('Y-m-d H:i:s');


            // create batch
            $batch = InventoryBatch::create([
                'inventory_id' => $request->inventory_id,
                'batch_number' => $request->batch_number,
                'expiry_date' => $expiryDate,
                'quantity' => $request->quantity
            ]);

            // commit transaction
            DB::commit();
            return response()->json([
                'message' => 'Batch created successfully',
                'batch' => $batch
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error creating batch', 'error' => $e->getMessage()], 500);
        }
    }

    // update batch
    public function updateBatch(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $batch = InventoryBatch::with('inventory')->find($id);

            if (!$batch) {
                return response()->json(['message' => 'Batch not found'], 404);
            }

            // Check if batch belongs to user's hospital
            if ($batch->inventory->hospital_id != $hospitalRecord->id) {
                return response()->json(['message' => 'Batch does not belong to your hospital'], 403);
            }

            $request->validate([
                'batch_number' => 'nullable|string|max:255',
                'expiry_date' => 'nullable|date',
                'quantity' => 'required|integer|min:0'
            ]);

            // Check for duplicate batch number (excluding current batch)
            if ($request->batch_number) {
                $existingBatch = InventoryBatch::where('inventory_id', $batch->inventory_id)
                    ->where('batch_number', $request->batch_number)
                    ->where('id', '!=', $id)
                    ->first();

                if ($existingBatch) {
                    return response()->json([
                        'message' => 'Batch with this number already exists for this inventory'
                    ], 400);
                }
            }

            // update batch
            $batch->update([
                'batch_number' => $request->batch_number,
                'expiry_date' => $request->expiry_date,
                'quantity' => $request->quantity
            ]);

            // commit transaction
            DB::commit();
            return response()->json(['message' => 'Batch updated successfully', 'batch' => $batch], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error updating batch', 'error' => $e->getMessage()], 500);
        }
    }

    // update inventory
    public function updateInventory(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $inventory = Inventories::where('hospital_id', $hospitalRecord->id)
                ->where('id', $id)
                ->first();

            if (!$inventory) {
                return response()->json(['message' => 'Inventory not found'], 404);
            }

            $request->validate([
                'drug_name' => 'required|string|max:255',
                'brand_name' => 'required|string|max:255',
                'weight' => 'nullable|numeric',
                'type' => 'required|string|max:100',
            ]);

            // Check for duplicate drug name, brand name and type (excluding current record)
            $existingInventory = Inventories::where('hospital_id', $hospitalRecord->id)
                ->where('drug_name', $request->input('drug_name'))
                ->where('brand_name', $request->input('brand_name'))
                ->where('type', $request->input('type'))
                ->where('id', '!=', $id)
                ->first();

            if ($existingInventory) {
                return response()->json([
                    'message' => 'Inventory with this drug name, brand name and type already exists for this hospital'
                ], 400);
            }

            // update inventory
            $inventory->update([
                'drug_name' => $request->drug_name,
                'brand_name' => $request->brand_name,
                'weight' => $request->weight,
                'type' => $request->type,
            ]);

            // commit transaction
            DB::commit();
            return response()->json(['message' => 'Inventory updated successfully', 'inventory' => $inventory], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error updating inventory', 'error' => $e->getMessage()], 500);
        }
    }

    // delete inventory (will cascade delete all batches)
    public function deleteInventory(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $inventory = Inventories::where('hospital_id', $hospitalRecord->id)
                ->where('id', $id)
                ->first();

            if (!$inventory) {
                return response()->json(['message' => 'Inventory not found'], 404);
            }

            $inventory->delete();

            // commit transaction
            DB::commit();
            return response()->json(['message' => 'Inventory and all associated batches deleted successfully'], 200);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error deleting inventory', 'error' => $e->getMessage()], 500);
        }
    }

    // delete batch
    public function deleteBatch(Request $request, $id)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            $batch = InventoryBatch::with('inventory')->find($id);

            if (!$batch) {
                return response()->json(['message' => 'Batch not found'], 404);
            }

            // Check if batch belongs to user's hospital
            if ($batch->inventory->hospital_id != $hospitalRecord->id) {
                return response()->json(['message' => 'Batch does not belong to your hospital'], 403);
            }

            $batch->delete();

            // commit transaction
            DB::commit();
            return response()->json(['message' => 'Batch deleted successfully'], 200);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error deleting batch', 'error' => $e->getMessage()], 500);
        }
    }

    // release medicine to prescription (simplified - only updates prescription status)
    public function releaseMedicine(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();

            // Find hospital by requested user's hospital ID
            $hospitalRecord = Hospital::where('id', $request->user()->hospital_id)->first();

            if (!$hospitalRecord) {
                return response()->json(['message' => 'Hospital not found'], 404);
            }

            // get pharmacist from request user
            $pharmacist = $request->user();

            // check user is pharmacist
            if (!$pharmacist->hasRole('pharmacist')) {
                return response()->json(['message' => 'You do not have permission to release medicines'], 403);
            }

            $request->validate([
                'prescription_id' => 'required|exists:prescriptions,id'
            ]);

            // check if pharmacist belongs to this hospital
            if ($pharmacist->hospital_id != $hospitalRecord->id) {
                return response()->json(['message' => 'You do not have access to this hospital\'s inventory'], 403);
            }

            // check if the prescription's hospital matches the current hospital
            $prescription = DB::table('prescriptions')
                ->where('id', $request->input('prescription_id'))
                ->where('hospital_id', $hospitalRecord->id)
                ->first();

            if (!$prescription) {
                return response()->json(['message' => 'Prescription not found or does not belong to this hospital'], 404);
            }

            // Check if prescription is already dispensed
            if ($prescription->status === 'dispensed') {
                return response()->json(['message' => 'Prescription is already dispensed'], 400);
            }

            // update prescription's status to 'dispensed' and add pharmacist_id
            DB::table('prescriptions')
                ->where('id', $request->input('prescription_id'))
                ->update([
                    'pharmacist_id' => $pharmacist->id,
                    'status' => 'dispensed',
                ]);

            // commit transaction
            DB::commit();
            return response()->json(['message' => 'Prescription marked as dispensed successfully'], 200);
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // rollback transaction
            DB::rollBack();
            return response()->json(['message' => 'Error updating prescription status', 'error' => $e->getMessage()], 500);
        }
    }
}
