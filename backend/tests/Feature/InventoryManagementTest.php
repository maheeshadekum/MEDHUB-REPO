<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Inventories;
use App\Models\InventoryBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private Permission $manageInventories;
    private Permission $viewInventories;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manageInventories = Permission::create([
            'name' => 'manage_inventories',
            'description' => 'Manage inventories',
        ]);
        $this->viewInventories = Permission::create([
            'name' => 'view_inventories',
            'description' => 'View inventories',
        ]);
    }

    public function test_super_admin_creates_inventory_for_selected_hospital_without_creating_a_batch(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $response = $this->postJson('/api/inventory', $this->inventoryPayload([
            'hospital_id' => $hospitalA->id,
        ]));

        $response->assertCreated()->assertJsonPath('inventory.hospital_id', $hospitalA->id);
        $this->assertDatabaseHas('inventories', ['hospital_id' => $hospitalA->id]);
        $this->assertDatabaseMissing('inventories', ['hospital_id' => $hospitalB->id]);
        $this->assertDatabaseCount('inventory_batch', 0);

        $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.available_quantity', 0);
    }

    public function test_super_admin_must_submit_a_valid_positive_existing_hospital(): void
    {
        Sanctum::actingAs($this->createUser('super_admin'));

        foreach ([[], ['hospital_id' => 0], ['hospital_id' => 999999]] as $hospitalData) {
            $this->postJson('/api/inventory', $this->inventoryPayload($hospitalData))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('hospital_id');
        }

        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_duplicate_identity_is_scoped_to_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/inventory', $this->inventoryPayload(['hospital_id' => $hospitalA->id]))
            ->assertCreated();
        $this->postJson('/api/inventory', $this->inventoryPayload(['hospital_id' => $hospitalA->id]))
            ->assertStatus(400);
        $this->postJson('/api/inventory', $this->inventoryPayload(['hospital_id' => $hospitalB->id]))
            ->assertCreated();

        $this->assertDatabaseCount('inventories', 2);
    }

    public function test_pharmacist_creation_uses_only_the_assigned_hospital(): void
    {
        $assignedHospital = $this->createHospital('Assigned Hospital');
        $otherHospital = $this->createHospital('Other Hospital');
        Sanctum::actingAs($this->createUser('pharmacist', $assignedHospital));

        $this->postJson('/api/inventory', $this->inventoryPayload())
            ->assertCreated()
            ->assertJsonPath('inventory.hospital_id', $assignedHospital->id);

        $this->postJson('/api/inventory', $this->inventoryPayload([
            'drug_name' => 'Ibuprofen',
            'hospital_id' => $otherHospital->id,
        ]))->assertForbidden();

        $this->assertDatabaseCount('inventories', 1);
        $this->assertDatabaseMissing('inventories', ['hospital_id' => $otherHospital->id]);
    }

    public function test_pharmacist_without_a_valid_hospital_is_forbidden(): void
    {
        Sanctum::actingAs($this->createUser('pharmacist'));

        $this->postJson('/api/inventory', $this->inventoryPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_unapproved_roles_are_forbidden_even_with_manage_permission(): void
    {
        $hospital = $this->createHospital();

        foreach (['hospital_admin', 'doctor', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->postJson('/api/inventory', $this->inventoryPayload([
                'hospital_id' => $hospital->id,
            ]))->assertForbidden();
        }

        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_inventory_validation_rejects_empty_drug_invalid_type_and_nonpositive_weight(): void
    {
        $hospital = $this->createHospital();
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/inventory', $this->inventoryPayload([
            'hospital_id' => $hospital->id,
            'drug_name' => '',
        ]))->assertUnprocessable()->assertJsonValidationErrors('drug_name');

        $this->postJson('/api/inventory', $this->inventoryPayload([
            'hospital_id' => $hospital->id,
            'type' => 'powder',
        ]))->assertUnprocessable()->assertJsonValidationErrors('type');

        foreach ([0, -1] as $weight) {
            $this->postJson('/api/inventory', $this->inventoryPayload([
                'hospital_id' => $hospital->id,
                'weight' => $weight,
            ]))->assertUnprocessable()->assertJsonValidationErrors('weight');
        }

        $this->assertDatabaseCount('inventories', 0);
    }

    public function test_super_admin_updates_details_but_cannot_change_hospital(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $inventory = $this->createInventory($hospitalA);
        $batch = $this->createBatch($inventory);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/inventory/{$inventory->id}", $this->inventoryPayload([
            'hospital_id' => $hospitalA->id,
            'drug_name' => 'Updated Panadol',
        ]))->assertOk();

        $this->putJson("/api/inventory/{$inventory->id}", $this->inventoryPayload([
            'hospital_id' => $hospitalB->id,
        ]))->assertForbidden();

        $this->assertDatabaseHas('inventories', [
            'id' => $inventory->id,
            'hospital_id' => $hospitalA->id,
            'drug_name' => 'Updated Panadol',
        ]);
        $this->assertDatabaseHas('inventory_batch', [
            'id' => $batch->id,
            'inventory_id' => $inventory->id,
            'quantity' => 20,
        ]);
    }

    public function test_pharmacist_updates_only_own_hospital_and_cannot_change_ownership(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $ownInventory = $this->createInventory($hospitalA);
        $otherInventory = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        Sanctum::actingAs($this->createUser('pharmacist', $hospitalA));

        $this->putJson("/api/inventory/{$ownInventory->id}", $this->inventoryPayload([
            'hospital_id' => $hospitalA->id,
            'drug_name' => 'Updated Panadol',
        ]))->assertOk();

        $this->putJson("/api/inventory/{$ownInventory->id}", $this->inventoryPayload([
            'hospital_id' => $hospitalB->id,
        ]))->assertForbidden();

        $this->putJson("/api/inventory/{$otherInventory->id}", $this->inventoryPayload([
            'hospital_id' => $hospitalB->id,
        ]))->assertForbidden();

        $this->assertDatabaseHas('inventories', [
            'id' => $ownInventory->id,
            'hospital_id' => $hospitalA->id,
            'drug_name' => 'Updated Panadol',
        ]);
        $this->assertDatabaseHas('inventories', [
            'id' => $otherInventory->id,
            'hospital_id' => $hospitalB->id,
            'drug_name' => 'Ibuprofen',
        ]);
    }

    public function test_delete_is_limited_to_super_admin_or_own_hospital_pharmacist(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $ownInventory = $this->createInventory($hospitalA);
        $otherInventory = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        $otherBatch = $this->createBatch($otherInventory);

        Sanctum::actingAs($this->createUser('pharmacist', $hospitalA));
        $this->deleteJson("/api/inventory/{$otherInventory->id}")->assertForbidden();
        $this->deleteJson("/api/inventory/{$ownInventory->id}")->assertOk();

        $this->assertDatabaseMissing('inventories', ['id' => $ownInventory->id]);
        $this->assertDatabaseHas('inventories', ['id' => $otherInventory->id]);
        $this->assertDatabaseHas('inventory_batch', ['id' => $otherBatch->id]);

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->deleteJson("/api/inventory/{$otherInventory->id}")->assertOk();
        $this->assertDatabaseMissing('inventories', ['id' => $otherInventory->id]);
        $this->assertDatabaseMissing('inventory_batch', ['id' => $otherBatch->id]);
    }

    public function test_unapproved_roles_cannot_update_or_delete(): void
    {
        $hospital = $this->createHospital();

        foreach (['hospital_admin', 'doctor', 'receptionist', 'patient'] as $role) {
            $inventory = $this->createInventory($hospital, ['drug_name' => 'Drug ' . $role]);
            Sanctum::actingAs($this->createUser($role, $hospital));

            $this->putJson("/api/inventory/{$inventory->id}", $this->inventoryPayload([
                'hospital_id' => $hospital->id,
            ]))->assertForbidden();
            $this->deleteJson("/api/inventory/{$inventory->id}")->assertForbidden();

            $this->assertDatabaseHas('inventories', ['id' => $inventory->id]);
        }
    }

    public function test_super_admin_adds_batch_to_selected_inventory_and_sees_updated_quantity_and_batch(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $inventoryA = $this->createInventory($hospitalA);
        $inventoryB = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        $superAdmin = $this->createUser('super_admin');
        Sanctum::actingAs($superAdmin);

        $this->assertNull($superAdmin->hospital_id);

        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryA, [
            'batch_number' => '001',
            'quantity' => 200,
        ]))
            ->assertCreated()
            ->assertJsonPath('batch.inventory_id', $inventoryA->id);

        $this->assertDatabaseHas('inventory_batch', [
            'inventory_id' => $inventoryA->id,
            'batch_number' => '001',
            'quantity' => 200,
        ]);
        $this->assertDatabaseMissing('inventory_batch', ['inventory_id' => $inventoryB->id]);
        $this->assertDatabaseCount('inventories', 2);
        $this->assertDatabaseHas('inventories', [
            'id' => $inventoryA->id,
            'hospital_id' => $hospitalA->id,
        ]);

        $this->getJson('/api/inventory')
            ->assertOk()
            ->assertJsonFragment(['available_quantity' => 200])
            ->assertJsonFragment(['batch_number' => '001']);
    }

    public function test_add_batch_rejects_invalid_inventory_expiry_and_quantity_without_writing(): void
    {
        $inventory = $this->createInventory($this->createHospital());
        Sanctum::actingAs($this->createUser('super_admin'));

        $invalidRequests = [
            [array_diff_key($this->batchPayload($inventory), ['inventory_id' => true]), 422, 'inventory_id'],
            [$this->batchPayload($inventory, ['inventory_id' => 0]), 422, 'inventory_id'],
            [$this->batchPayload($inventory, ['inventory_id' => 999999]), 404, null],
            [$this->batchPayload($inventory, ['expiry_date' => now()->subDay()->toDateString()]), 422, 'expiry_date'],
            [$this->batchPayload($inventory, ['expiry_date' => now()->toDateString()]), 422, 'expiry_date'],
            [$this->batchPayload($inventory, ['quantity' => 0]), 422, 'quantity'],
            [$this->batchPayload($inventory, ['quantity' => -1]), 422, 'quantity'],
            [$this->batchPayload($inventory, ['quantity' => 1.5]), 422, 'quantity'],
        ];

        foreach ($invalidRequests as [$payload, $status, $field]) {
            $response = $this->postJson('/api/inventory/batch', $payload)->assertStatus($status);
            if ($field) {
                $response->assertJsonValidationErrors($field);
            }
        }

        $this->assertDatabaseCount('inventory_batch', 0);
    }

    public function test_duplicate_batch_numbers_are_scoped_to_inventory(): void
    {
        $hospital = $this->createHospital();
        $inventoryA = $this->createInventory($hospital);
        $inventoryB = $this->createInventory($hospital, ['drug_name' => 'Ibuprofen']);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryA, ['batch_number' => '001']))
            ->assertCreated();
        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryA, ['batch_number' => '001']))
            ->assertStatus(400);
        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryB, ['batch_number' => '001']))
            ->assertCreated();

        $this->assertDatabaseCount('inventory_batch', 2);
    }

    public function test_pharmacist_adds_batch_only_to_own_hospital_inventory(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $inventoryA = $this->createInventory($hospitalA);
        $inventoryB = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        Sanctum::actingAs($this->createUser('pharmacist', $hospitalA));

        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryA))
            ->assertCreated();
        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryB))
            ->assertForbidden();

        $this->assertDatabaseHas('inventory_batch', ['inventory_id' => $inventoryA->id]);
        $this->assertDatabaseMissing('inventory_batch', ['inventory_id' => $inventoryB->id]);

        Sanctum::actingAs($this->createUser('pharmacist'));
        $this->postJson('/api/inventory/batch', $this->batchPayload($inventoryA, [
            'batch_number' => 'NO-HOSPITAL',
        ]))->assertForbidden();
    }

    public function test_unapproved_roles_cannot_add_batches_even_with_manage_permission(): void
    {
        $hospital = $this->createHospital();
        $inventory = $this->createInventory($hospital);

        foreach (['hospital_admin', 'doctor', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospital));
            $this->postJson('/api/inventory/batch', $this->batchPayload($inventory, [
                'batch_number' => 'BATCH-' . $role,
            ]))->assertForbidden();
        }

        $this->assertDatabaseCount('inventory_batch', 0);
    }

    public function test_super_admin_updates_batch_without_changing_inventory_ownership(): void
    {
        $hospital = $this->createHospital();
        $otherInventory = $this->createInventory($hospital, ['drug_name' => 'Ibuprofen']);
        $inventory = $this->createInventory($hospital);
        $batch = $this->createBatch($inventory);
        Sanctum::actingAs($this->createUser('super_admin'));

        $this->putJson("/api/inventory/batch/{$batch->id}", $this->batchPayload($inventory, [
            'batch_number' => 'UPDATED-001',
            'quantity' => 30,
        ]))->assertOk();

        $this->putJson("/api/inventory/batch/{$batch->id}", $this->batchPayload($otherInventory))
            ->assertForbidden();

        foreach ([
            ['expiry_date' => now()->subDay()->toDateString()],
            ['quantity' => 0],
            ['quantity' => 1.5],
        ] as $invalidData) {
            $this->putJson(
                "/api/inventory/batch/{$batch->id}",
                $this->batchPayload($inventory, $invalidData)
            )->assertUnprocessable();
        }

        $this->assertDatabaseHas('inventory_batch', [
            'id' => $batch->id,
            'inventory_id' => $inventory->id,
            'batch_number' => 'UPDATED-001',
            'quantity' => 30,
        ]);
    }

    public function test_pharmacist_updates_only_own_hospital_batch(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $inventoryA = $this->createInventory($hospitalA);
        $inventoryB = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        $batchA = $this->createBatch($inventoryA);
        $batchB = $this->createBatch($inventoryB);
        Sanctum::actingAs($this->createUser('pharmacist', $hospitalA));

        $this->putJson("/api/inventory/batch/{$batchA->id}", $this->batchPayload($inventoryA, [
            'batch_number' => 'UPDATED-OWN',
        ]))->assertOk();
        $this->putJson("/api/inventory/batch/{$batchB->id}", $this->batchPayload($inventoryB))
            ->assertForbidden();
        $this->putJson("/api/inventory/batch/{$batchA->id}", $this->batchPayload($inventoryB))
            ->assertForbidden();

        $this->assertDatabaseHas('inventory_batch', [
            'id' => $batchA->id,
            'inventory_id' => $inventoryA->id,
            'batch_number' => 'UPDATED-OWN',
        ]);
        $this->assertDatabaseHas('inventory_batch', [
            'id' => $batchB->id,
            'inventory_id' => $inventoryB->id,
        ]);
    }

    public function test_batch_delete_authorization_preserves_forbidden_batches(): void
    {
        $hospitalA = $this->createHospital('Hospital A');
        $hospitalB = $this->createHospital('Hospital B');
        $inventoryA = $this->createInventory($hospitalA);
        $inventoryB = $this->createInventory($hospitalB, ['drug_name' => 'Ibuprofen']);
        $ownBatch = $this->createBatch($inventoryA);
        $otherBatch = $this->createBatch($inventoryB);

        Sanctum::actingAs($this->createUser('pharmacist', $hospitalA));
        $this->deleteJson("/api/inventory/batch/{$otherBatch->id}")->assertForbidden();
        $this->deleteJson("/api/inventory/batch/{$ownBatch->id}")->assertOk();
        $this->assertDatabaseHas('inventory_batch', ['id' => $otherBatch->id]);

        foreach (['hospital_admin', 'doctor', 'receptionist', 'patient'] as $role) {
            Sanctum::actingAs($this->createUser($role, $hospitalB));
            $this->deleteJson("/api/inventory/batch/{$otherBatch->id}")->assertForbidden();
        }

        Sanctum::actingAs($this->createUser('super_admin'));
        $this->deleteJson("/api/inventory/batch/{$otherBatch->id}")->assertOk();
        $this->assertDatabaseMissing('inventory_batch', ['id' => $otherBatch->id]);
    }

    private function createUser(string $roleName, ?Hospital $hospital = null): User
    {
        $role = Role::firstOrCreate(['name' => $roleName]);
        $role->permissions()->syncWithoutDetaching([
            $this->manageInventories->id,
            $this->viewInventories->id,
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
            'is_inventory_activated' => true,
            'is_appointment_activated' => false,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function inventoryPayload(array $overrides = []): array
    {
        return array_merge([
            'drug_name' => 'Panadol',
            'brand_name' => 'SPC',
            'type' => 'tablet',
            'weight' => 100,
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function createInventory(Hospital $hospital, array $overrides = []): Inventories
    {
        return Inventories::create($this->inventoryPayload(array_merge([
            'hospital_id' => $hospital->id,
        ], $overrides)));
    }

    private function createBatch(Inventories $inventory): InventoryBatch
    {
        return InventoryBatch::create([
            'inventory_id' => $inventory->id,
            'batch_number' => 'BATCH-' . uniqid(),
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => 20,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function batchPayload(Inventories $inventory, array $overrides = []): array
    {
        return array_merge([
            'inventory_id' => $inventory->id,
            'batch_number' => 'BATCH-' . uniqid(),
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity' => 20,
        ], $overrides);
    }
}
