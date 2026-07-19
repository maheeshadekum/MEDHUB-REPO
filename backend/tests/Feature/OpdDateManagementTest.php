<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\OpdDate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OpdDateManagementTest extends TestCase
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
    }

    public function test_super_admin_creates_for_only_the_selected_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/opd-dates', $this->payload($hospitalA))
            ->assertCreated()
            ->assertJsonPath('hospital_id', $hospitalA->id);

        $this->assertDatabaseHas('opd_dates', ['hospital_id' => $hospitalA->id]);
        $this->assertDatabaseMissing('opd_dates', ['hospital_id' => $hospitalB->id]);
    }

    public function test_creation_rejects_missing_zero_and_nonexistent_hospitals(): void
    {
        Sanctum::actingAs($this->createUser('super_admin'));

        foreach ([null, 0, 999999] as $hospitalId) {
            $payload = $this->payload();
            if ($hospitalId === null) {
                unset($payload['hospital_id']);
            } else {
                $payload['hospital_id'] = $hospitalId;
            }

            $this->postJson('/api/opd-dates', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('hospital_id');
        }

        $this->assertDatabaseCount('opd_dates', 0);
    }

    public function test_creation_rejects_invalid_schedule_fields_without_writing(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createUser('super_admin'));

        $invalidPayloads = [
            [$this->payload($hospital, ['start_time' => '']), 'start_time'],
            [$this->payload($hospital, ['end_time' => '']), 'end_time'],
            [$this->payload($hospital, ['start_time' => '10:00', 'end_time' => '09:00']), 'end_time'],
            [$this->payload($hospital, ['status' => 'invalid']), 'status'],
        ];

        foreach ($invalidPayloads as [$payload, $field]) {
            $this->postJson('/api/opd-dates', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }

        $this->assertDatabaseCount('opd_dates', 0);
    }

    public function test_hospital_admin_creates_only_for_its_assigned_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->postJson('/api/opd-dates', $this->payload($assignedHospital))
            ->assertCreated()
            ->assertJsonPath('hospital_id', $assignedHospital->id);

        $this->postJson('/api/opd-dates', $this->payload($otherHospital))
            ->assertForbidden();

        $this->assertDatabaseHas('opd_dates', ['hospital_id' => $assignedHospital->id]);
        $this->assertDatabaseMissing('opd_dates', ['hospital_id' => $otherHospital->id]);
    }

    public function test_hospital_admin_without_assignment_cannot_create(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createUser('hospital_admin'));

        $this->postJson('/api/opd-dates', $this->payload($hospital))
            ->assertForbidden();

        $this->assertDatabaseCount('opd_dates', 0);
    }

    public function test_unapproved_roles_cannot_create_even_with_permission(): void
    {
        $hospital = $this->createHospital();

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->postJson('/api/opd-dates', $this->payload($hospital))
                ->assertForbidden();
        }

        $this->assertDatabaseCount('opd_dates', 0);
    }

    public function test_super_admin_updates_schedule_without_changing_hospital(): void
    {
        $hospital = $this->createHospital();
        $opdDate = $this->createOpdDate($hospital);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/opd-dates/{$opdDate->id}", $this->updatePayload([
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'completed',
        ]))
            ->assertOk()
            ->assertJsonPath('hospital_id', $hospital->id)
            ->assertJsonPath('status', 'completed');

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'hospital_id' => $hospital->id,
            'status' => 'completed',
        ]);
    }

    public function test_super_admin_cannot_transfer_an_opd_date(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $opdDate = $this->createOpdDate($hospitalA);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/opd-dates/{$opdDate->id}", $this->payload($hospitalB))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('hospital_id');

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'hospital_id' => $hospitalA->id,
        ]);
    }

    public function test_hospital_admin_updates_only_its_hospital_record(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $ownOpdDate = $this->createOpdDate($assignedHospital);
        $otherOpdDate = $this->createOpdDate($otherHospital);
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->putJson("/api/opd-dates/{$ownOpdDate->id}", $this->updatePayload([
            'start_time' => '09:00',
            'end_time' => '11:00',
        ]))->assertOk();

        $this->putJson("/api/opd-dates/{$otherOpdDate->id}", $this->payload($otherHospital))
            ->assertForbidden();

        $this->assertDatabaseHas('opd_dates', [
            'id' => $otherOpdDate->id,
            'hospital_id' => $otherHospital->id,
            'start_time' => '08:00',
        ]);
    }

    public function test_hospital_admin_cannot_transfer_its_opd_date(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        $opdDate = $this->createOpdDate($assignedHospital);
        Sanctum::actingAs($this->createUser('hospital_admin', $assignedHospital));

        $this->putJson("/api/opd-dates/{$opdDate->id}", $this->payload($otherHospital))
            ->assertForbidden();

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'hospital_id' => $assignedHospital->id,
        ]);
    }

    public function test_unapproved_roles_cannot_update_even_with_permission(): void
    {
        $hospital = $this->createHospital();
        $opdDate = $this->createOpdDate($hospital);

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->putJson("/api/opd-dates/{$opdDate->id}", $this->payload())
                ->assertForbidden();
        }

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'start_time' => '08:00',
        ]);
    }

    public function test_approved_roles_can_update_status_with_hospital_scope(): void
    {
        $hospital = $this->createHospital();
        $otherHospital = $this->createHospital('Other Hospital');
        $opdDate = $this->createOpdDate($hospital);

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->patchJson("/api/opd-dates/{$opdDate->id}/status", ['status' => 'completed'])
            ->assertOk();

        Sanctum::actingAs($this->createUser('hospital_admin', $otherHospital));
        $this->patchJson("/api/opd-dates/{$opdDate->id}/status", ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'status' => 'completed',
        ]);
    }

    public function test_unapproved_roles_cannot_update_status_even_with_permission(): void
    {
        $hospital = $this->createHospital();
        $opdDate = $this->createOpdDate($hospital);

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->patchJson("/api/opd-dates/{$opdDate->id}/status", [
                'status' => 'completed',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('opd_dates', [
            'id' => $opdDate->id,
            'status' => 'scheduled',
        ]);
    }

    private function createUser(string $roleName, ?Hospital $hospital = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching([$this->manageHospitals->id]);

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

    private function createOpdDate(Hospital $hospital): OpdDate
    {
        return OpdDate::create($this->payload($hospital));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(?Hospital $hospital = null, array $overrides = []): array
    {
        return array_merge([
            'hospital_id' => $hospital?->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '10:00',
            'status' => 'scheduled',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(array $overrides = []): array
    {
        $payload = $this->payload(null, $overrides);
        unset($payload['hospital_id']);

        return $payload;
    }
}
