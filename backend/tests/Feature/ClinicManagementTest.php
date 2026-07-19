<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Hospital;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicManagementTest extends TestCase
{
    use RefreshDatabase;

    private Permission $manageClinic;
    private Permission $viewClinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manageClinic = Permission::create([
            'name' => 'manage_clinic',
            'description' => 'Manage clinics',
        ]);
        $this->viewClinic = Permission::create([
            'name' => 'view_clinic',
            'description' => 'View clinics',
        ]);
    }

    public function test_super_admin_creates_a_clinic_for_the_selected_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $doctorA = $this->createUser('doctor', $hospitalA);

        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', $this->clinicPayload($hospitalA, $doctorA))
            ->assertCreated()
            ->assertJsonPath('hospital_id', $hospitalA->id)
            ->assertJsonPath('doctor_id', $doctorA->id);

        $this->assertDatabaseHas('clinics', [
            'hospital_id' => $hospitalA->id,
            'doctor_id' => $doctorA->id,
        ]);
        $this->assertDatabaseMissing('clinics', ['hospital_id' => $hospitalB->id]);
    }

    public function test_super_admin_cannot_create_for_a_nonexistent_hospital(): void
    {
        $doctor = $this->createUser('doctor', $this->createHospital());
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', [
            ...$this->clinicPayload(null, $doctor),
            'hospital_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_super_admin_cannot_create_with_an_invalid_doctor_for_the_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $invalidUsers = [
            $this->createUser('doctor', $hospitalB),
            $this->createUser('doctor'),
            $this->createUser('receptionist', $hospitalA),
            $this->createUser('doctor', $hospitalA, 'retired'),
            $this->createUser('doctor', $hospitalA, 'banned'),
        ];

        Sanctum::actingAs($this->createUser('super_admin'));

        foreach ($invalidUsers as $invalidUser) {
            $this->postJson('/api/clinics', $this->clinicPayload($hospitalA, $invalidUser))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('doctor_id');
        }

        $this->postJson('/api/clinics', [
            ...$this->clinicPayload($hospitalA),
            'doctor_id' => 999999,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_hospital_admin_creates_a_clinic_in_its_assigned_hospital(): void
    {
        $hospital = $this->createHospital();
        $doctor = $this->createUser('doctor', $hospital);
        Sanctum::actingAs($this->createUser('hospital_admin', $hospital));

        $this->postJson('/api/clinics', $this->clinicPayload($hospital, $doctor))
            ->assertCreated()
            ->assertJsonPath('hospital_id', $hospital->id)
            ->assertJsonPath('doctor_id', $doctor->id);
    }

    public function test_hospital_admin_cannot_submit_another_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $otherDoctor = $this->createUser('doctor', $otherHospital);
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->postJson('/api/clinics', $this->clinicPayload($otherHospital, $otherDoctor))
            ->assertForbidden();

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_hospital_admin_cannot_submit_a_doctor_from_another_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $otherDoctor = $this->createUser('doctor', $otherHospital);
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->postJson('/api/clinics', $this->clinicPayload($assignedHospital, $otherDoctor))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_hospital_admin_without_an_assignment_receives_forbidden(): void
    {
        $hospital = $this->createHospital();
        $doctor = $this->createUser('doctor', $hospital);
        Sanctum::actingAs($this->createUser('hospital_admin'));

        $this->postJson('/api/clinics', $this->clinicPayload($hospital, $doctor))
            ->assertForbidden();

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_unapproved_roles_are_forbidden_even_with_manage_clinic_permission(): void
    {
        $hospital = $this->createHospital();
        $doctor = $this->createUser('doctor', $hospital);

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->postJson('/api/clinics', $this->clinicPayload($hospital, $doctor))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_self_hourly_tokens_cannot_exceed_total_hourly_tokens(): void
    {
        $hospital = $this->createHospital();
        $doctor = $this->createUser('doctor', $hospital);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', [
            ...$this->clinicPayload($hospital, $doctor),
            'total_hourly_tokens' => 5,
            'self_hourly_tokens' => 6,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('self_hourly_tokens');

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_super_admin_updates_a_clinic_with_a_valid_selected_hospital_and_doctor(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $clinic = $this->createClinic($hospitalA, $doctorA);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson(
            "/api/clinics/{$clinic->id}",
            $this->clinicPayload($hospitalB, $doctorB, ['name' => 'Moved Clinic'])
        )
            ->assertOk()
            ->assertJsonPath('hospital_id', $hospitalB->id)
            ->assertJsonPath('doctor_id', $doctorB->id);

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'name' => 'Moved Clinic',
            'hospital_id' => $hospitalB->id,
            'doctor_id' => $doctorB->id,
        ]);
    }

    public function test_super_admin_cannot_update_with_a_cross_hospital_doctor(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $clinic = $this->createClinic($hospitalA, $doctorA);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson(
            "/api/clinics/{$clinic->id}",
            $this->clinicPayload($hospitalA, $doctorB)
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'hospital_id' => $hospitalA->id,
            'doctor_id' => $doctorA->id,
        ]);
    }

    public function test_hospital_admin_updates_only_a_clinic_in_its_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $assignedDoctor = $this->createUser('doctor', $assignedHospital);
        $otherDoctor = $this->createUser('doctor', $otherHospital);
        $ownClinic = $this->createClinic($assignedHospital, $assignedDoctor);
        $otherClinic = $this->createClinic($otherHospital, $otherDoctor);
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->putJson(
            "/api/clinics/{$ownClinic->id}",
            $this->clinicPayload($assignedHospital, $assignedDoctor, ['name' => 'Updated Clinic'])
        )->assertOk();

        $this->putJson(
            "/api/clinics/{$otherClinic->id}",
            $this->clinicPayload($otherHospital, $otherDoctor)
        )->assertForbidden();

        $this->assertDatabaseHas('clinics', [
            'id' => $ownClinic->id,
            'name' => 'Updated Clinic',
        ]);
        $this->assertDatabaseHas('clinics', [
            'id' => $otherClinic->id,
            'hospital_id' => $otherHospital->id,
            'doctor_id' => $otherDoctor->id,
        ]);
    }

    public function test_doctor_endpoint_returns_only_working_doctors_from_the_authorized_hospital(): void
    {
        $hospital = $this->createHospital();
        $otherHospital = $this->createHospital('Other Hospital');
        $workingDoctor = $this->createUser('doctor', $hospital);
        $this->createUser('doctor', $hospital, 'retired');
        $this->createUser('doctor', $hospital, 'banned');
        $this->createUser('doctor');
        $this->createUser('receptionist', $hospital);
        $this->createUser('doctor', $otherHospital);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->getJson("/api/hospitals/{$hospital->id}/doctors")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $workingDoctor->id);
    }

    public function test_hospital_admin_cannot_load_doctors_from_another_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->getJson("/api/hospitals/{$otherHospital->id}/doctors")
            ->assertForbidden();
    }

    private function createUser(
        string $roleName,
        ?Hospital $hospital = null,
        string $status = 'working'
    ): User {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching([
            $this->manageClinic->id,
            $this->viewClinic->id,
        ]);

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)) . ' ' . uniqid(),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => $status,
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

    private function createClinic(Hospital $hospital, User $doctor): Clinic
    {
        return Clinic::create($this->clinicPayload($hospital, $doctor));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function clinicPayload(
        ?Hospital $hospital = null,
        ?User $doctor = null,
        array $overrides = []
    ): array {
        return array_merge([
            'name' => 'Eye Clinic',
            'hospital_id' => $hospital?->id,
            'description' => 'Weekly eye clinic',
            'doctor_id' => $doctor?->id,
            'location' => 'Block A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ], $overrides);
    }
}
