<?php

namespace Database\Seeders;

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
        User::updateOrCreate(
            ['email' => 'faculty@example.com'],
            [
                'name' => 'Dr. John Doe',
                'department' => 'Computer Science',
                'designation' => 'Lecturer',
                'password' => Hash::make('password123'),
            ]
        );
    }
}
