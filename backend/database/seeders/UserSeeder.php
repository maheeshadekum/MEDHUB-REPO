<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
class UserSeeder extends Seeder
{
    public function run()
    {
        // Sample users for each role
        $users = [
            [
                'name' => 'Simple LinkX Super Admin',
                'email' => 'superadmin@simplinkx.com',
                'password' => bcrypt('abcd1234'),
                'role_id' => 1, // Assuming 1 is the ID for Super Admin
            ],
        ];

        // Insert users into the database
        foreach ($users as $user) {
            User::create($user);
        }
    }
}
