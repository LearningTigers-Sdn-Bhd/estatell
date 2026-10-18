<?php

namespace App\Data\Platform;

use Spatie\LaravelData\Data;

/**
 * The partner **Demo access** card: the demo link, the Owner login of each demo property, and the shared password.
 * See docs/01-platform/06-demo-properties.md#logins.
 */
class DemoAccessData extends Data
{
    /**
     * @param  list<DemoLoginData>  $logins
     */
    public function __construct(
        public string $url,
        public array $logins,
        public string $password,
    ) {}

    /**
     * Read `estatell.demo`. Null when `DEMO_URL` or `DEMO_PASSWORD` is not set, so the card stays hidden.
     */
    public static function fromConfig(): ?self
    {
        $url = config('estatell.demo.url');
        $password = config('estatell.demo.password');

        if (! is_string($url) || $url === '' || ! is_string($password) || $password === '') {
            return null;
        }

        /** @var list<array{property: string, email: string}> $logins */
        $logins = config('estatell.demo.logins', []);

        return new self(
            url: $url,
            logins: array_map(fn (array $login): DemoLoginData => new DemoLoginData($login['property'], $login['email']), $logins),
            password: $password,
        );
    }
}
