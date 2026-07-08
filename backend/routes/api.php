<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CLIController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\InventoriesController;
use App\Http\Controllers\MedicinesController;
use App\Http\Controllers\PatientsController;
use App\Http\Controllers\PermissionsController;
use App\Http\Controllers\PrescriptionsController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\RolePermissionsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\ClinicDateController;
use App\Http\Controllers\ClinicPatientsController;
use App\Http\Controllers\ClinicTokenController;
use App\Http\Controllers\OpdDateController;
use App\Http\Controllers\OpdTokenController;
use App\Http\Controllers\PharmacyController;
use App\Http\Middleware\CheckAppKey;
use App\Http\Middleware\PermissionMiddleware;

/**
 * @unauthenticated
 */
Route::get("/", function () {
    return response()->json([
        "message" => "Welcome to the SimpLinkX API",
        "status" => "success"
    ]);
});

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/validate-reset-token', [AuthController::class, 'validateResetToken']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);


// public routes
Route::get('/hospitals', [HospitalController::class, 'getHospitals']);
Route::get('/hospitals/{identifier}', [HospitalController::class, 'showHospital']);
Route::get('/hospitals/single/{hospital}', [HospitalController::class, 'getHospital']);

Route::get('/inventory/{hospital}', [InventoriesController::class, 'getInventories']);
Route::get('/inventory/{hospital}/{id}', [InventoriesController::class, 'getInventoryById']);

Route::get('/pharmacies', [PharmacyController::class, 'index']);
Route::get('/pharmacies/single/{pharmacy}', [PharmacyController::class, 'show']);
Route::get('/pharmacies/{pharmacy}', [PharmacyController::class, 'show']);

// user routes
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::put('/user/name', [AuthController::class, 'updateName']);
    Route::put('/user/email', [AuthController::class, 'updateEmail']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);
});

