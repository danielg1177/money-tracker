<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $family = Family::firstOrCreate([
            'name' => 'Household',
        ], [
            'description' => 'Primary family for the app admin.',
        ]);

        User::updateOrCreate([
            'email' => 'admin@example.com',
        ], [
            'name' => 'App Admin',
            'password' => Hash::make('password'),
            'family_id' => $family->id,
            'role' => 'head_of_household',
            'is_admin' => true,
        ]);
    }
}
