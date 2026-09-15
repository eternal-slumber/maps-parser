<?php

namespace Database\Seeders;

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
        $user = User::query()
            ->where('email', 'review.parser.operator+local@demo.test')
            ->first()
            ?? User::query()->where('email', 'test@example.com')->first()
            ?? new User;

        $user->fill([
            'name' => 'Review Parser Operator',
            'email' => 'review.parser.operator+local@demo.test',
            'password' => 'Rvp!2026_Local#Access9',
        ])->save();
    }
}
