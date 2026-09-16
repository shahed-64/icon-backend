<?php

namespace Database\Seeders;

use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Staff::create([
            'name' => 'MOSHARRAF HOSSAIN',
            'user_name' => 'manager',
            'skill' => 'Administration',
            'role' => 'Manager',
            'email' => 'icon@academy.com',
            'password' => Hash::make('Icon@Academy!6475#'),
            'image' => null,
            'salary' => 000
        ]);



        // Grading System Seeder
        $this->call([
            GradingSystemSeeder::class,
        ]);
    }
}
