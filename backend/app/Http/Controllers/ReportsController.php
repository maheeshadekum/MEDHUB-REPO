<?php

namespace App\Http\Controllers;

use App\Models\Reports;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReportsController extends Controller
{
    // add reports
    // there is file handling in this function
    public function addReport(Request $request)
    {
        try {
            // start transaction
            DB::beginTransaction();
            $request->validate([
                'prescription_id' => 'required|exists:prescriptions,id',
                'report_type' => 'required|string|max:255',
                'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:2048', // Adjust file types and size as needed
            ]);

            if ($request->hasFile('file')) {
                // Get the file from the request
                $file = $request->file('file');

                // Generate a unique filename
                $filename = time() . '_' . $file->getClientOriginalName();

                // Store the file in the public disk (you can change the disk as needed)
                $path = $file->storeAs('reports', $filename, 'public');

                // Update the report's file path
                $file_path = Storage::url($path);

                // Create report
                $report = Reports::create([
                    'prescription_id' => $request->input('prescription_id'),
                    'report_type' => $request->input('report_type'),
                    'file_path' => $file_path,
                ]);

                // commit transaction
                DB::commit();

                return response()->json($report, 201);
            } else {
                return response()->json(['message' => 'File is required'], 400);
            }
        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'message' => $e->validator->errors()->first()
            ], 400);
        } catch (Exception $e) {
            // Rollback transaction in case of error
            DB::rollBack();
            return response()->json(['message' => 'Error creating report: ' . $e->getMessage()], 500);
        }
    }
}
