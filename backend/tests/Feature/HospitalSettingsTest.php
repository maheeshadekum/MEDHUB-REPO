<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HospitalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Permission $manageHospitals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manageHospitals = Permission::create([
            'name' => 'manage_hospitals',
            'description' => 'Manage hospitals',
        ]);

        Http::fake([
            '*' => Http::response('', 302, [
                'Location' => 'https://maps.google.com/@6.9271,79.8612,15z',
            ]),
        ]);
    }

    public function test_super_admin_updates_only_the_selected_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A', 'hospital-a@example.com');
        $hospitalB = $this->createHospital('Hospital B', 'hospital-b@example.com');
        $superAdmin = $this->createUser('super_admin');

        Sanctum::actingAs($superAdmin);

        $response = $this->putJson(
            "/api/hospitals/{$hospitalA->id}/settings",
            $this->settingsPayload($hospitalA, [
                'is_inventory_activated' => true,
                'is_appointment_activated' => true,
            ])
        );

        $response
            ->assertOk()
            ->assertJsonPath('id', $hospitalA->id)
            ->assertJsonPath('is_inventory_activated', true)
            ->assertJsonPath('is_appointment_activated', true);

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospitalA->id,
            'is_inventory_activated' => true,
            'is_appointment_activated' => true,
        ]);
        $this->assertDatabaseHas('hospitals', [
            'id' => $hospitalB->id,
            'is_inventory_activated' => false,
            'is_appointment_activated' => false,
        ]);
        $this->assertNull($superAdmin->fresh()->hospital_id);
    }

    public function test_super_admin_receives_not_found_for_a_missing_hospital(): void
    {
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson('/api/hospitals/999999/settings', $this->settingsPayload())
            ->assertNotFound();
    }

    public function test_hospital_admin_updates_its_assigned_hospital(): void
    {
        $hospital = $this->createHospital('Assigned Hospital', 'assigned@example.com');
        $hospitalAdmin = $this->createUser('hospital_admin', $hospital);

        Sanctum::actingAs($hospitalAdmin);

        $this->putJson(
            "/api/hospitals/{$hospital->id}/settings",
            $this->settingsPayload($hospital, ['is_inventory_activated' => true])
        )
            ->assertOk()
            ->assertJsonPath('is_inventory_activated', true);

        $this->assertDatabaseHas('hospitals', [
            'id' => $hospital->id,
            'is_inventory_activated' => true,
        ]);
    }

    public function test_hospital_admin_cannot_update_another_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital', 'assigned@example.com');
        $otherHospital = $this->createHospital('Other Hospital', 'other@example.com');

        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->putJson(
            "/api/hospitals/{$otherHospital->id}/settings",
            $this->settingsPayload($otherHospital, ['is_inventory_activated' => true])
        )->assertForbidden();

        $this->assertDatabaseHas('hospitals', [
            'id' => $otherHospital->id,
            'is_inventory_activated' => false,
        ]);
    }

    public function test_hospital_admin_without_an_assignment_receives_forbidden(): void
    {
        $hospital = $this->createHospital();

        Sanctum::actingAs($this->createUser('hospital_admin'));

        $this->putJson(
            "/api/hospitals/{$hospital->id}/settings",
            $this->settingsPayload($hospital)
        )->assertForbidden();
    }

    public function test_unapproved_roles_are_forbidden_even_with_manage_hospitals_permission(): void
    {
        $hospital = $this->createHospital();

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->putJson(
                "/api/hospitals/{$hospital->id}/settings",
                $this->settingsPayload($hospital)
            )->assertForbidden();
        }
    }

    public function test_invalid_toggle_values_fail_validation(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson(
            "/api/hospitals/{$hospital->id}/settings",
            $this->settingsPayload($hospital, ['is_inventory_activated' => 'enabled'])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_inventory_activated');
    }

    public function test_current_name_and_email_are_excluded_from_unique_validation(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson(
            "/api/hospitals/{$hospital->id}/settings",
            $this->settingsPayload($hospital)
        )->assertOk();
    }

    public function test_another_hospitals_name_and_email_fail_unique_validation(): void
    {
        $hospital = $this->createHospital('Hospital A', 'hospital-a@example.com');
        $otherHospital = $this->createHospital('Hospital B', 'hospital-b@example.com');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson(
            "/api/hospitals/{$hospital->id}/settings",
            $this->settingsPayload($hospital, [
                'name' => $otherHospital->name,
                'email' => $otherHospital->email,
            ])
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
    }

    public function test_legacy_manage_endpoint_is_limited_to_assigned_hospital_admin(): void
    {
        $hospital = $this->createHospital();

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->postJson('/api/hospitals/manage', $this->settingsPayload($hospital))
            ->assertForbidden();

        Sanctum::actingAs($this->createUser('hospital_admin', $hospital));
        $this->postJson('/api/hospitals/manage', $this->settingsPayload($hospital))
            ->assertOk();
    }

    private function createUser(string $roleName, ?Hospital $hospital = null): User
    {
        $role = Role::create(['name' => $roleName]);
        $role->permissions()->attach($this->manageHospitals);

        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $roleName)),
            'email' => uniqid($roleName . '-') . '@example.com',
            'password' => 'password',
            'status' => 'working',
            'role_id' => $role->id,
            'hospital_id' => $hospital?->id,
        ]);
    }

    private function createHospital(
        string $name = 'Test Hospital',
        string $email = 'hospital@example.com'
    ): Hospital {
        $suffix = uniqid();

        return Hospital::create([
            'name' => $name . '-' . $suffix,
            'identifier' => 'hospital-' . $suffix,
            'address' => '1 Test Street',
            'phone' => '0712345678',
            'email' => str_replace('@', '+' . $suffix . '@', $email),
            'district' => 'Colombo',
            'location_url' => 'https://maps.app.goo.gl/test',
            'location_lat' => 6.9271,
            'location_lng' => 79.8612,
            'is_inventory_activated' => false,
            'is_appointment_activated' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function settingsPayload(?Hospital $hospital = null, array $overrides = []): array
    {
        return array_merge([
            'name' => $hospital?->name ?? 'Updated Hospital',
            'address' => $hospital?->address ?? '1 Updated Street',
            'phone' => $hospital?->phone ?? '0712345678',
            'email' => $hospital?->email ?? 'updated@example.com',
            'district' => $hospital?->district ?? 'Colombo',
            'location_url' => $hospital?->location_url ?? 'https://maps.app.goo.gl/test',
            'is_inventory_activated' => $hospital?->is_inventory_activated ?? false,
            'is_appointment_activated' => $hospital?->is_appointment_activated ?? false,
        ], $overrides);
    }
}
