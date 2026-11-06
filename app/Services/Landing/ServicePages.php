<?php

namespace App\Services\Landing;

/**
 * The public /services/* pages. Their copy lives in one JSON file that the React pages import too
 * (resources/js/components/landing/services.json), so the routes, sitemap.xml and llms.txt can never drift from the pages.
 * Add a service by adding an entry to that file.
 */
final class ServicePages
{
    private const SOURCE = 'js/components/landing/services.json';

    /**
     * @return list<array{slug: string, name: string, description: string, blurb: string}>
     */
    public static function all(): array
    {
        /** @var list<array{slug: string, name: string, description: string, blurb: string}> */
        return json_decode((string) file_get_contents(resource_path(self::SOURCE)), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::all(), 'slug');
    }

    /**
     * Absolute URL of a public page. Built from APP_URL, never the request host, so a spoofed Host header cannot change it.
     */
    public static function url(string $path = ''): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
