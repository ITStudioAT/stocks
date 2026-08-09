<?php

namespace Database\Seeders;

use App\Services\ProtectedAdminProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(ProtectedAdminProvisioner $protectedAdmins): void
    {
        $protectedAdmins->provision();
    }
}
