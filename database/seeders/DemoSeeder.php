<?php

namespace Database\Seeders;

use App\Enums\DefaultRoleEnum;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the demo environment: Pearlside View Resort and Boreno Hotel. It runs at the nightly reset
 * (`php artisan migrate:fresh --force --seeder=DemoSeeder`) and is never called from `DatabaseSeeder`, so it can't
 * run in production. See docs/deployment/demo_environment.md.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        self::password();

        $this->call([OtaChannelSeeder::class, PearlsideSeeder::class, BorenoSeeder::class]);
    }

    /**
     * `DEMO_PASSWORD`, shared by every demo staff account. Refuses a missing or short value.
     */
    public static function password(): string
    {
        $password = config('seeding.demo_password');

        if (! is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set DEMO_PASSWORD (at least 8 characters) before seeding the demo.');
        }

        return $password;
    }

    /**
     * The Owner signs in as `{property}@demo.estatell.com`, other staff as `{property}.{role}@demo.estatell.com`.
     */
    public static function loginEmail(string $property, DefaultRoleEnum $role): string
    {
        $local = $role === DefaultRoleEnum::Owner ? $property : $property.'.'.str_replace('_', '', $role->value);

        return $local.'@demo.estatell.com';
    }
}
