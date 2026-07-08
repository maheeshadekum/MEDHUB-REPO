<?php

namespace Database\Seeders;

use App\Models\RolePermission;
use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Clear existing permissions and role_permissions
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        DB::table('role_permission')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $permissions = [
            // view_hospitals
            ['role_id' => 1, 'permission_id' => 1],
            ['role_id' => 2, 'permission_id' => 1],
            ['role_id' => 3, 'permission_id' => 1],
            ['role_id' => 4, 'permission_id' => 1],
            ['role_id' => 5, 'permission_id' => 1],
            ['role_id' => 6, 'permission_id' => 1],
            // create_hospitals
            ['role_id' => 1, 'permission_id' => 2],
            // update_hospitals
            ['role_id' => 1, 'permission_id' => 3],
            // manage_hospitals
            ['role_id' => 2, 'permission_id' => 4],
            // view_users
            ['role_id' => 1, 'permission_id' => 5],
            ['role_id' => 2, 'permission_id' => 5],
            // create_users
            ['role_id' => 1, 'permission_id' => 6],
            // update_users
            ['role_id' => 1, 'permission_id' => 7],
            ['role_id' => 2, 'permission_id' => 7],
            // view_roles
            ['role_id' => 1, 'permission_id' => 8],
            ['role_id' => 2, 'permission_id' => 8],
            // create_roles
            ['role_id' => 1, 'permission_id' => 9],
            // update_roles
            ['role_id' => 1, 'permission_id' => 10],
            // view_permissions
            ['role_id' => 1, 'permission_id' => 11],
            // create_permissions
            ['role_id' => 1, 'permission_id' => 12],
            // update_permissions
            ['role_id' => 1, 'permission_id' => 13],
            // view_role_permissions
            ['role_id' => 1, 'permission_id' => 14],
            // assign_permissions
            ['role_id' => 1, 'permission_id' => 15],
            // view_patients
            ['role_id' => 1, 'permission_id' => 16],
            ['role_id' => 2, 'permission_id' => 16],
            ['role_id' => 3, 'permission_id' => 16],
            ['role_id' => 4, 'permission_id' => 16],
            ['role_id' => 5, 'permission_id' => 16],
            // manage_patients
            ['role_id' => 1, 'permission_id' => 17],
            ['role_id' => 2, 'permission_id' => 17],
            ['role_id' => 5, 'permission_id' => 17],
            // update_users_hospital
            ['role_id' => 1, 'permission_id' => 18],
            // view_prescriptions
            ['role_id' => 1, 'permission_id' => 19],
            ['role_id' => 2, 'permission_id' => 19],
            ['role_id' => 3, 'permission_id' => 19],
            ['role_id' => 4, 'permission_id' => 19],
            ['role_id' => 5, 'permission_id' => 19],
            ['role_id' => 6, 'permission_id' => 19],
            // create_prescriptions
            ['role_id' => 5, 'permission_id' => 20],
            // update_prescriptions
            ['role_id' => 5, 'permission_id' => 21],
            // delete_prescriptions
            ['role_id' => 5, 'permission_id' => 22],
            // add_medicines
            ['role_id' => 3, 'permission_id' => 23],
            // release_medicines
            ['role_id' => 4, 'permission_id' => 24],
            // add_reports
            ['role_id' => 3, 'permission_id' => 25],
            // view_inventories
            ['role_id' => 1, 'permission_id' => 26],
            ['role_id' => 2, 'permission_id' => 26],
            ['role_id' => 3, 'permission_id' => 26],
            ['role_id' => 4, 'permission_id' => 26],
            ['role_id' => 5, 'permission_id' => 26],
            ['role_id' => 6, 'permission_id' => 26],
            // manage_inventories
            ['role_id' => 4, 'permission_id' => 27],
            // view_appointments
            ['role_id' => 1, 'permission_id' => 28],
            ['role_id' => 2, 'permission_id' => 28],
            ['role_id' => 3, 'permission_id' => 28],
            ['role_id' => 5, 'permission_id' => 28],
            ['role_id' => 6, 'permission_id' => 28],
            // manage_appointments
            ['role_id' => 1, 'permission_id' => 29],
            ['role_id' => 2, 'permission_id' => 29],
            ['role_id' => 5, 'permission_id' => 29],
            // view_clinic_patients
            ['role_id' => 1, 'permission_id' => 30],
            ['role_id' => 2, 'permission_id' => 30],
            ['role_id' => 3, 'permission_id' => 30],
            ['role_id' => 4, 'permission_id' => 30],
            ['role_id' => 5, 'permission_id' => 30],
            ['role_id' => 6, 'permission_id' => 30],
            // manage_clinic_patients
            ['role_id' => 2, 'permission_id' => 31],
            ['role_id' => 5, 'permission_id' => 31],
            // view_clinic
            ['role_id' => 1, 'permission_id' => 32],
            ['role_id' => 2, 'permission_id' => 32],
            ['role_id' => 3, 'permission_id' => 32],
            ['role_id' => 4, 'permission_id' => 32],
            ['role_id' => 5, 'permission_id' => 32],
            ['role_id' => 6, 'permission_id' => 32],
            // manage_clinic
            ['role_id' => 2, 'permission_id' => 33],
            // manage_pharmacy
            ['role_id' => 1, 'permission_id' => 34],
        ];

        $superAdminPermissions = Permission::pluck('id')
            ->map(fn($permissionId) => ['role_id' => 1, 'permission_id' => $permissionId])
            ->all();

        $permissions = array_values(array_filter(
            $permissions,
            fn($permission) => $permission['role_id'] !== 1
        ));
        $permissions = array_merge($superAdminPermissions, $permissions);

        foreach ($permissions as $permission) {
            RolePermission::create($permission);
        }
    }
}
