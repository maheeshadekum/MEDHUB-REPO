<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicDate;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicManagementSecurityTest extends TestCase
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

    public function test_super_admin_creates_clinics_for_selected_hospitals(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, [
            'name' => 'Clinic A',
        ]))->assertCreated()->assertJsonPath('hospital_id', $hospitalA->id);

        $this->postJson('/api/clinics', $this->validPayload($hospitalB, $doctorB, [
            'name' => 'Clinic B',
        ]))->assertCreated()->assertJsonPath('hospital_id', $hospitalB->id);

        $this->assertDatabaseCount('clinics', 2);
    }

    public function test_super_admin_create_requires_valid_hospital_and_valid_doctor(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $nonDoctor = $this->createUser('receptionist', $hospitalA);
        $retiredDoctor = $this->createUser('doctor', $hospitalA, 'retired');
        $bannedDoctor = $this->createUser('doctor', $hospitalA, 'banned');
        Sanctum::actingAs($this->createUser('super_admin'));

        $payload = $this->validPayload($hospitalA, $doctorA);
        unset($payload['hospital_id']);
        $this->postJson('/api/clinics', $payload)->assertUnprocessable()->assertJsonValidationErrors('hospital_id');

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, ['hospital_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        foreach ([$doctorB, $nonDoctor, $retiredDoctor, $bannedDoctor] as $invalidDoctor) {
            $this->postJson('/api/clinics', $this->validPayload($hospitalA, $invalidDoctor))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('doctor_id');
        }

        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_duplicate_rule_is_trimmed_case_insensitive_and_hospital_scoped(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, [
            'name' => '  Medical Clinic  ',
            'location' => '  Building A  ',
        ]))->assertCreated();

        $this->assertDatabaseHas('clinics', [
            'name' => 'Medical Clinic',
            'location' => 'Building A',
        ]);

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, [
            'name' => 'medical clinic',
            'location' => ' building a ',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'location']);

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, [
            'name' => 'Medical Clinic',
            'location' => 'Building B',
        ]))->assertCreated();

        $this->postJson('/api/clinics', $this->validPayload($hospitalB, $doctorB, [
            'name' => 'Medical Clinic',
            'location' => 'Building A',
        ]))->assertCreated();

        $this->assertDatabaseCount('clinics', 3);
    }

    public function test_super_admin_updates_without_transferring_hospital(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $clinic = $this->createClinic($hospitalA, $doctorA, 'Original Clinic');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospitalA, $doctorA, [
            'name' => 'Updated Clinic',
        ]))->assertOk()->assertJsonPath('hospital_id', $hospitalA->id);

        $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospitalB, $doctorB, [
            'name' => 'Transferred Clinic',
        ]))->assertUnprocessable()->assertJsonValidationErrors('hospital_id');

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'hospital_id' => $hospitalA->id,
            'name' => 'Updated Clinic',
            'doctor_id' => $doctorA->id,
        ]);
    }

    public function test_failed_update_preserves_values_and_duplicate_check_excludes_current_clinic(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Clinic One', 'Block A');
        $this->createClinic($hospital, $doctor, 'Clinic Two', 'Block B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospital, $doctor, [
            'name' => 'Clinic One',
            'location' => 'Block A',
        ]))->assertOk();

        $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospital, $doctor, [
            'name' => ' clinic two ',
            'location' => ' block b ',
        ]))->assertUnprocessable();

        $this->assertDatabaseHas('clinics', [
            'id' => $clinic->id,
            'name' => 'Clinic One',
            'location' => 'Block A',
        ]);
    }

    public function test_hospital_admin_is_restricted_to_assigned_hospital(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $ownClinic = $this->createClinic($hospitalA, $doctorA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctorB, 'Other Clinic');
        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));

        $this->postJson('/api/clinics', $this->validPayload($hospitalA, $doctorA, [
            'name' => 'Created Clinic',
        ]))->assertCreated();

        $this->postJson('/api/clinics', $this->validPayload($hospitalB, $doctorB))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $this->putJson("/api/clinics/{$ownClinic->id}", $this->validPayload($hospitalA, $doctorA, [
            'name' => 'Updated Own Clinic',
        ]))->assertOk();
        $this->putJson("/api/clinics/{$otherClinic->id}", $this->validPayload($hospitalB, $doctorB))
            ->assertForbidden();
        $this->putJson("/api/clinics/{$ownClinic->id}", $this->validPayload($hospitalA, $doctorB))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }

    public function test_hospital_admin_without_context_is_forbidden(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Clinic');
        Sanctum::actingAs($this->createUser('hospital_admin'));

        $this->postJson('/api/clinics', $this->validPayload($hospital, $doctor))->assertForbidden();
        $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospital, $doctor))->assertForbidden();
        $this->deleteJson("/api/clinics/{$clinic->id}")->assertForbidden();
        $this->getJson("/api/hospitals/{$hospital->id}/doctors")->assertForbidden();
    }

    public function test_dynamic_manage_permission_does_not_bypass_role_allowlist(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Clinic');

        foreach (['receptionist', 'doctor', 'pharmacist', 'patient', 'clinic_manager'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->postJson('/api/clinics', $this->validPayload($hospital, $doctor))->assertForbidden();
            $this->putJson("/api/clinics/{$clinic->id}", $this->validPayload($hospital, $doctor))->assertForbidden();
            $this->deleteJson("/api/clinics/{$clinic->id}")->assertForbidden();
        }

        $this->assertDatabaseCount('clinics', 1);
    }

    public function test_delete_is_disabled_and_preserves_clinic_dependencies(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $ownClinic = $this->createClinic($hospitalA, $doctorA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctorB, 'Other Clinic');
        $patientUser = $this->createUser('patient');
        $patient = Patient::create(['nic' => uniqid('nic-'), 'name' => 'Patient', 'user_id' => $patientUser->id]);
        $patient->clinics()->attach($ownClinic->id);
        ClinicDate::create([
            'clinic_id' => $ownClinic->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));
        $this->deleteJson("/api/clinics/{$ownClinic->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Clinic deletion is not supported. Clinics must be retained to protect clinical history.');
        $this->deleteJson("/api/clinics/{$otherClinic->id}")->assertForbidden();

        $this->assertDatabaseHas('clinics', ['id' => $ownClinic->id]);
        $this->assertDatabaseHas('clinic_dates', ['clinic_id' => $ownClinic->id]);
        $this->assertDatabaseHas('clinic_patients', ['clinic_id' => $ownClinic->id, 'patient_id' => $patient->id]);
    }

    public function test_validation_rejects_invalid_lengths_and_token_values_and_trims_values(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/clinics', [])->assertUnprocessable()->assertJsonValidationErrors([
            'hospital_id', 'name', 'description', 'doctor_id', 'location',
            'total_hourly_tokens', 'self_hourly_tokens',
        ]);

        $this->postJson('/api/clinics', $this->validPayload($hospital, $doctor, [
            'name' => str_repeat('n', 256),
            'description' => str_repeat('d', 501),
            'location' => str_repeat('l', 256),
            'total_hourly_tokens' => 0,
            'self_hourly_tokens' => 1001,
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'name', 'description', 'location', 'total_hourly_tokens', 'self_hourly_tokens',
        ]);

        $this->postJson('/api/clinics', $this->validPayload($hospital, $doctor, [
            'total_hourly_tokens' => 5,
            'self_hourly_tokens' => 6,
        ]))->assertUnprocessable()->assertJsonValidationErrors('self_hourly_tokens');

        $this->postJson('/api/clinics', $this->validPayload($hospital, $doctor, [
            'name' => ' Trimmed Clinic ',
            'description' => ' Trimmed description ',
            'location' => ' Trimmed location ',
        ]))->assertCreated();
        $this->assertDatabaseHas('clinics', [
            'name' => 'Trimmed Clinic',
            'description' => 'Trimmed description',
            'location' => 'Trimmed location',
        ]);
    }

    public function test_doctor_options_are_management_only_working_and_hospital_scoped(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $workingA = $this->createUser('doctor', $hospitalA);
        $workingB = $this->createUser('doctor', $hospitalB);
        $retired = $this->createUser('doctor', $hospitalA, 'retired');
        $banned = $this->createUser('doctor', $hospitalA, 'banned');
        $nonDoctor = $this->createUser('receptionist', $hospitalA);

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->getJson("/api/hospitals/{$hospitalA->id}/doctors")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $workingA->id])
            ->assertJsonMissing(['id' => $workingB->id])
            ->assertJsonMissing(['id' => $retired->id])
            ->assertJsonMissing(['id' => $banned->id])
            ->assertJsonMissing(['id' => $nonDoctor->id]);

        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));
        $this->getJson("/api/hospitals/{$hospitalA->id}/doctors")->assertOk();
        $this->getJson("/api/hospitals/{$hospitalB->id}/doctors")->assertForbidden();

        foreach (['receptionist', 'doctor', 'pharmacist', 'patient', 'clinic_manager'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospitalA));
            $this->getJson("/api/hospitals/{$hospitalA->id}/doctors")->assertForbidden();
        }
    }

    /** @return array{Hospital, Hospital} */
    private function createTwoHospitals(): array
    {
        return [$this->createHospital('Hospital A'), $this->createHospital('Hospital B')];
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

    private function createUser(string $roleName, ?Hospital $hospital = null, string $status = 'working'): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching([$this->manageClinic->id, $this->viewClinic->id]);

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => $status,
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }

    private function validPayload(Hospital $hospital, User $doctor, array $overrides = []): array
    {
        return array_merge([
            'hospital_id' => $hospital->id,
            'name' => 'Medical Clinic',
            'description' => 'General medical clinic',
            'doctor_id' => $doctor->id,
            'location' => 'Building A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ], $overrides);
    }

    private function createClinic(
        Hospital $hospital,
        User $doctor,
        string $name,
        string $location = 'Building A'
    ): Clinic {
        return Clinic::create([
            'name' => $name,
            'hospital_id' => $hospital->id,
            'description' => 'Clinic management security test',
            'doctor_id' => $doctor->id,
            'location' => $location,
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }
}
