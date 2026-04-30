<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $family = Family::create([
            'name' => 'Household',
            'description' => 'Primary family for the app admin.',
        ]);

        User::factory()->create([
            'name' => 'App Admin',
            'email' => 'admin@example.com',
            'family_id' => $family->id,
            'role' => 'admin',
        ]);
    }
}