// Protected routes
Route::middleware(['auth:sanctum'])->group(function () {
    // hospital routes
    Route::post('/hospitals', [HospitalController::class, 'storeHospital'])->middleware(PermissionMiddleware::class . ':create_hospitals');
    Route::post('/hospitals/manage', [HospitalController::class, 'manageHospital'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::put('/hospitals/{hospital}', [HospitalController::class, 'updateHospital'])->middleware(PermissionMiddleware::class . ':update_hospitals');

    // users routes
    Route::get('/users', [UsersController::class, 'users'])->middleware(PermissionMiddleware::class . ':view_users');
    Route::get('/users/{user}', [UsersController::class, 'getUser'])->middleware(PermissionMiddleware::class . ':view_users');
    Route::post('/users', [UsersController::class, 'createUser'])->middleware(PermissionMiddleware::class . ':create_users');
    Route::put('/users/{user}', [UsersController::class, 'updateUser'])->middleware(PermissionMiddleware::class . ':update_users');
    Route::delete('/users/{user}', [UsersController::class, 'deleteUser'])->middleware(PermissionMiddleware::class . ':update_users');
    Route::post('/users/status/{user}', [UsersController::class, 'updateStatus'])->middleware(PermissionMiddleware::class . ':update_users');
    Route::post('/users/hospital/{user}', [UsersController::class, 'assignHospital'])->middleware(PermissionMiddleware::class . ':update_users_hospital');

    // roles routes
    Route::get('/roles', [RolesController::class, 'getRoles'])->middleware(PermissionMiddleware::class . ':view_roles');
    Route::post('/roles', [RolesController::class, 'createRole'])->middleware(PermissionMiddleware::class . ':create_roles');
    Route::get('/roles/permissions', [RolePermissionsController::class, 'getRolePermissions'])->middleware(PermissionMiddleware::class . ':view_role_permissions');
    Route::post('roles/permissions/{id}', [RolePermissionsController::class, 'assignPermission'])->middleware(PermissionMiddleware::class . ':assign_permissions');
    Route::get('/roles/{role}', [RolesController::class, 'getRole'])->middleware(PermissionMiddleware::class . ':view_roles');
    Route::put('/roles/{role}', [RolesController::class, 'updateRole'])->middleware(PermissionMiddleware::class . ':update_roles');
    Route::delete('/roles/{role}', [RolesController::class, 'deleteRole'])->middleware(PermissionMiddleware::class . ':update_roles');

    // permissions routes
    Route::get('/permissions', [PermissionsController::class, 'getPermissions'])->middleware(PermissionMiddleware::class . ':view_permissions');
    Route::post('/permissions', [PermissionsController::class, 'createPermission'])->middleware(PermissionMiddleware::class . ':create_permissions');
    Route::put('/permissions/{id}', [PermissionsController::class, 'updatePermission'])->middleware(PermissionMiddleware::class . ':update_permissions');

    // patients routes
    Route::get('/patients', [PatientsController::class, 'getPatients'])->middleware(PermissionMiddleware::class . ':view_patients');
    Route::get('/patients/{nic}', [PatientsController::class, 'getPatientByNic'])->middleware(PermissionMiddleware::class . ':view_patients');
    Route::post('/patients', [PatientsController::class, 'createPatient'])->middleware(PermissionMiddleware::class . ':manage_patients');
    Route::put('/patients/{patient}', [PatientsController::class, 'updatePatient'])->middleware(PermissionMiddleware::class . ':manage_patients');

    // prescriptions routes
    Route::get('/prescriptions', [PrescriptionsController::class, 'getPrescriptions'])->middleware(PermissionMiddleware::class . ':view_prescriptions');
    Route::get('/prescriptions/{id}', [PrescriptionsController::class, 'getPrescriptionById'])->middleware(PermissionMiddleware::class . ':view_prescriptions');
    Route::post('/prescriptions', [PrescriptionsController::class, 'createPrescription'])->middleware(PermissionMiddleware::class . ':create_prescriptions');
    Route::put('/prescriptions/{id}', [PrescriptionsController::class, 'updatePrescription'])->middleware(PermissionMiddleware::class . ':update_prescriptions');
    Route::delete('/prescriptions/{id}', [PrescriptionsController::class, 'deletePrescription'])->middleware(PermissionMiddleware::class . ':delete_prescriptions');

    // medicines routes
    Route::post('/medicines', [MedicinesController::class, 'addMedicines'])->middleware(PermissionMiddleware::class . ':add_medicines');
    Route::post('/medicines/release', [MedicinesController::class, 'release'])->middleware(PermissionMiddleware::class . ':release_medicines');

    // reports routes
    Route::post('/reports', [ReportsController::class, 'addReport'])->middleware(PermissionMiddleware::class . ':add_reports');

    // inventory routes
    Route::get('/inventory', [InventoriesController::class, 'getInventory'])->middleware(PermissionMiddleware::class . ':view_inventories');
    Route::get('/inventories/near-expiry', [InventoriesController::class, 'getNearExpiryInventories'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::get('/inventories/low-stock', [InventoriesController::class, 'getLowStockInventories'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::post('/inventory', [InventoriesController::class, 'addInventory'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::post('/inventory/release', [InventoriesController::class, 'releaseMedicine'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::post('/inventory/batch', [InventoriesController::class, 'addBatch'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::put('/inventory/{id}', [InventoriesController::class, 'updateInventory'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::put('/inventory/batch/{id}', [InventoriesController::class, 'updateBatch'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::delete('/inventory/{id}', [InventoriesController::class, 'deleteInventory'])->middleware(PermissionMiddleware::class . ':manage_inventories');
    Route::delete('/inventory/batch/{id}', [InventoriesController::class, 'deleteBatch'])->middleware(PermissionMiddleware::class . ':manage_inventories');

    // clinics routes
    Route::get('/clinics', [ClinicController::class, 'index'])->middleware(PermissionMiddleware::class . ':view_clinic');
    Route::get('/clinics/{id}', [ClinicController::class, 'show'])->middleware(PermissionMiddleware::class . ':view_clinic');
    Route::get('/clinics/hospital/{hospitalId}', [ClinicController::class, 'getClinicsByHospital'])->middleware(PermissionMiddleware::class . ':view_clinic');
    Route::get('/clinics/doctor/{doctorId}', [ClinicController::class, 'getClinicsByDoctor'])->middleware(PermissionMiddleware::class . ':view_clinic');
    Route::get('/hospitals/{hospitalId}/doctors', [ClinicController::class, 'getAvailableDoctors'])->middleware(PermissionMiddleware::class . ':view_clinic');
    Route::post('/clinics', [ClinicController::class, 'store'])->middleware(PermissionMiddleware::class . ':manage_clinic');
    Route::put('/clinics/{id}', [ClinicController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_clinic');
    Route::delete('/clinics/{id}', [ClinicController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_clinic');

    // clinic dates routes
    Route::get('/clinic-dates', [ClinicDateController::class, 'index']);
    Route::get('/clinic-dates/{id}', [ClinicDateController::class, 'show']);
    Route::get('/clinic-dates/clinic/{clinicId}', [ClinicDateController::class, 'getByClinic']);
    Route::post('/clinic-dates', [ClinicDateController::class, 'store'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::put('/clinic-dates/{id}', [ClinicDateController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::patch('/clinic-dates/{id}/status', [ClinicDateController::class, 'updateStatus'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::delete('/clinic-dates/{id}', [ClinicDateController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_hospitals');

    // clinic tokens (reservations) routes
    Route::get('/clinic-tokens', [ClinicTokenController::class, 'index'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::get('/clinic-tokens/{id}', [ClinicTokenController::class, 'show'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::get('/clinic-dates/{clinicDateId}/available-slots', [ClinicTokenController::class, 'getAvailableSlots'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::post('/clinic-tokens', [ClinicTokenController::class, 'store'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::put('/clinic-tokens/{id}', [ClinicTokenController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_appointments');
    Route::delete('/clinic-tokens/{id}', [ClinicTokenController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_appointments');

    // opd dates routes
    Route::get('/opd-dates', [OpdDateController::class, 'index']);
    Route::get('/opd-dates/{id}', [OpdDateController::class, 'show']);
    Route::get('/opd-dates/hospital/{hospitalId}', [OpdDateController::class, 'getByHospital']);
    Route::post('/opd-dates', [OpdDateController::class, 'store'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::put('/opd-dates/{id}', [OpdDateController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::patch('/opd-dates/{id}/status', [OpdDateController::class, 'updateStatus'])->middleware(PermissionMiddleware::class . ':manage_hospitals');
    Route::delete('/opd-dates/{id}', [OpdDateController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_hospitals');

    // opd tokens (reservations) routes
    Route::get('/opd-tokens', [OpdTokenController::class, 'index'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::get('/opd-tokens/{id}', [OpdTokenController::class, 'show'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::get('/opd-dates/{opdDateId}/available-slots', [OpdTokenController::class, 'getAvailableSlots'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::post('/opd-tokens', [OpdTokenController::class, 'store'])->middleware(PermissionMiddleware::class . ':view_appointments');
    Route::put('/opd-tokens/{id}', [OpdTokenController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_appointments');
    Route::delete('/opd-tokens/{id}', [OpdTokenController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_appointments');

    // clinic patients routes
    Route::get('/clinic-patients', [ClinicPatientsController::class, 'index'])->middleware(PermissionMiddleware::class . ':view_clinic_patients');
    Route::get('/clinic-patients/{clinicPatients}', [ClinicPatientsController::class, 'show'])->middleware(PermissionMiddleware::class . ':view_clinic_patients');
    Route::post('/clinic-patients', [ClinicPatientsController::class, 'store'])->middleware(PermissionMiddleware::class . ':manage_clinic_patients');
    Route::put('/clinic-patients/{clinicPatients}', [ClinicPatientsController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_clinic_patients');
    Route::delete('/clinic-patients/{clinicPatients}', [ClinicPatientsController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_clinic_patients');

    // pharmacy routes (Osusala)
    Route::post('/pharmacies', [PharmacyController::class, 'store'])->middleware(PermissionMiddleware::class . ':manage_pharmacy');
    Route::put('/pharmacies/{pharmacy}', [PharmacyController::class, 'update'])->middleware(PermissionMiddleware::class . ':manage_pharmacy');
    Route::delete('/pharmacies/{pharmacy}', [PharmacyController::class, 'destroy'])->middleware(PermissionMiddleware::class . ':manage_pharmacy');
});

// CLI routes
Route::get('/cli/run-migrate', [CLIController::class, 'migrateDatabase'])->middleware(CheckAppKey::class);
Route::get('/cli/run-seed', [CLIController::class, 'seedDatabase'])->middleware(CheckAppKey::class);
Route::get('/cli/run-migrate-rollback', [CLIController::class, 'rollbackDatabase'])->middleware(CheckAppKey::class);
Route::get('/cli/run-storage-link', [CLIController::class, 'createStorageLink'])->middleware(CheckAppKey::class);
Route::get('/cli/run-cache-clear', [CLIController::class, 'clearCache'])->middleware(CheckAppKey::class);
