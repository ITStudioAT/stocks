<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Company;
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

        $company = Company::updateOrCreate(
            ['company_name_1' => 'ITStudio.at'],
            [
                'company_name_2' => 'by Dipl.-Ing. Gütnher Kron',
                'street' => 'Salzburger Straße 87b',
                'postal_code' => '5110',
                'city' => 'Oberndorf',
                'country' => 'Österreich',
            ],
        );

        $user = User::updateOrCreate(
            ['email' => 'kron@naturwelt.at'],
            [
                'company_id' => $company->id,
                'last_name' => 'Kron',
                'first_name' => 'Günther',
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        $user->syncRoles([$admin, $superAdmin]);

        Client::updateOrCreate(
            ['signature' => 'naturwelt'],
            [
                'company_id' => $company->id,
                'name' => 'Naturwelt',
                'headline' => 'Naturwelt brings quiet outdoor experiences online.',
                'subheadline' => 'A first client homepage powered by Stocks.',
                'body' => 'This public page is served from the client signature URL and can later be edited from the admin dashboard.',
                'is_published' => true,
            ],
        );

        Client::updateOrCreate(
            ['signature' => 'studio-demo'],
            [
                'company_id' => $company->id,
                'name' => 'Studio Demo',
                'headline' => 'A focused web presence for a modern studio.',
                'subheadline' => 'Each client receives a dedicated homepage URL.',
                'body' => 'Stocks keeps multiple client pages under one shared admin login while publishing each page separately.',
                'is_published' => true,
            ],
        );
    }
}
