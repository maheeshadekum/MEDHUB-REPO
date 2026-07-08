<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionSeeder extends Seeder
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
        DB::table('permissions')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        $permissions = [
            ['name' => 'view_hospitals', 'description' => 'View hospitals'],
            ['name' => 'create_hospitals', 'description' => 'Create hospitals'],
            ['name' => 'update_hospitals', 'description' => 'Update hospitals'],
            ['name' => 'manage_hospitals', 'description' => 'Manage hospitals'],
            ['name' => 'view_users', 'description' => 'View users'],
            ['name' => 'create_users', 'description' => 'Create users'],
            ['name' => 'update_users', 'description' => 'Update users'],
            ['name' => 'view_roles', 'description' => 'View roles'],
            ['name' => 'create_roles', 'description' => 'Create roles'],
            ['name' => 'update_roles', 'description' => 'Update roles'],
            ['name' => 'view_permissions', 'description' => 'View permissions'],
            ['name' => 'create_permissions', 'description' => 'Create permissions'],
            ['name' => 'update_permissions', 'description' => 'Update permissions'],
            ['name' => 'view_role_permissions', 'description' => 'View role permissions'],
            ['name' => 'assign_permissions', 'description' => 'Assign permissions to roles'],
            ['name' => 'view_patients', 'description' => 'View patients'],
            ['name' => 'manage_patients', 'description' => 'Manage patients'],
            ['name' => 'update_users_hospital', 'description' => 'Update user hospital assignment'],
            ['name' => 'view_prescriptions', 'description' => 'View prescriptions'],
            ['name' => 'create_prescriptions', 'description' => 'Create prescriptions'],
            ['name' => 'update_prescriptions', 'description' => 'Update prescriptions'],
            ['name' => 'delete_prescriptions', 'description' => 'Delete prescriptions'],
            ['name' => 'add_medicines', 'description' => 'Add medicines'],
            ['name' => 'release_medicines', 'description' => 'Release medicines from inventory'],
            ['name' => 'add_reports', 'description' => 'Add reports'],
            ['name' => 'view_inventories', 'description' => 'View inventories'],
            ['name' => 'manage_inventories', 'description' => 'Manage inventories'],
            ['name' => 'view_appointments', 'description' => 'View appointments'],
            ['name' => 'manage_appointments', 'description' => 'Manage appointments'],
            ['name' => 'view_clinic_patients', 'description' => 'View clinic patients'],
            ['name' => 'manage_clinic_patients', 'description' => 'Manage clinic patients'],
            ['name' => 'view_clinic' , 'description' => 'View clinic details'],
            ['name' => 'manage_clinic', 'description' => 'Manage clinic'],
            ['name' => 'manage_pharmacy', 'description' => 'Manage pharmacy'],
        ];

        foreach ($permissions as $permission) {
            Permission::create($permission);
        }
    }
}
