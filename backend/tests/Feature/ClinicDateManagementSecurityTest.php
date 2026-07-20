<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicDate;
use App\Models\ClinicToken;
use App\Models\Hospital;
use App\Models\Medicines;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Prescriptions;
use App\Models\Reports;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicDateManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Permission $viewClinic;
    private Permission $manageHospitals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewClinic = Permission::create([
            'name' => 'view_clinic',
            'description' => 'View clinics',
        ]);
        $this->manageHospitals = Permission::create([
            'name' => 'manage_hospitals',
            'description' => 'Manage hospitals',
        ]);
    }

    public function test_super_admin_reads_all_dates_and_filters_any_clinic(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA), 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB), 'Clinic B');
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB, 2);
        Sanctum::actingAs($this->createUser('super_admin', null, true, true));

        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonFragment(['id' => $dateA->id])
            ->assertJsonFragment(['id' => $dateB->id]);
        $this->getJson("/api/clinic-dates?clinic_id={$clinicB->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $dateB->id]);
        $this->getJson("/api/clinic-dates/{$dateB->id}")->assertOk()->assertJsonPath('id', $dateB->id);
        $this->getJson("/api/clinic-dates/clinic/{$clinicB->id}?from_date=" . now()->toDateString())
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_hospital_admin_and_receptionist_reads_are_hospital_scoped(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA), 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB), 'Clinic B');
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);

        foreach (['hospital_admin', 'receptionist'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospitalA, true, $role === 'hospital_admin'));
            $this->getJson('/api/clinic-dates')
                ->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonFragment(['id' => $dateA->id])
                ->assertJsonMissing(['id' => $dateB->id]);
            $this->getJson("/api/clinic-dates?clinic_id={$clinicB->id}")->assertForbidden();
            $this->getJson("/api/clinic-dates/{$dateB->id}")->assertForbidden();
            $this->getJson("/api/clinic-dates/clinic/{$clinicB->id}")->assertForbidden();
        }
    }

    public function test_hospital_scoped_roles_without_hospital_context_are_forbidden(): void
    {
        $hospital = $this->createHospital('Hospital');
        $clinic = $this->createClinic($hospital, $this->createUser('doctor', $hospital));
        $date = $this->createClinicDate($clinic);

        foreach (['hospital_admin', 'receptionist'] as $role) {
            Sanctum::actingAs($this->createUser($role, null, true, true));
            $this->getJson('/api/clinic-dates')->assertForbidden();
            $this->getJson("/api/clinic-dates/{$date->id}")->assertForbidden();
            $this->getJson("/api/clinic-dates/clinic/{$clinic->id}")->assertForbidden();
        }
    }

    public function test_doctor_reads_only_personally_assigned_clinic_dates(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital, true);
        $otherDoctor = $this->createUser('doctor', $hospital, true);
        $ownClinic = $this->createClinic($hospital, $doctor, 'Own Clinic');
        $otherClinic = $this->createClinic($hospital, $otherDoctor, 'Other Clinic');
        $ownDate = $this->createClinicDate($ownClinic);
        $otherDate = $this->createClinicDate($otherClinic);
        Sanctum::actingAs($doctor);

        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $ownDate->id])
            ->assertJsonMissing(['id' => $otherDate->id]);
        $this->getJson("/api/clinic-dates/{$ownDate->id}")->assertOk();
        $this->getJson("/api/clinic-dates/{$otherDate->id}")->assertForbidden();
        $this->getJson("/api/clinic-dates/clinic/{$ownClinic->id}")->assertOk();
        $this->getJson("/api/clinic-dates/clinic/{$otherClinic->id}")->assertForbidden();
    }

    public function test_pharmacist_is_forbidden_from_all_protected_read_endpoints(): void
    {
        $hospital = $this->createHospital('Hospital');
        $clinic = $this->createClinic($hospital, $this->createUser('doctor', $hospital));
        $date = $this->createClinicDate($clinic);
        Sanctum::actingAs($this->createUser('pharmacist', $hospital, true));

        $this->getJson('/api/clinic-dates')->assertForbidden();
        $this->getJson("/api/clinic-dates/{$date->id}")->assertForbidden();
        $this->getJson("/api/clinic-dates/clinic/{$clinic->id}")->assertForbidden();
    }

    public function test_patient_reads_only_enrolled_clinic_dates_and_requires_profile(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $enrolledClinic = $this->createClinic($hospital, $doctor, 'Enrolled Clinic');
        $otherClinic = $this->createClinic($hospital, $doctor, 'Other Clinic');
        $enrolledDate = $this->createClinicDate($enrolledClinic);
        $otherDate = $this->createClinicDate($otherClinic);
        $patientUser = $this->createUser('patient', null, true);
        $patient = Patient::create(['nic' => uniqid('nic-'), 'name' => 'Patient', 'user_id' => $patientUser->id]);
        $patient->clinics()->attach($enrolledClinic->id);
        Sanctum::actingAs($patientUser);

        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $enrolledDate->id])
            ->assertJsonMissing(['id' => $otherDate->id]);
        $this->getJson("/api/clinic-dates/{$enrolledDate->id}")->assertOk();
        $this->getJson("/api/clinic-dates/{$otherDate->id}")->assertForbidden();
        $this->getJson("/api/clinic-dates/clinic/{$otherClinic->id}")->assertForbidden();

        Sanctum::actingAs($this->createUser('patient', null, true));
        $this->getJson('/api/clinic-dates')->assertForbidden();
        $this->getJson("/api/clinic-dates/{$enrolledDate->id}")->assertForbidden();
    }

    public function test_custom_roles_require_view_permission_and_hospital_context(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);

        Sanctum::actingAs($this->createUser('scheduler', $hospitalA, true));
        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $dateA->id])
            ->assertJsonMissing(['id' => $dateB->id]);
        $this->getJson("/api/clinic-dates/{$dateB->id}")->assertForbidden();

        Sanctum::actingAs($this->createUser('scheduler_without_permission', $hospitalA));
        $this->getJson('/api/clinic-dates')->assertForbidden();

        Sanctum::actingAs($this->createUser('scheduler_without_context', null, true));
        $this->getJson('/api/clinic-dates')->assertForbidden();
    }

    public function test_list_validation_and_pagination_are_scoped_and_controlled(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));
        $this->createClinicDate($clinicA);
        $this->createClinicDate($clinicB);
        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA, true, true));

        $this->getJson('/api/clinic-dates?size=1&page=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/clinic-dates?size=0')->assertUnprocessable();
        $this->getJson('/api/clinic-dates?status=invalid')->assertUnprocessable();
        $this->getJson('/api/clinic-dates?clinic_id=999999')->assertUnprocessable();
        $this->getJson("/api/clinic-dates/clinic/{$clinicA->id}?from_date=not-a-date")->assertUnprocessable();
    }

    public function test_super_admin_and_hospital_admin_create_scheduled_dates_in_authorized_clinics(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));

        Sanctum::actingAs($this->createUser('super_admin', null, true, true));
        $this->postJson('/api/clinic-dates', $this->validPayload($clinicB, 2, ['status' => 'scheduled']))
            ->assertCreated()
            ->assertJsonPath('clinic_id', $clinicB->id)
            ->assertJsonPath('status', 'scheduled');

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA, true, true));
        $this->postJson('/api/clinic-dates', $this->validPayload($clinicA, 3))
            ->assertCreated()
            ->assertJsonPath('clinic_id', $clinicA->id);
        $this->postJson('/api/clinic-dates', $this->validPayload($clinicB, 4))->assertForbidden();
    }

    public function test_create_rejects_disallowed_roles_invalid_values_duplicates_and_partial_writes(): void
    {
        $hospital = $this->createHospital('Hospital');
        $clinic = $this->createClinic($hospital, $this->createUser('doctor', $hospital));

        foreach (['receptionist', 'doctor', 'pharmacist', 'patient', 'scheduler'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital, true, true));
            $this->postJson('/api/clinic-dates', $this->validPayload($clinic))->assertForbidden();
        }

        Sanctum::actingAs($this->createUser('hospital_admin', null, true, true));
        $this->postJson('/api/clinic-dates', $this->validPayload($clinic))->assertForbidden();

        Sanctum::actingAs($this->createUser('super_admin', null, true, true));
        $this->postJson('/api/clinic-dates', $this->validPayload($clinic, 1, ['status' => 'completed']))
            ->assertUnprocessable();
        $this->postJson('/api/clinic-dates', $this->validPayload($clinic, 1, ['date' => now()->subDay()->toDateString()]))
            ->assertUnprocessable();
        $this->postJson('/api/clinic-dates', $this->validPayload($clinic, 1, ['end_time' => '08:00']))
            ->assertUnprocessable();

        $this->postJson('/api/clinic-dates', $this->validPayload($clinic))->assertCreated();
        $this->postJson('/api/clinic-dates', $this->validPayload($clinic))->assertUnprocessable();
        $this->assertDatabaseCount('clinic_dates', 1);
    }

    public function test_updates_authorize_existing_record_and_keep_clinic_immutable(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);
        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA, true, true));

        $this->putJson("/api/clinic-dates/{$dateA->id}", $this->validPayload($clinicA, 4))
            ->assertOk()
            ->assertJsonPath('clinic_id', $clinicA->id);
        $this->putJson("/api/clinic-dates/{$dateB->id}", $this->validPayload($clinicA, 5))->assertForbidden();
        $this->putJson("/api/clinic-dates/{$dateA->id}", $this->validPayload($clinicB, 5))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');
        $this->assertDatabaseHas('clinic_dates', ['id' => $dateB->id, 'clinic_id' => $clinicB->id]);
        $this->assertDatabaseHas('clinic_dates', ['id' => $dateA->id, 'clinic_id' => $clinicA->id]);
    }

    public function test_update_rejects_token_bearing_schedule_changes_and_duplicate_dates(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor);
        $date = $this->createClinicDate($clinic, 2);
        $duplicate = $this->createClinicDate($clinic, 5);
        $patientUser = $this->createUser('patient');
        $patient = Patient::create(['nic' => uniqid('nic-'), 'name' => 'Patient', 'user_id' => $patientUser->id]);
        $token = $this->createToken($date, $patient);
        Sanctum::actingAs($this->createUser('super_admin', null, true, true));

        $this->putJson("/api/clinic-dates/{$date->id}", $this->validPayload($clinic, 3))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');
        $this->assertSame(now()->addDays(2)->toDateString(), $date->fresh()->date->toDateString());
        $this->assertDatabaseHas('clinic_tokens', ['id' => $token->id, 'clinic_id' => $date->id]);

        $this->putJson("/api/clinic-dates/{$duplicate->id}", $this->validPayload($clinic, 2))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        $this->assertSame(now()->addDays(5)->toDateString(), $duplicate->fresh()->date->toDateString());
    }

    public function test_status_updates_are_role_and_hospital_authorized_and_validate_status(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);

        Sanctum::actingAs($this->createUser('super_admin', null, true, true));
        $this->patchJson("/api/clinic-dates/{$dateB->id}/status", ['status' => 'completed'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA, true, true));
        $this->patchJson("/api/clinic-dates/{$dateA->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/clinic-dates/{$dateB->id}/status", ['status' => 'cancelled'])->assertForbidden();
        $this->patchJson("/api/clinic-dates/{$dateA->id}/status", ['status' => 'invalid'])->assertUnprocessable();

        Sanctum::actingAs($this->createUser('receptionist', $hospitalA, true, true));
        $this->patchJson("/api/clinic-dates/{$dateA->id}/status", ['status' => 'scheduled'])->assertForbidden();
    }

    public function test_cancellation_and_disabled_delete_preserve_complete_related_history(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor);
        $date = $this->createClinicDate($clinic);
        $patientUser = $this->createUser('patient');
        $patient = Patient::create(['nic' => uniqid('nic-'), 'name' => 'Patient', 'user_id' => $patientUser->id]);
        $token = $this->createToken($date, $patient);
        $prescription = Prescriptions::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'hospital_id' => $hospital->id,
            'date' => now()->toDateString(),
            'status' => 'draft',
            'token_type' => 'clinic',
            'clinic_token_id' => $token->id,
        ]);
        $report = Reports::create(['prescription_id' => $prescription->id, 'report_type' => 'Test']);
        $medicine = Medicines::create([
            'name' => 'Medicine',
            'prescription_id' => $prescription->id,
            'is_external' => true,
            'dosage' => 1,
            'days_supply' => 1,
        ]);
        Sanctum::actingAs($this->createUser('super_admin', null, true, true));

        $this->patchJson("/api/clinic-dates/{$date->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->deleteJson("/api/clinic-dates/{$date->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Clinic Date deletion is not supported. Cancel the Clinic Date to retain scheduling and clinical history.');

        $this->assertDatabaseHas('clinic_dates', ['id' => $date->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('clinic_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('prescriptions', ['id' => $prescription->id]);
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertDatabaseHas('medicines', ['id' => $medicine->id]);
    }

    public function test_delete_is_authorized_before_retention_response_and_never_writes(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $clinicA = $this->createClinic($hospitalA, $this->createUser('doctor', $hospitalA));
        $clinicB = $this->createClinic($hospitalB, $this->createUser('doctor', $hospitalB));
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA, true, true));
        $this->deleteJson("/api/clinic-dates/{$dateA->id}")->assertUnprocessable();
        $this->deleteJson("/api/clinic-dates/{$dateB->id}")->assertForbidden();

        Sanctum::actingAs($this->createUser('scheduler', $hospitalA, true, true));
        $this->deleteJson("/api/clinic-dates/{$dateA->id}")->assertForbidden();
        $this->deleteJson('/api/clinic-dates/999999')->assertNotFound();
        $this->assertDatabaseCount('clinic_dates', 2);
    }

    private function createHospital(string $name): Hospital
    {
        $suffix = uniqid();

        return Hospital::create([
            'name' => $name . '-' . $suffix,
            'identifier' => 'hospital-' . $suffix,
            'address' => '1 Test Street',
            'phone' => '0712345678',
            'email' => 'hospital-' . $suffix . '@example.com',
            'district' => 'Colombo',
            'location_url' => 'https://maps.app.goo.gl/test',
            'is_inventory_activated' => false,
            'is_appointment_activated' => false,
        ]);
    }

    /** @return array{Hospital, Hospital} */
    private function createTwoHospitals(): array
    {
        return [$this->createHospital('Hospital A'), $this->createHospital('Hospital B')];
    }

    private function createUser(
        string $roleName,
        ?Hospital $hospital = null,
        bool $canView = false,
        bool $canManage = false
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $permissions = [];
        if ($canView) {
            $permissions[] = $this->viewClinic->id;
        }
        if ($canManage) {
            $permissions[] = $this->manageHospitals->id;
        }
        if ($permissions) {
            $role->permissions()->syncWithoutDetaching($permissions);
        }

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }

    private function createClinic(Hospital $hospital, User $doctor, string $name = 'Clinic'): Clinic
    {
        return Clinic::create([
            'name' => $name . '-' . uniqid(),
            'hospital_id' => $hospital->id,
            'description' => 'Clinic Date security test',
            'doctor_id' => $doctor->id,
            'location' => 'Building A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }

    private function createClinicDate(Clinic $clinic, int $daysFromNow = 1, array $overrides = []): ClinicDate
    {
        return ClinicDate::create(array_merge([
            'clinic_id' => $clinic->id,
            'date' => now()->addDays($daysFromNow)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'status' => 'scheduled',
        ], $overrides));
    }

    private function validPayload(Clinic $clinic, int $daysFromNow = 1, array $overrides = []): array
    {
        return array_merge([
            'clinic_id' => $clinic->id,
            'date' => now()->addDays($daysFromNow)->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
        ], $overrides);
    }

    private function createToken(ClinicDate $clinicDate, Patient $patient): ClinicToken
    {
        return ClinicToken::create([
            'clinic_id' => $clinicDate->id,
            'patient_id' => $patient->id,
            'type' => 'self',
            'token_number' => now()->toDateString() . '-001',
            'start_time' => '09:00',
            'end_time' => '10:00',
        ]);
    }
}
