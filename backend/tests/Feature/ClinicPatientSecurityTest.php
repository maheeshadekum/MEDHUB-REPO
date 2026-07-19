<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\ClinicPatients;
use App\Models\Hospital;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicPatientSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    private Permission $viewPermission;
    private Permission $managePermission;
    private Hospital $hospitalA;
    private Hospital $hospitalB;
    private Clinic $clinicA;
    private Clinic $clinicASecond;
    private Clinic $clinicB;
    private Patient $patientA;
    private Patient $patientB;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['super_admin', 'hospital_admin', 'doctor', 'pharmacist', 'receptionist', 'patient'] as $roleName) {
            $this->roles[$roleName] = Role::create(['name' => $roleName]);
        }

        $this->viewPermission = Permission::create([
            'name' => 'view_clinic_patients',
            'description' => 'View clinic patients',
        ]);
        $this->managePermission = Permission::create([
            'name' => 'manage_clinic_patients',
            'description' => 'Manage clinic patients',
        ]);

        foreach ($this->roles as $role) {
            $role->permissions()->attach($this->viewPermission);
        }
        $this->roles['super_admin']->permissions()->attach($this->managePermission);
        $this->roles['hospital_admin']->permissions()->attach($this->managePermission);
        $this->roles['receptionist']->permissions()->attach($this->managePermission);

        $this->hospitalA = $this->createHospital('A');
        $this->hospitalB = $this->createHospital('B');
        $this->clinicA = $this->createClinic($this->hospitalA, 'Cardiology');
        $this->clinicASecond = $this->createClinic($this->hospitalA, 'Neurology');
        $this->clinicB = $this->createClinic($this->hospitalB, 'Oncology');
        $this->patientA = $this->createPatient('Patient Alpha', 'NIC-A', '0711111111');
        $this->patientB = $this->createPatient('Patient Beta', 'NIC-B', '0722222222');
    }

    public function test_super_admin_can_manage_assignments_globally(): void
    {
        Sanctum::actingAs($this->createUser($this->roles['super_admin']));
        $hospitalBAssignment = $this->createAssignment($this->clinicB, $this->patientB);

        $this->postJson('/api/clinic-patients', [
            'clinic_id' => $this->clinicA->id,
            'patient_id' => $this->patientA->id,
        ])->assertCreated()
            ->assertJsonPath('clinic_id', $this->clinicA->id)
            ->assertJsonPath('patient_id', $this->patientA->id);

        $assignment = ClinicPatients::where('clinic_id', $this->clinicA->id)->firstOrFail();
        $this->getJson("/api/clinic-patients/{$hospitalBAssignment->id}")->assertOk();
        $this->getJson('/api/clinic-patients')->assertOk()->assertJsonCount(2, 'data');

        $this->putJson("/api/clinic-patients/{$assignment->id}", [
            'clinic_id' => $this->clinicASecond->id,
            'patient_id' => $this->patientA->id,
        ])->assertOk()->assertJsonPath('clinic_id', $this->clinicASecond->id);

        $this->deleteJson("/api/clinic-patients/{$assignment->id}")->assertNoContent();
        $this->assertDatabaseMissing('clinic_patients', ['id' => $assignment->id]);
        $this->assertDatabaseHas('clinic_patients', ['id' => $hospitalBAssignment->id]);
    }

    public function test_hospital_admin_and_receptionist_manage_only_their_hospital(): void
    {
        foreach (['hospital_admin', 'receptionist'] as $roleName) {
            $actor = $this->createUser($this->roles[$roleName], $this->hospitalA);
            Sanctum::actingAs($actor);
            $ownPatient = $this->createPatient("{$roleName} Own", uniqid('NIC-'), '0733333333');
            $otherPatient = $this->createPatient("{$roleName} Other", uniqid('NIC-'), '0744444444');
            $crossAssignment = $this->createAssignment($this->clinicB, $otherPatient);

            $this->postJson('/api/clinic-patients', [
                'clinic_id' => $this->clinicA->id,
                'patient_id' => $ownPatient->id,
            ])->assertCreated();
            $ownAssignment = ClinicPatients::where('clinic_id', $this->clinicA->id)
                ->where('patient_id', $ownPatient->id)
                ->firstOrFail();

            $this->postJson('/api/clinic-patients', [
                'clinic_id' => $this->clinicB->id,
                'patient_id' => $ownPatient->id,
            ])->assertForbidden();
            $this->getJson("/api/clinic-patients/{$ownAssignment->id}")->assertOk();
            $this->getJson("/api/clinic-patients/{$crossAssignment->id}")->assertForbidden();

            $this->putJson("/api/clinic-patients/{$ownAssignment->id}", [
                'clinic_id' => $this->clinicASecond->id,
                'patient_id' => $ownPatient->id,
            ])->assertOk();
            $this->putJson("/api/clinic-patients/{$ownAssignment->id}", [
                'clinic_id' => $this->clinicB->id,
                'patient_id' => $ownPatient->id,
            ])->assertForbidden();
            $this->putJson("/api/clinic-patients/{$crossAssignment->id}", [
                'clinic_id' => $this->clinicA->id,
                'patient_id' => $otherPatient->id,
            ])->assertForbidden();

            $this->deleteJson("/api/clinic-patients/{$crossAssignment->id}")->assertForbidden();
            $this->assertDatabaseHas('clinic_patients', ['id' => $crossAssignment->id]);
            $this->deleteJson("/api/clinic-patients/{$ownAssignment->id}")->assertNoContent();
        }
    }

    public function test_hospital_scoped_list_is_enforced_for_approved_staff_roles(): void
    {
        $ownAssignment = $this->createAssignment($this->clinicA, $this->patientA);
        $crossAssignment = $this->createAssignment($this->clinicB, $this->patientB);

        foreach (['hospital_admin', 'receptionist', 'doctor', 'pharmacist'] as $roleName) {
            Sanctum::actingAs($this->createUser($this->roles[$roleName], $this->hospitalA));

            $this->getJson('/api/clinic-patients')
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $ownAssignment->id);
            $this->getJson("/api/clinic-patients/{$ownAssignment->id}")->assertOk();
            $this->getJson("/api/clinic-patients/{$crossAssignment->id}")->assertForbidden();
        }
    }

    public function test_hospital_scoped_staff_without_a_hospital_are_forbidden(): void
    {
        foreach (['hospital_admin', 'receptionist', 'doctor', 'pharmacist'] as $roleName) {
            Sanctum::actingAs($this->createUser($this->roles[$roleName]));
            $this->getJson('/api/clinic-patients')->assertForbidden();
        }
    }

    public function test_doctor_and_pharmacist_cannot_mutate_even_with_manage_permission(): void
    {
        foreach (['doctor', 'pharmacist'] as $roleName) {
            $role = $this->roles[$roleName];
            $role->permissions()->syncWithoutDetaching($this->managePermission);
            Sanctum::actingAs($this->createUser($role, $this->hospitalA));
            $assignment = $this->createAssignment($this->clinicA, $this->patientA);

            $this->postJson('/api/clinic-patients', [
                'clinic_id' => $this->clinicASecond->id,
                'patient_id' => $this->patientB->id,
            ])->assertForbidden();
            $this->putJson("/api/clinic-patients/{$assignment->id}", [
                'clinic_id' => $this->clinicASecond->id,
                'patient_id' => $this->patientA->id,
            ])->assertForbidden();
            $this->deleteJson("/api/clinic-patients/{$assignment->id}")->assertForbidden();
            $this->assertDatabaseHas('clinic_patients', ['id' => $assignment->id]);

            $assignment->delete();
        }
    }

    public function test_patient_can_read_only_their_own_assignments_and_cannot_mutate(): void
    {
        $patientUser = $this->createUser($this->roles['patient']);
        $linkedPatient = $this->createPatient('Linked Patient', 'NIC-LINKED', '0755555555', $patientUser);
        $ownAssignment = $this->createAssignment($this->clinicA, $linkedPatient);
        $otherAssignment = $this->createAssignment($this->clinicASecond, $this->patientB);
        $this->roles['patient']->permissions()->syncWithoutDetaching($this->managePermission);
        Sanctum::actingAs($patientUser);

        $this->getJson('/api/clinic-patients')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownAssignment->id);
        $this->getJson("/api/clinic-patients/{$ownAssignment->id}")->assertOk();
        $this->getJson("/api/clinic-patients/{$otherAssignment->id}")->assertForbidden();

        $this->postJson('/api/clinic-patients', [
            'clinic_id' => $this->clinicB->id,
            'patient_id' => $linkedPatient->id,
        ])->assertForbidden();
        $this->putJson("/api/clinic-patients/{$ownAssignment->id}", [
            'clinic_id' => $this->clinicASecond->id,
            'patient_id' => $linkedPatient->id,
        ])->assertForbidden();
        $this->deleteJson("/api/clinic-patients/{$ownAssignment->id}")->assertForbidden();
        $this->assertDatabaseHas('clinic_patients', ['id' => $ownAssignment->id]);
    }

    public function test_custom_role_cannot_mutate_or_receive_unscoped_reads(): void
    {
        $customRole = Role::create(['name' => 'custom_clinic_manager']);
        $customRole->permissions()->attach([$this->viewPermission->id, $this->managePermission->id]);
        $assignment = $this->createAssignment($this->clinicA, $this->patientA);

        Sanctum::actingAs($this->createUser($customRole));
        $this->getJson('/api/clinic-patients')->assertForbidden();
        $this->getJson("/api/clinic-patients/{$assignment->id}")->assertForbidden();
        $this->postJson('/api/clinic-patients', [
            'clinic_id' => $this->clinicA->id,
            'patient_id' => $this->patientB->id,
        ])->assertForbidden();
        $this->putJson("/api/clinic-patients/{$assignment->id}", [
            'clinic_id' => $this->clinicASecond->id,
            'patient_id' => $this->patientA->id,
        ])->assertForbidden();
        $this->deleteJson("/api/clinic-patients/{$assignment->id}")->assertForbidden();
        $this->assertDatabaseHas('clinic_patients', ['id' => $assignment->id]);

        Sanctum::actingAs($this->createUser($customRole, $this->hospitalA));
        $this->getJson('/api/clinic-patients')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_create_validation_and_duplicates_make_no_partial_changes(): void
    {
        Sanctum::actingAs($this->createUser($this->roles['super_admin']));

        foreach ([
            ['patient_id' => $this->patientA->id],
            ['clinic_id' => $this->clinicA->id],
            ['clinic_id' => 999999, 'patient_id' => $this->patientA->id],
            ['clinic_id' => $this->clinicA->id, 'patient_id' => 999999],
        ] as $payload) {
            $before = ClinicPatients::count();
            $this->postJson('/api/clinic-patients', $payload)->assertUnprocessable();
            $this->assertSame($before, ClinicPatients::count());
        }

        $this->createAssignment($this->clinicA, $this->patientA);
        $this->postJson('/api/clinic-patients', [
            'clinic_id' => $this->clinicA->id,
            'patient_id' => $this->patientA->id,
        ])->assertUnprocessable();
        $this->assertSame(1, ClinicPatients::where('clinic_id', $this->clinicA->id)
            ->where('patient_id', $this->patientA->id)->count());
    }

    public function test_update_duplicate_conflict_leaves_original_assignment_unchanged(): void
    {
        Sanctum::actingAs($this->createUser($this->roles['super_admin']));
        $existing = $this->createAssignment($this->clinicA, $this->patientA);
        $target = $this->createAssignment($this->clinicASecond, $this->patientB);

        $this->putJson("/api/clinic-patients/{$target->id}", [
            'clinic_id' => $this->clinicA->id,
            'patient_id' => $this->patientA->id,
        ])->assertUnprocessable();

        $this->assertDatabaseHas('clinic_patients', [
            'id' => $target->id,
            'clinic_id' => $this->clinicASecond->id,
            'patient_id' => $this->patientB->id,
        ]);
        $this->assertDatabaseHas('clinic_patients', ['id' => $existing->id]);
    }

    public function test_search_uses_patient_fields_without_a_linked_user_and_stays_hospital_scoped(): void
    {
        $namePatient = $this->createPatient('Unique Search Name', 'NIC-NAME', '0760000001');
        $nicPatient = $this->createPatient('NIC Search', 'UNIQUE-NIC-777', '0760000002');
        $phonePatient = $this->createPatient('Phone Search', 'NIC-PHONE', '0779998888');
        $crossPatient = $this->createPatient('Unique Search Name', 'NIC-CROSS', '0760000003');
        $this->createAssignment($this->clinicA, $namePatient);
        $this->createAssignment($this->clinicA, $nicPatient);
        $this->createAssignment($this->clinicA, $phonePatient);
        $this->createAssignment($this->clinicB, $crossPatient);
        Sanctum::actingAs($this->createUser($this->roles['hospital_admin'], $this->hospitalA));

        foreach (['Unique Search Name', 'UNIQUE-NIC-777', '0779998888'] as $search) {
            $this->getJson('/api/clinic-patients?search=' . urlencode($search))
                ->assertOk()
                ->assertJsonCount(1, 'data');
        }
    }

    public function test_patient_search_remains_scoped_to_the_authenticated_patient(): void
    {
        $patientUser = $this->createUser($this->roles['patient']);
        $linkedPatient = $this->createPatient('Shared Search', 'NIC-OWN', '0780000001', $patientUser);
        $otherPatient = $this->createPatient('Shared Search', 'NIC-OTHER', '0780000002');
        $ownAssignment = $this->createAssignment($this->clinicA, $linkedPatient);
        $this->createAssignment($this->clinicB, $otherPatient);
        Sanctum::actingAs($patientUser);

        $this->getJson('/api/clinic-patients?search=Shared%20Search')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownAssignment->id);
    }

    private function createHospital(string $suffix): Hospital
    {
        return Hospital::create([
            'name' => "Hospital {$suffix}",
            'identifier' => "hospital-{$suffix}",
            'email' => "hospital-{$suffix}@example.com",
        ]);
    }

    private function createClinic(Hospital $hospital, string $name): Clinic
    {
        $doctor = $this->createUser($this->roles['doctor'], $hospital);

        return Clinic::create([
            'name' => $name,
            'hospital_id' => $hospital->id,
            'description' => "{$name} clinic",
            'doctor_id' => $doctor->id,
            'location' => 'Main Building',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }

    private function createPatient(
        string $name,
        string $nic,
        string $phone,
        ?User $user = null,
    ): Patient {
        return Patient::create([
            'name' => $name,
            'nic' => $nic,
            'phone' => $phone,
            'user_id' => $user?->id,
        ]);
    }

    private function createAssignment(Clinic $clinic, Patient $patient): ClinicPatients
    {
        return ClinicPatients::create([
            'clinic_id' => $clinic->id,
            'patient_id' => $patient->id,
        ]);
    }

    private function createUser(Role $role, ?Hospital $hospital = null): User
    {
        return User::create([
            'name' => 'Test ' . $role->name,
            'email' => uniqid($role->name . '-') . '@example.com',
            'password' => 'password123',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }
}
