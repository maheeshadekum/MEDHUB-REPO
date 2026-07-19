<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicReadVisibilitySecurityTest extends TestCase
{
    use RefreshDatabase;

    private Permission $viewClinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewClinic = Permission::create([
            'name' => 'view_clinic',
            'description' => 'View clinic details',
        ]);
    }

    public function test_super_admin_retains_global_and_filtered_access(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $clinicA = $this->createClinic($hospitalA, $doctorA, 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, $doctorB, 'Clinic B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 2);
        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $clinicB->id]);
        $this->getJson("/api/clinics/{$clinicA->id}")
            ->assertOk()
            ->assertJsonPath('id', $clinicA->id);
        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $clinicB->id]);
        $this->getJson("/api/clinics/doctor/{$doctorB->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $clinicB->id]);
    }

    public function test_hospital_admin_is_scoped_across_all_read_endpoints(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $ownClinic = $this->createClinic($hospitalA, $doctorA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctorB, 'Other Clinic');
        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $ownClinic->id])
            ->assertJsonMissing(['id' => $otherClinic->id]);
        $this->getJson("/api/clinics?hospital_id={$hospitalA->id}")->assertOk();
        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")->assertForbidden();
        $this->getJson("/api/clinics/{$ownClinic->id}")->assertOk();
        $this->getJson("/api/clinics/{$otherClinic->id}")
            ->assertForbidden()
            ->assertJsonMissing(['id' => $otherClinic->id]);
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")->assertOk();
        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctorA->id}")->assertOk();
        $this->getJson("/api/clinics/doctor/{$doctorB->id}")->assertForbidden();
    }

    public function test_hospital_scoped_doctor_results_cannot_leak_another_hospital(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctor = $this->createUser('doctor', $hospitalA);
        $ownClinic = $this->createClinic($hospitalA, $doctor, 'Own Hospital Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctor, 'Other Hospital Clinic');

        foreach (['hospital_admin', 'receptionist'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospitalA));

            $this->getJson("/api/clinics/doctor/{$doctor->id}")
                ->assertOk()
                ->assertJsonCount(1)
                ->assertJsonFragment(['id' => $ownClinic->id])
                ->assertJsonMissing(['id' => $otherClinic->id]);
        }
    }

    public function test_receptionist_details_and_doctor_reads_are_hospital_scoped(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $ownClinic = $this->createClinic($hospitalA, $doctorA, 'Reception Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctorB, 'Hidden Clinic');
        Sanctum::actingAs($this->createUser('receptionist', $hospitalA));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 1);
        $this->getJson("/api/clinics/{$ownClinic->id}")->assertOk();
        $this->getJson("/api/clinics/{$otherClinic->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctorA->id}")->assertOk();
        $this->getJson("/api/clinics/doctor/{$doctorB->id}")->assertForbidden();
    }

    public function test_hospital_scoped_roles_without_a_hospital_are_forbidden_everywhere(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Clinic');

        foreach (['hospital_admin', 'receptionist'] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $this->getJson('/api/clinics')->assertForbidden();
            $this->getJson("/api/clinics/{$clinic->id}")->assertForbidden();
            $this->getJson("/api/clinics/hospital/{$hospital->id}")->assertForbidden();
            $this->getJson("/api/clinics/doctor/{$doctor->id}")->assertForbidden();
        }
    }

    public function test_doctor_can_read_only_personally_assigned_clinics(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctor = $this->createUser('doctor', $hospitalA);
        $otherDoctor = $this->createUser('doctor', $hospitalA);
        $ownClinic = $this->createClinic($hospitalA, $doctor, 'Assigned Search Clinic');
        $otherClinic = $this->createClinic($hospitalA, $otherDoctor, 'Hidden Search Clinic');
        $this->createClinic($hospitalB, $doctor, 'Assigned Other Hospital');
        Sanctum::actingAs($doctor);

        $this->getJson('/api/clinics?size=1&page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $otherClinic->id]);
        $this->getJson('/api/clinics?search=Hidden')
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson("/api/clinics?hospital_id={$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $ownClinic->id]);
        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")->assertForbidden();
        $this->getJson("/api/clinics/{$ownClinic->id}")->assertOk();
        $this->getJson("/api/clinics/{$otherClinic->id}")->assertForbidden();
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctor->id}")
            ->assertOk()
            ->assertJsonCount(2);
        $this->getJson("/api/clinics/doctor/{$otherDoctor->id}")->assertForbidden();
    }

    public function test_pharmacist_is_forbidden_from_every_clinic_read_endpoint(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Protected Clinic');
        Sanctum::actingAs($this->createUser('pharmacist', $hospital));

        $this->getJson('/api/clinics')->assertForbidden()->assertJsonMissing(['id' => $clinic->id]);
        $this->getJson("/api/clinics/{$clinic->id}")->assertForbidden()->assertJsonMissing(['id' => $clinic->id]);
        $this->getJson("/api/clinics/hospital/{$hospital->id}")->assertForbidden()->assertJsonMissing(['id' => $clinic->id]);
        $this->getJson("/api/clinics/doctor/{$doctor->id}")->assertForbidden()->assertJsonMissing(['id' => $clinic->id]);
    }

    public function test_patient_can_read_only_enrolled_clinics(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $enrolledClinic = $this->createClinic($hospitalA, $doctorA, 'Enrolled Search Clinic');
        $otherEnrolledClinic = $this->createClinic($hospitalB, $doctorB, 'Second Enrolled Clinic');
        $unenrolledClinic = $this->createClinic($hospitalA, $doctorA, 'Hidden Search Clinic');
        $user = $this->createUser('patient');
        $patient = $this->createPatient($user);
        $patient->clinics()->attach([$enrolledClinic->id, $otherEnrolledClinic->id]);
        Sanctum::actingAs($user);

        $this->getJson('/api/clinics?size=1&page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $unenrolledClinic->id]);
        $this->getJson('/api/clinics?search=Hidden')
            ->assertOk()
            ->assertJsonPath('total', 0);
        $this->getJson("/api/clinics?hospital_id={$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $enrolledClinic->id]);
        $this->getJson("/api/clinics/{$enrolledClinic->id}")->assertOk();
        $this->getJson("/api/clinics/{$unenrolledClinic->id}")->assertForbidden();
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctorA->id}")->assertForbidden();
    }

    public function test_patient_without_profile_receives_controlled_forbidden_response(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $clinic = $this->createClinic($hospital, $doctor, 'Clinic');
        Sanctum::actingAs($this->createUser('patient'));

        $expected = ['message' => 'A patient profile is required'];
        $this->getJson('/api/clinics')->assertForbidden()->assertExactJson($expected);
        $this->getJson("/api/clinics/{$clinic->id}")->assertForbidden()->assertExactJson($expected);
        $this->getJson("/api/clinics/hospital/{$hospital->id}")->assertForbidden()->assertExactJson($expected);
        $this->getJson("/api/clinics/doctor/{$doctor->id}")->assertForbidden()->assertExactJson($expected);
    }

    public function test_hospital_custom_role_is_scoped_and_contextless_custom_role_is_denied(): void
    {
        [$hospitalA, $hospitalB] = $this->createTwoHospitals();
        $doctorA = $this->createUser('doctor', $hospitalA);
        $doctorB = $this->createUser('doctor', $hospitalB);
        $ownClinic = $this->createClinic($hospitalA, $doctorA, 'Custom Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, $doctorB, 'Custom Other Clinic');
        $customUser = $this->createUser('clinic_auditor', $hospitalA);
        Sanctum::actingAs($customUser);

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonFragment(['id' => $ownClinic->id]);
        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")->assertForbidden();
        $this->getJson("/api/clinics/{$otherClinic->id}")->assertForbidden();
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")->assertOk();
        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctorA->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $ownClinic->id]);
        $this->getJson("/api/clinics/doctor/{$doctorB->id}")->assertForbidden();

        Sanctum::actingAs($this->createUser('contextless_auditor'));
        $this->getJson('/api/clinics')->assertForbidden();
        $this->getJson("/api/clinics/{$ownClinic->id}")->assertForbidden();
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")->assertForbidden();
        $this->getJson("/api/clinics/doctor/{$doctorA->id}")->assertForbidden();
    }

    public function test_missing_resources_remain_not_found_and_reads_do_not_modify_clinics(): void
    {
        $hospital = $this->createHospital('Hospital');
        $doctor = $this->createUser('doctor', $hospital);
        $this->createClinic($hospital, $doctor, 'Clinic');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->getJson('/api/clinics/999999')->assertNotFound();
        $this->getJson('/api/clinics/hospital/999999')->assertNotFound();
        $this->getJson('/api/clinics/doctor/999999')->assertNotFound();
        $this->assertDatabaseCount('clinics', 1);
    }

    /** @return array{Hospital, Hospital} */
    private function createTwoHospitals(): array
    {
        return [
            $this->createHospital('Hospital A'),
            $this->createHospital('Hospital B'),
        ];
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

    private function createUser(string $roleName, ?Hospital $hospital = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching($this->viewClinic->id);

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }

    private function createClinic(Hospital $hospital, User $doctor, string $name): Clinic
    {
        return Clinic::create([
            'name' => $name,
            'hospital_id' => $hospital->id,
            'description' => 'Clinic read visibility test',
            'doctor_id' => $doctor->id,
            'location' => 'Block A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }

    private function createPatient(User $user): Patient
    {
        return Patient::create([
            'nic' => uniqid('nic-'),
            'name' => $user->name,
            'user_id' => $user->id,
        ]);
    }
}
