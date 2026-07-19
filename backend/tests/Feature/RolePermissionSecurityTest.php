<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RolePermissionSecurityTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Role> */
    private array $roles = [];

    /** @var array<string, Permission> */
    private array $permissions = [];

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (Role::SYSTEM_ROLE_NAMES as $roleName) {
            $this->roles[$roleName] = Role::create(['name' => $roleName]);
        }

        $permissionNames = array_merge(
            Permission::PROTECTED_ADMIN_PERMISSION_NAMES,
            ['non_protected_permission'],
        );

        foreach ($permissionNames as $permissionName) {
            $this->permissions[$permissionName] = Permission::create([
                'name' => $permissionName,
                'description' => $permissionName,
            ]);
        }

        $this->roles['super_admin']->permissions()->attach(
            collect($this->permissions)->pluck('id')->all(),
        );

        $this->superAdmin = $this->createUser($this->roles['super_admin']);
    }

    public function test_super_admin_can_manage_custom_roles(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/roles', ['name' => '  care coordinator  '])
            ->assertCreated()
            ->assertJsonPath('role.name', 'care coordinator');

        $role = Role::where('name', 'care coordinator')->firstOrFail();

        $this->putJson("/api/roles/{$role->id}", ['name' => 'senior coordinator'])
            ->assertOk()
            ->assertJsonPath('role.name', 'senior coordinator');

        $this->deleteJson("/api/roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_non_super_admin_roles_cannot_mutate_roles_even_with_dynamic_permissions(): void
    {
        $mutationPermissions = $this->permissionIds(['create_roles', 'update_roles']);

        foreach (['hospital_admin', 'doctor', 'pharmacist', 'receptionist', 'patient'] as $roleName) {
            $role = $this->roles[$roleName];
            $role->permissions()->syncWithoutDetaching($mutationPermissions);
            Sanctum::actingAs($this->createUser($role));

            $customRole = Role::create(['name' => "custom_{$roleName}"]);

            $this->postJson('/api/roles', ['name' => "created_{$roleName}"])->assertForbidden();
            $this->putJson("/api/roles/{$customRole->id}", ['name' => "updated_{$roleName}"])->assertForbidden();
            $this->deleteJson("/api/roles/{$customRole->id}")->assertForbidden();

            $this->assertDatabaseHas('roles', [
                'id' => $customRole->id,
                'name' => "custom_{$roleName}",
            ]);
            $this->assertDatabaseMissing('roles', ['name' => "created_{$roleName}"]);
        }
    }

    public function test_all_system_roles_are_immutable_and_their_pivots_remain_unchanged(): void
    {
        Sanctum::actingAs($this->superAdmin);

        foreach (Role::SYSTEM_ROLE_NAMES as $roleName) {
            $role = $this->roles[$roleName];
            $role->permissions()->syncWithoutDetaching($this->permissions['non_protected_permission']->id);
            $pivotCount = $role->permissions()->count();

            $this->putJson("/api/roles/{$role->id}", ['name' => "renamed_{$roleName}"])
                ->assertUnprocessable();
            $this->deleteJson("/api/roles/{$role->id}")->assertUnprocessable();

            $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => $roleName]);
            $this->assertSame($pivotCount, $role->permissions()->count());
        }
    }

    public function test_role_names_are_trimmed_bounded_unique_and_cannot_variant_system_names(): void
    {
        Sanctum::actingAs($this->superAdmin);
        Role::create(['name' => 'Existing Custom']);

        foreach (['   ', 'EXISTING CUSTOM', 'SUPER_ADMIN', ' Super_Admin ', str_repeat('a', 256)] as $name) {
            $before = Role::count();
            $this->postJson('/api/roles', ['name' => $name])->assertUnprocessable();
            $this->assertSame($before, Role::count());
        }
    }

    public function test_failed_custom_role_update_leaves_original_data_unchanged(): void
    {
        Sanctum::actingAs($this->superAdmin);
        Role::create(['name' => 'Existing Custom']);
        $target = Role::create(['name' => 'Original Custom']);

        foreach ([' ', 'existing custom', 'SUPER_ADMIN', str_repeat('b', 256)] as $name) {
            $this->putJson("/api/roles/{$target->id}", ['name' => $name])
                ->assertUnprocessable();
            $this->assertDatabaseHas('roles', ['id' => $target->id, 'name' => 'Original Custom']);
        }
    }

    public function test_assigned_custom_role_cannot_be_deleted_and_related_data_remains(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $role = Role::create(['name' => 'assigned_custom']);
        $role->permissions()->attach($this->permissions['non_protected_permission']);
        $user = $this->createUser($role);

        $this->deleteJson("/api/roles/{$role->id}")->assertUnprocessable();

        $this->assertDatabaseHas('roles', ['id' => $role->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role_id' => $role->id]);
        $this->assertDatabaseHas('role_permission', [
            'role_id' => $role->id,
            'permission_id' => $this->permissions['non_protected_permission']->id,
        ]);
    }

    public function test_super_admin_can_create_and_update_non_protected_permissions(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $this->postJson('/api/permissions', [
            'name' => '  export_records  ',
            'description' => 'Export records',
        ])->assertCreated()->assertJsonPath('permission.name', 'export_records');

        $permission = Permission::where('name', 'export_records')->firstOrFail();
        $this->putJson("/api/permissions/{$permission->id}", [
            'name' => 'export_selected_records',
            'description' => 'Export selected records',
        ])->assertOk()->assertJsonPath('permission.name', 'export_selected_records');
    }

    public function test_non_super_admin_cannot_mutate_permissions_or_assignments_even_when_permissioned(): void
    {
        $role = Role::create(['name' => 'dynamic_admin']);
        $role->permissions()->attach($this->permissionIds([
            'create_permissions',
            'update_permissions',
            'assign_permissions',
        ]));
        Sanctum::actingAs($this->createUser($role));

        $target = $this->permissions['non_protected_permission'];
        $originalRoleIds = $target->roles()->pluck('roles.id')->all();

        $this->postJson('/api/permissions', [
            'name' => 'escalated_permission',
            'description' => 'Should not exist',
        ])->assertForbidden();
        $this->putJson("/api/permissions/{$target->id}", [
            'name' => 'renamed_permission',
            'description' => 'Should not change',
        ])->assertForbidden();
        $this->postJson("/api/roles/permissions/{$target->id}", [
            'permission_ids' => [$role->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('permissions', ['name' => 'escalated_permission']);
        $this->assertSame($originalRoleIds, $target->roles()->pluck('roles.id')->all());
    }

    public function test_permission_names_are_trimmed_bounded_and_case_insensitively_unique(): void
    {
        Sanctum::actingAs($this->superAdmin);

        foreach ([' ', 'VIEW_ROLES', str_repeat('p', 256)] as $name) {
            $before = Permission::count();
            $this->postJson('/api/permissions', [
                'name' => $name,
                'description' => 'Validation test',
            ])->assertUnprocessable();
            $this->assertSame($before, Permission::count());
        }
    }

    public function test_protected_permission_cannot_be_renamed_but_description_can_change(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $permission = $this->permissions['view_roles'];

        $this->putJson("/api/permissions/{$permission->id}", [
            'name' => 'renamed_view_roles',
            'description' => 'Changed description',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'view_roles',
            'description' => 'view_roles',
        ]);

        $this->putJson("/api/permissions/{$permission->id}", [
            'name' => 'view_roles',
            'description' => 'Updated description',
        ])->assertOk();
        $this->assertDatabaseHas('permissions', [
            'id' => $permission->id,
            'name' => 'view_roles',
            'description' => 'Updated description',
        ]);
    }

    public function test_assignment_route_uses_permission_id_and_syncs_unique_role_ids(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $permission = $this->permissions['non_protected_permission'];
        $customRole = Role::create(['name' => 'assignment_target']);

        $this->postJson("/api/roles/permissions/{$permission->id}", [
            'permission_ids' => [$customRole->id, $customRole->id],
        ])->assertOk();

        $this->assertSame([$customRole->id], $permission->roles()->pluck('roles.id')->all());
        $this->postJson('/api/roles/permissions/999999', [
            'permission_ids' => [$customRole->id],
        ])->assertNotFound();
    }

    public function test_invalid_role_assignment_leaves_existing_pivots_unchanged(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $permission = $this->permissions['non_protected_permission'];
        $permission->roles()->sync([$this->roles['doctor']->id]);

        $this->postJson("/api/roles/permissions/{$permission->id}", [
            'permission_ids' => [999999],
        ])->assertUnprocessable();

        $this->assertSame(
            [$this->roles['doctor']->id],
            $permission->roles()->pluck('roles.id')->all(),
        );
    }

    public function test_super_admin_cannot_be_removed_from_protected_permissions(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $additionalRole = Role::create(['name' => 'safe_assignment']);

        foreach (Permission::PROTECTED_ADMIN_PERMISSION_NAMES as $permissionName) {
            $permission = $this->permissions[$permissionName];
            $before = $permission->roles()->pluck('roles.id')->sort()->values()->all();

            $this->postJson("/api/roles/permissions/{$permission->id}", [
                'permission_ids' => [$additionalRole->id],
            ])->assertUnprocessable();

            $this->assertSame(
                $before,
                $permission->roles()->pluck('roles.id')->sort()->values()->all(),
            );

            $this->postJson("/api/roles/permissions/{$permission->id}", [
                'permission_ids' => [$this->roles['super_admin']->id, $additionalRole->id],
            ])->assertOk();
            $this->assertDatabaseHas('role_permission', [
                'role_id' => $this->roles['super_admin']->id,
                'permission_id' => $permission->id,
            ]);
        }
    }

    public function test_existing_read_permissions_continue_to_authorize_read_routes(): void
    {
        $role = Role::create(['name' => 'auditor']);
        $role->permissions()->attach($this->permissionIds(['view_roles', 'view_permissions']));
        Sanctum::actingAs($this->createUser($role));

        $this->getJson('/api/roles')->assertOk();
        $this->getJson('/api/permissions')->assertOk();
        $this->postJson('/api/roles', ['name' => 'forbidden_role'])->assertForbidden();
    }

    /** @param array<int, string> $names */
    private function permissionIds(array $names): array
    {
        return collect($names)->map(fn(string $name) => $this->permissions[$name]->id)->all();
    }

    private function createUser(Role $role): User
    {
        return User::create([
            'name' => 'Test ' . $role->name,
            'email' => uniqid($role->name . '-') . '@example.com',
            'password' => 'password123',
            'status' => 'working',
            'role_id' => $role->id,
        ]);
    }
}
