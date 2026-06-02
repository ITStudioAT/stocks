<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $admin = Role::findOrCreate('admin');
        $superAdmin = Role::findOrCreate('super_admin');

        $user = User::updateOrCreate(
            ['email' => 'kron@naturwelt.at'],
            [
                'last_name' => 'Kron',
                'first_name' => 'Günther',
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$admin, $superAdmin]);
    }
}
