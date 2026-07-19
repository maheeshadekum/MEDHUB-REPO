<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicDate;
use App\Models\ClinicToken;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicDateManagementTest extends TestCase
{
    use RefreshDatabase;

    private Permission $manageHospitals;
    private Permission $viewClinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manageHospitals = Permission::create([
            'name' => 'manage_hospitals',
            'description' => 'Manage hospitals',
        ]);
        $this->viewClinic = Permission::create([
            'name' => 'view_clinic',
            'description' => 'View clinics',
        ]);
    }

    public function test_super_admin_creates_for_the_matching_hospital_and_clinic(): void
    {
        $hospital = $this->createHospital('Hospital A');
        $clinic = $this->createClinic($hospital, 'Clinic A');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinic-dates', $this->createPayload($hospital, $clinic))
            ->assertCreated()
            ->assertJsonPath('clinic_id', $clinic->id)
            ->assertJsonPath('clinic.hospital_id', $hospital->id)
            ->assertJsonPath('clinic.hospital.id', $hospital->id);

        $this->assertDatabaseHas('clinic_dates', ['clinic_id' => $clinic->id]);
    }

    public function test_super_admin_cannot_pair_a_hospital_with_another_hospitals_clinic(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $clinicB = $this->createClinic($hospitalB, 'Clinic B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinic-dates', $this->createPayload($hospitalA, $clinicB))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');

        $this->assertDatabaseCount('clinic_dates', 0);
    }

    public function test_creation_requires_existing_hospital_and_clinic(): void
    {
        $hospital = $this->createHospital();
        $clinic = $this->createClinic($hospital);
        Sanctum::actingAs($this->createUser('super_admin'));

        $payloads = [
            [array_diff_key($this->createPayload($hospital, $clinic), ['hospital_id' => true]), 'hospital_id'],
            [array_diff_key($this->createPayload($hospital, $clinic), ['clinic_id' => true]), 'clinic_id'],
            [$this->createPayload($hospital, $clinic, ['hospital_id' => 999999]), 'hospital_id'],
            [$this->createPayload($hospital, $clinic, ['clinic_id' => 999999]), 'clinic_id'],
        ];

        foreach ($payloads as [$payload, $field]) {
            $this->postJson('/api/clinic-dates', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('clinic_dates', 0);
    }

    public function test_hospital_admin_creates_only_for_an_own_hospital_clinic(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $ownClinic = $this->createClinic($assignedHospital, 'Own Clinic');
        $otherClinic = $this->createClinic($otherHospital, 'Other Clinic');
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->postJson('/api/clinic-dates', $this->createPayload($assignedHospital, $ownClinic))
            ->assertCreated();

        $this->postJson('/api/clinic-dates', $this->createPayload($otherHospital, $otherClinic))
            ->assertForbidden();

        $this->assertDatabaseHas('clinic_dates', ['clinic_id' => $ownClinic->id]);
        $this->assertDatabaseMissing('clinic_dates', ['clinic_id' => $otherClinic->id]);
    }

    public function test_hospital_admin_without_assignment_cannot_create_or_list(): void
    {
        $hospital = $this->createHospital();
        $clinic = $this->createClinic($hospital);
        Sanctum::actingAs($this->createUser('hospital_admin'));

        $this->postJson('/api/clinic-dates', $this->createPayload($hospital, $clinic))
            ->assertForbidden();
        $this->getJson('/api/clinic-dates')->assertForbidden();

        $this->assertDatabaseCount('clinic_dates', 0);
    }

    public function test_unapproved_roles_cannot_create_even_with_dynamic_permission(): void
    {
        $hospital = $this->createHospital();
        $clinic = $this->createClinic($hospital);

        foreach ($this->unapprovedRoles() as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->postJson('/api/clinic-dates', $this->createPayload($hospital, $clinic))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('clinic_dates', 0);
    }

    public function test_super_admin_updates_schedule_without_changing_ownership(): void
    {
        $hospital = $this->createHospital();
        $clinic = $this->createClinic($hospital);
        $clinicDate = $this->createClinicDate($clinic);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/clinic-dates/{$clinicDate->id}", $this->updatePayload([
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'completed',
        ]))
            ->assertOk()
            ->assertJsonPath('clinic_id', $clinic->id)
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('clinic_dates', [
            'id' => $clinicDate->id,
            'clinic_id' => $clinic->id,
            'status' => 'completed',
        ]);
    }

    public function test_hospital_admin_updates_only_an_own_hospital_clinic_date(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $ownDate = $this->createClinicDate($this->createClinic($assignedHospital, 'Own Clinic'));
        $otherDate = $this->createClinicDate($this->createClinic($otherHospital, 'Other Clinic'));
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->putJson("/api/clinic-dates/{$ownDate->id}", $this->updatePayload([
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]))->assertOk();

        $this->putJson("/api/clinic-dates/{$otherDate->id}", $this->updatePayload())
            ->assertForbidden();

        $this->assertDatabaseHas('clinic_dates', [
            'id' => $otherDate->id,
            'start_time' => '08:00',
        ]);
    }

    public function test_clinic_and_hospital_are_immutable_and_tokens_remain_unchanged(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $clinicA = $this->createClinic($hospitalA, 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, 'Clinic B');
        $clinicDate = $this->createClinicDate($clinicA);
        $token = $this->createClinicToken($clinicDate);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/clinic-dates/{$clinicDate->id}", $this->updatePayload([
            'clinic_id' => $clinicB->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');

        $this->putJson("/api/clinic-dates/{$clinicDate->id}", $this->updatePayload([
            'hospital_id' => $hospitalB->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $this->assertDatabaseHas('clinic_dates', [
            'id' => $clinicDate->id,
            'clinic_id' => $clinicA->id,
        ]);
        $this->assertDatabaseHas('clinic_tokens', [
            'id' => $token->id,
            'clinic_id' => $clinicDate->id,
        ]);

        $this->deleteJson("/api/clinic-dates/{$clinicDate->id}")
            ->assertStatus(400);
        $this->assertDatabaseHas('clinic_dates', ['id' => $clinicDate->id]);
    }

    public function test_hospital_admin_cannot_change_clinic_id(): void
    {
        $hospital = $this->createHospital();
        $clinicA = $this->createClinic($hospital, 'Clinic A');
        $clinicB = $this->createClinic($hospital, 'Clinic B');
        $clinicDate = $this->createClinicDate($clinicA);
        Sanctum::actingAs($this->createUser('hospital_admin', $hospital));

        $this->putJson("/api/clinic-dates/{$clinicDate->id}", $this->updatePayload([
            'clinic_id' => $clinicB->id,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('clinic_id');

        $this->assertDatabaseHas('clinic_dates', [
            'id' => $clinicDate->id,
            'clinic_id' => $clinicA->id,
        ]);
    }

    public function test_status_updates_follow_role_and_hospital_scope(): void
    {
        $hospital = $this->createHospital();
        $otherHospital = $this->createHospital('Other Hospital');
        $clinicDate = $this->createClinicDate($this->createClinic($hospital));

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->patchJson("/api/clinic-dates/{$clinicDate->id}/status", [
            'status' => 'completed',
        ])->assertOk();

        Sanctum::actingAs($this->createUser('hospital_admin', $hospital));
        $this->patchJson("/api/clinic-dates/{$clinicDate->id}/status", [
            'status' => 'scheduled',
        ])->assertOk();

        Sanctum::actingAs($this->createUser('hospital_admin', $otherHospital));
        $this->patchJson("/api/clinic-dates/{$clinicDate->id}/status", [
            'status' => 'cancelled',
        ])->assertForbidden();

        foreach ($this->unapprovedRoles() as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));
            $this->patchJson("/api/clinic-dates/{$clinicDate->id}/status", [
                'status' => 'cancelled',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('clinic_dates', [
            'id' => $clinicDate->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_listing_is_globally_available_to_super_admin_and_scoped_for_hospital_admin(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $clinicA = $this->createClinic($hospitalA, 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, 'Clinic B');
        $dateA = $this->createClinicDate($clinicA);
        $dateB = $this->createClinicDate($clinicB);

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));
        $this->getJson('/api/clinic-dates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $dateA->id);

        $this->getJson("/api/clinic-dates?clinic_id={$clinicB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertNotSame($dateA->id, $dateB->id);
    }

    public function test_show_and_get_by_clinic_enforce_hospital_scope(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $clinicA = $this->createClinic($hospitalA, 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, 'Clinic B');
        $dateB = $this->createClinicDate($clinicB);

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));
        $this->getJson("/api/clinic-dates/{$dateB->id}")->assertForbidden();
        $this->getJson("/api/clinic-dates/clinic/{$clinicB->id}")->assertForbidden();

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->getJson("/api/clinic-dates/{$dateB->id}")->assertOk();
        $this->getJson("/api/clinic-dates/clinic/{$clinicB->id}")->assertOk();

        $this->assertNotSame($clinicA->id, $clinicB->id);
    }

    public function test_unapproved_roles_cannot_read_or_update(): void
    {
        $hospital = $this->createHospital();
        $clinicDate = $this->createClinicDate($this->createClinic($hospital));

        foreach ($this->unapprovedRoles() as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));
            $this->getJson('/api/clinic-dates')->assertForbidden();
            $this->getJson("/api/clinic-dates/{$clinicDate->id}")->assertForbidden();
            $this->putJson("/api/clinic-dates/{$clinicDate->id}", $this->updatePayload())
                ->assertForbidden();
        }
    }

    public function test_delete_follows_role_hospital_and_existing_reservation_rules(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $dateForSuperAdmin = $this->createClinicDate($this->createClinic($hospitalA, 'Clinic A1'));
        $dateForHospitalAdmin = $this->createClinicDate($this->createClinic($hospitalA, 'Clinic A2'));
        $foreignDate = $this->createClinicDate($this->createClinic($hospitalB, 'Clinic B'));

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->deleteJson("/api/clinic-dates/{$dateForSuperAdmin->id}")->assertOk();

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));
        $this->deleteJson("/api/clinic-dates/{$dateForHospitalAdmin->id}")->assertOk();
        $this->deleteJson("/api/clinic-dates/{$foreignDate->id}")->assertForbidden();

        foreach ($this->unapprovedRoles() as $role) {
            Sanctum::actingAs($this->createUser($role, $hospitalB));
            $this->deleteJson("/api/clinic-dates/{$foreignDate->id}")->assertForbidden();
        }

        $this->assertDatabaseHas('clinic_dates', ['id' => $foreignDate->id]);
    }

    private function createUser(string $roleName, ?Hospital $hospital = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching([
            $this->manageHospitals->id,
            $this->viewClinic->id,
        ]);

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)) . ' ' . uniqid(),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }

    private function createHospital(string $name = 'Test Hospital'): Hospital
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

    private function createClinic(Hospital $hospital, string $name = 'Test Clinic'): Clinic
    {
        $doctor = $this->createUser('doctor', $hospital);

        return Clinic::create([
            'name' => $name . '-' . uniqid(),
            'hospital_id' => $hospital->id,
            'description' => 'Test clinic',
            'doctor_id' => $doctor->id,
            'location' => 'Block A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }

    private function createClinicDate(Clinic $clinic): ClinicDate
    {
        return ClinicDate::create([
            'clinic_id' => $clinic->id,
            ...$this->updatePayload(),
        ]);
    }

    private function createClinicToken(ClinicDate $clinicDate): ClinicToken
    {
        $patient = Patient::create([
            'nic' => uniqid('nic-'),
            'name' => 'Test Patient',
        ]);

        return ClinicToken::create([
            'clinic_id' => $clinicDate->id,
            'patient_id' => $patient->id,
            'type' => 'self',
            'token_number' => 'T-1',
            'start_time' => '08:00',
            'end_time' => '08:30',
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function createPayload(Hospital $hospital, Clinic $clinic, array $overrides = []): array
    {
        return array_merge([
            'hospital_id' => $hospital->id,
            'clinic_id' => $clinic->id,
            ...$this->updatePayload(),
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'scheduled',
        ], $overrides);
    }

    /** @return array<int, string> */
    private function unapprovedRoles(): array
    {
        return ['doctor', 'pharmacist', 'receptionist', 'patient'];
    }
}
