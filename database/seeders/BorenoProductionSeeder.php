<?php

namespace Database\Seeders;

use App\Enums\DefaultRoleEnum;
use RuntimeException;

/**
 * Seed a normal Boreno Hotel in production with the full sample data and separate staff logins.
 */
class BorenoProductionSeeder extends BorenoSeeder
{
    protected function staffEmail(DefaultRoleEnum $role): string
    {
        return str_replace('_', '', $role->value).'@boreno.com';
    }

    protected function staffPassword(): string
    {
        $password = config('seeding.boreno_password');

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set BORENO_SEED_PASSWORD to at least 8 characters before seeding Boreno Hotel.');
        }

        return $password;
    }
}
