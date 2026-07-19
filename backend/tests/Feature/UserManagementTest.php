<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, Permission> */
    private array $permissions = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        foreach (['super_admin', 'hospital_admin', 'doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            $this->roles[$role] = Role::create(['name' => $role]);
        }

        foreach (['view_users', 'create_users', 'update_users', 'update_users_hospital'] as $permission) {
            $this->permissions[$permission] = Permission::create([
                'name' => $permission,
                'description' => $permission,
            ]);
            $this->roles['super_admin']->permissions()->attach($this->permissions[$permission]);
        }
    }

    public function test_super_admin_creates_each_staff_role_with_hospital_and_status_atomically(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        foreach (['hospital_admin', 'doctor', 'pharmacist', 'receptionist'] as $index => $role) {
            $email = $role . '@example.com';

            $this->postJson('/api/users', $this->createPayload([
                'name' => ucfirst($role),
                'email' => $email,
                'role' => $role,
                'hospital_id' => $hospital->id,
                'status' => $index === 0 ? 'retired' : 'working',
            ]))
                ->assertCreated()
                ->assertJsonPath('user.role', $role)
                ->assertJsonPath('user.hospital_id', $hospital->id)
                ->assertJsonPath('user.hospital', $hospital->name);

            $this->assertDatabaseHas('users', [
                'email' => $email,
                'role_id' => $this->roles[$role]->id,
                'hospital_id' => $hospital->id,
                'status' => $index === 0 ? 'retired' : 'working',
            ]);
        }
    }

    public function test_scoped_staff_validation_rejects_missing_zero_and_unknown_hospitals_without_partial_users(): void
    {
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        foreach (['hospital_admin', 'doctor', 'pharmacist', 'receptionist'] as $role) {
            $email = $role . '-missing@example.com';
            $this->postJson('/api/users', $this->createPayload([
                'email' => $email,
                'role' => $role,
                'hospital_id' => null,
            ]))->assertUnprocessable()->assertJsonValidationErrors('hospital_id');
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }

        foreach ([0, 999999] as $hospitalId) {
            $email = "invalid-hospital-{$hospitalId}@example.com";
            $this->postJson('/api/users', $this->createPayload([
                'email' => $email,
                'hospital_id' => $hospitalId,
            ]))->assertUnprocessable()->assertJsonValidationErrors('hospital_id');
            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_create_validates_email_password_confirmation_status_and_patient_role(): void
    {
        $hospital = $this->createHospital();
        $existing = $this->createExistingUser('doctor', $hospital, 'duplicate@example.com');
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        $cases = [
            ['email' => $existing->email],
            ['email' => 'short@example.com', 'password' => 'short6', 'password_confirmation' => 'short6'],
            ['email' => 'mismatch@example.com', 'password_confirmation' => 'different1'],
            ['email' => 'status@example.com', 'status' => 'active'],
            ['email' => 'patient@example.com', 'role' => 'patient', 'hospital_id' => null],
        ];

        foreach ($cases as $payload) {
            $email = $payload['email'];
            $this->postJson('/api/users', $this->createPayload($payload))->assertUnprocessable();
            if ($email !== $existing->email) {
                $this->assertDatabaseMissing('users', ['email' => $email]);
            }
        }
    }

    public function test_super_admin_creation_defaults_to_working_and_rejects_a_hospital(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        $payload = $this->createPayload([
            'email' => 'second-admin@example.com',
            'role' => 'super_admin',
            'hospital_id' => null,
        ]);
        unset($payload['status']);

        $this->postJson('/api/users', $payload)->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'second-admin@example.com',
            'role_id' => $this->roles['super_admin']->id,
            'hospital_id' => null,
            'status' => 'working',
        ]);

        $this->postJson('/api/users', $this->createPayload([
            'email' => 'assigned-admin@example.com',
            'role' => 'super_admin',
            'hospital_id' => $hospital->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('hospital_id');
    }

    public function test_unapproved_roles_cannot_create_staff_even_with_permission(): void
    {
        $hospital = $this->createHospital();

        foreach (['doctor', 'pharmacist', 'receptionist', 'patient'] as $role) {
            $this->roles[$role]->permissions()->attach($this->permissions['create_users']);
            Sanctum::actingAs($this->createExistingUser($role, $hospital));

            $this->postJson('/api/users', $this->createPayload([
                'email' => "created-by-{$role}@example.com",
                'hospital_id' => $hospital->id,
            ]))->assertForbidden();
        }
    }

    public function test_hospital_admin_is_not_granted_staff_creation_authority(): void
    {
        $hospital = $this->createHospital();
        $this->roles['hospital_admin']->permissions()->attach($this->permissions['create_users']);
        Sanctum::actingAs($this->createExistingUser('hospital_admin', $hospital));

        $this->postJson('/api/users', $this->createPayload([
            'email' => 'created-by-hospital-admin@example.com',
            'hospital_id' => $hospital->id,
        ]))->assertForbidden();
    }

    public function test_super_admin_edits_staff_without_a_password_and_can_change_staff_role(): void
    {
        $hospital = $this->createHospital();
        $staff = $this->createExistingUser('doctor', $hospital, 'doctor@example.com');
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        $this->putJson("/api/users/{$staff->id}", [
            'name' => 'Updated Pharmacist',
            'email' => 'updated@example.com',
            'role' => 'pharmacist',
            'hospital_id' => $hospital->id,
            'status' => 'retired',
        ])
            ->assertOk()
            ->assertJsonPath('user.role', 'pharmacist')
            ->assertJsonPath('user.hospital', $hospital->name);

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'name' => 'Updated Pharmacist',
            'role_id' => $this->roles['pharmacist']->id,
            'hospital_id' => $hospital->id,
            'status' => 'retired',
        ]);
    }

    public function test_update_enforces_role_hospital_consistency_and_patient_boundaries(): void
    {
        $hospital = $this->createHospital();
        $staff = $this->createExistingUser('doctor', $hospital, 'staff@example.com');
        $patient = $this->createExistingUser('patient', null, 'patient@example.com');
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        $this->putJson("/api/users/{$staff->id}", $this->updatePayload($staff, [
            'hospital_id' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('hospital_id');

        $this->putJson("/api/users/{$staff->id}", $this->updatePayload($staff, [
            'role' => 'patient',
            'hospital_id' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->putJson("/api/users/{$patient->id}", $this->updatePayload($patient, [
            'role' => 'doctor',
            'hospital_id' => $hospital->id,
        ]))->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'role_id' => $this->roles['doctor']->id,
            'hospital_id' => $hospital->id,
        ]);
    }

    public function test_staff_can_be_promoted_to_super_admin_only_without_hospital(): void
    {
        $hospital = $this->createHospital();
        $staff = $this->createExistingUser('doctor', $hospital);
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        $this->putJson("/api/users/{$staff->id}", $this->updatePayload($staff, [
            'role' => 'super_admin',
            'hospital_id' => null,
        ]))->assertOk()->assertJsonPath('user.hospital_id', null);

        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'role_id' => $this->roles['super_admin']->id,
            'hospital_id' => null,
        ]);
    }

    public function test_change_hospital_only_accepts_scoped_staff_and_valid_hospitals(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        Sanctum::actingAs($this->createExistingUser('super_admin'));

        foreach (['hospital_admin', 'doctor', 'pharmacist', 'receptionist'] as $role) {
            $staff = $this->createExistingUser($role, $hospitalA);
            $this->postJson("/api/users/hospital/{$staff->id}", [
                'hospital_id' => $hospitalB->id,
            ])->assertOk()->assertJsonPath('user.hospital', $hospitalB->name);
            $this->assertSame($hospitalB->id, $staff->fresh()->hospital_id);
        }

        foreach (['super_admin', 'patient'] as $role) {
            $user = $this->createExistingUser($role);
            $this->postJson("/api/users/hospital/{$user->id}", [
                'hospital_id' => $hospitalB->id,
            ])->assertForbidden();
        }

        $staff = $this->createExistingUser('doctor', $hospitalA);
        $this->postJson("/api/users/hospital/{$staff->id}", [
            'hospital_id' => 999999,
        ])->assertUnprocessable()->assertJsonValidationErrors('hospital_id');
    }

    public function test_status_changes_are_validated_and_self_protected(): void
    {
        $hospital = $this->createHospital();
        $admin = $this->createExistingUser('super_admin');
        $staff = $this->createExistingUser('doctor', $hospital);
        Sanctum::actingAs($admin);

        foreach (['working', 'retired', 'banned'] as $status) {
            $this->postJson("/api/users/status/{$staff->id}", ['status' => $status])
                ->assertOk()
                ->assertJsonPath('user.status', $status);
        }

        $this->postJson("/api/users/status/{$staff->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        foreach (['retired', 'banned'] as $status) {
            $this->postJson("/api/users/status/{$admin->id}", ['status' => $status])
                ->assertForbidden();
            $this->assertSame('working', $admin->fresh()->status);
        }
    }

    public function test_hospital_admin_preserves_same_hospital_status_access_without_cross_hospital_access(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $this->roles['hospital_admin']->permissions()->attach($this->permissions['update_users']);
        $hospitalAdmin = $this->createExistingUser('hospital_admin', $hospitalA);
        $ownStaff = $this->createExistingUser('doctor', $hospitalA);
        $otherStaff = $this->createExistingUser('doctor', $hospitalB);
        Sanctum::actingAs($hospitalAdmin);

        $this->postJson("/api/users/status/{$ownStaff->id}", ['status' => 'retired'])
            ->assertOk();
        $this->assertSame('retired', $ownStaff->fresh()->status);

        $this->postJson("/api/users/status/{$otherStaff->id}", ['status' => 'retired'])
            ->assertForbidden();
        $this->assertSame('working', $otherStaff->fresh()->status);
    }

    public function test_self_demotion_and_removing_last_working_super_admin_are_blocked(): void
    {
        $hospital = $this->createHospital();
        $admin = $this->createExistingUser('super_admin');
        Sanctum::actingAs($admin);

        $this->putJson("/api/users/{$admin->id}", $this->updatePayload($admin, [
            'role' => 'doctor',
            'hospital_id' => $hospital->id,
        ]))->assertForbidden();

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role_id' => $this->roles['super_admin']->id,
            'status' => 'working',
            'hospital_id' => null,
        ]);

        $admin->update(['status' => 'retired']);
        $lastWorkingAdmin = $this->createExistingUser('super_admin', null, 'last-admin@example.com');
        Sanctum::actingAs($admin);

        $this->postJson("/api/users/status/{$lastWorkingAdmin->id}", ['status' => 'banned'])
            ->assertForbidden();
        $this->assertSame('working', $lastWorkingAdmin->fresh()->status);
    }

    public function test_super_admin_accounts_cannot_be_deleted(): void
    {
        $admin = $this->createExistingUser('super_admin');
        $otherAdmin = $this->createExistingUser('super_admin');
        Sanctum::actingAs($admin);

        $this->deleteJson("/api/users/{$otherAdmin->id}")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id]);
    }

    public function test_list_and_details_include_hospital_context(): void
    {
        $hospital = $this->createHospital();
        $admin = $this->createExistingUser('super_admin');
        $staff = $this->createExistingUser('doctor', $hospital, 'listed-doctor@example.com');
        Sanctum::actingAs($admin);

        $this->getJson('/api/users?size=20')
            ->assertOk()
            ->assertJsonFragment([
                'email' => $staff->email,
                'hospital_id' => $hospital->id,
                'hospital' => $hospital->name,
            ])
            ->assertJsonFragment([
                'email' => $admin->email,
                'hospital_id' => null,
                'hospital' => null,
            ]);

        $this->getJson("/api/users/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('hospital_id', $hospital->id)
            ->assertJsonPath('hospital', $hospital->name)
            ->assertJsonPath('status', 'working');
    }

    private function createExistingUser(
        string $role,
        ?Hospital $hospital = null,
        ?string $email = null,
        string $status = 'working'
    ): User {
        return User::create([
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'email' => $email ?? uniqid($role . '-') . '@example.com',
            'password' => 'password123',
            'role_id' => $this->roles[$role]->id,
            'hospital_id' => $hospital?->id,
            'status' => $status,
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
            'location_url' => 'https://maps.example.test/hospital',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Doctor',
            'email' => uniqid('new-user-') . '@example.com',
            'role' => 'doctor',
            'hospital_id' => null,
            'status' => 'working',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function updatePayload(User $user, array $overrides = []): array
    {
        $user->loadMissing('role');

        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->name,
            'hospital_id' => $user->hospital_id,
            'status' => $user->status,
        ], $overrides);
    }
}
