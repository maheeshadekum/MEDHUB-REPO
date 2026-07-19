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

class ClinicOptionSecurityTest extends TestCase
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

    public function test_receptionist_general_list_is_scoped_and_rejects_cross_hospital_filter(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $ownClinic = $this->createClinic($hospitalA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, 'Other Clinic');
        Sanctum::actingAs($this->createUser('receptionist', $hospitalA));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ownClinic->id)
            ->assertJsonMissing(['id' => $otherClinic->id]);

        $this->getJson("/api/clinics?hospital_id={$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ownClinic->id);

        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")
            ->assertForbidden()
            ->assertJsonMissing(['id' => $otherClinic->id]);

        $this->assertDatabaseCount('clinics', 2);
    }

    public function test_receptionist_search_and_pagination_remain_scoped(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $firstOwnClinic = $this->createClinic($hospitalA, 'Shared Clinic A');
        $this->createClinic($hospitalA, 'Shared Clinic B');
        $otherClinic = $this->createClinic($hospitalB, 'Shared Clinic Other');
        Sanctum::actingAs($this->createUser('receptionist', $hospitalA));

        $this->getJson('/api/clinics?search=Shared&size=1&page=1')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['id' => $otherClinic->id]);

        $this->getJson('/api/clinics?search=Other')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonMissing(['id' => $otherClinic->id]);

        $this->assertDatabaseHas('clinics', ['id' => $firstOwnClinic->id]);
        $this->assertDatabaseCount('clinics', 3);
    }

    public function test_receptionist_hospital_endpoint_allows_own_and_rejects_other_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $ownClinic = $this->createClinic($hospitalA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, 'Other Clinic');
        Sanctum::actingAs($this->createUser('receptionist', $hospitalA));

        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $ownClinic->id);

        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")
            ->assertForbidden()
            ->assertJsonMissing(['id' => $otherClinic->id]);

        $this->assertDatabaseCount('clinics', 2);
    }

    public function test_hospital_scoped_roles_without_hospital_context_receive_forbidden(): void
    {
        $hospital = $this->createHospital();

        foreach (['receptionist', 'hospital_admin'] as $roleName) {
            Sanctum::actingAs($this->createUser($roleName));

            $this->getJson('/api/clinics')->assertForbidden();
            $this->getJson("/api/clinics/hospital/{$hospital->id}")->assertForbidden();
        }
    }

    public function test_hospital_admin_remains_scoped_in_both_clinic_option_flows(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $ownClinic = $this->createClinic($hospitalA, 'Own Clinic');
        $otherClinic = $this->createClinic($hospitalB, 'Other Clinic');
        Sanctum::actingAs($this->createUser('hospital_admin', $hospitalA));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $ownClinic->id);
        $this->getJson("/api/clinics?hospital_id={$hospitalB->id}")
            ->assertForbidden()
            ->assertJsonMissing(['id' => $otherClinic->id]);
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('0.id', $ownClinic->id);
        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('clinics', 2);
    }

    public function test_super_admin_preserves_global_and_hospital_filtered_access(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $clinicA = $this->createClinic($hospitalA, 'Clinic A');
        $clinicB = $this->createClinic($hospitalB, 'Clinic B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->getJson('/api/clinics')
            ->assertOk()
            ->assertJsonPath('total', 2);
        $this->getJson("/api/clinics?hospital_id={$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $clinicA->id);
        $this->getJson("/api/clinics/hospital/{$hospitalA->id}")
            ->assertOk()
            ->assertJsonPath('0.id', $clinicA->id);
        $this->getJson("/api/clinics/hospital/{$hospitalB->id}")
            ->assertOk()
            ->assertJsonPath('0.id', $clinicB->id);
    }

    public function test_view_clinic_permission_is_still_required_before_controller_scope(): void
    {
        $hospital = $this->createHospital();
        $role = Role::create(['name' => 'receptionist']);
        $user = User::create([
            'name' => 'Receptionist Without Permission',
            'email' => 'receptionist-no-permission@example.com',
            'password' => 'password',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital->id,
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/clinics')->assertForbidden();
        $this->getJson("/api/clinics/hospital/{$hospital->id}")->assertForbidden();
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

    private function createClinic(Hospital $hospital, string $name): Clinic
    {
        $doctor = $this->createUser('doctor', $hospital);

        return Clinic::create([
            'name' => $name,
            'hospital_id' => $hospital->id,
            'description' => 'Clinic option security test',
            'doctor_id' => $doctor->id,
            'location' => 'Block A',
            'total_hourly_tokens' => 10,
            'self_hourly_tokens' => 5,
        ]);
    }
}
