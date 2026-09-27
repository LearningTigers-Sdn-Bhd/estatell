<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    /**
     * Create the first platform admin. Skipped when ADMIN_SEED_EMAIL or ADMIN_SEED_PASSWORD is not set,
     * and when an admin with that email already exists.
     */
    public function run(): void
    {
        $email = config('seeding.admin_email');
        $password = config('seeding.admin_password');

        if (blank($email) || blank($password)) {
            $this->command->warn('Skipping platform admin: ADMIN_SEED_EMAIL or ADMIN_SEED_PASSWORD is not set.');

            return;
        }

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Set a valid ADMIN_SEED_EMAIL before seeding the platform admin.');
        }

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set ADMIN_SEED_PASSWORD to at least 8 characters before seeding the platform admin.');
        }

        if (Admin::query()->where('email', $email)->exists()) {
            return;
        }

        Admin::query()->create([
            'name' => 'Admin',
            'email' => $email,
            'password' => $password,
        ]);
    }
}
