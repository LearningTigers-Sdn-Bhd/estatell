<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use App\Services\Landing\ServicePages;
use Illuminate\Http\Response;

/**
 * What crawlers read first: robots.txt, the sitemap and llms.txt for AI tools. All three are built from APP_URL and
 * ServicePages, so a new service or a new domain needs no edits here.
 */
class SearchEngineController extends Controller
{
    private const CACHE = 'public, max-age=3600';

    /**
     * Only production is open to crawlers. The demo and any staging copy must never be indexed.
     */
    public function robots(): Response
    {
        $lines = app()->isProduction()
            ? [
                'User-agent: *',
                'Allow: /$',
                'Allow: /services/',
                'Allow: /terms-and-conditions',
                'Allow: /privacy-policy',
                'Allow: /sitemap.xml',
                'Allow: /llms.txt',
                'Disallow: /',
                '',
                'Sitemap: '.ServicePages::url(route('sitemap', absolute: false)),
            ]
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::CACHE]);
    }

    public function sitemap(): Response
    {
        $paths = [
            route('home', absolute: false),
            ...array_map(fn (string $slug): string => $this->servicePath($slug), ServicePages::slugs()),
            route('terms', absolute: false),
            route('privacy', absolute: false),
        ];

        $urls = implode('', array_map(fn (string $path): string => '<url><loc>'.e(ServicePages::url($path)).'</loc></url>', $paths));

        return response(
            '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$urls.'</urlset>',
            headers: ['Content-Type' => 'application/xml; charset=UTF-8', 'Cache-Control' => self::CACHE],
        );
    }

    public function llms(): Response
    {
        $services = array_map(
            fn (array $service): string => "- [{$service['name']}](".ServicePages::url($this->servicePath($service['slug']))."): {$service['description']}",
            ServicePages::all(),
        );

        $lines = [
            '# Estatell',
            '',
            '> PMS, Channel Manager and Booking Engine in one system. AI, automation and self-service layered on top to run your hotel end to end. Built for independent and boutique hotels.',
            '',
            '## Services',
            '',
            ...$services,
            '',
            '## Company',
            '',
            '- [Home]('.ServicePages::url('/').'): What Estatell is and how the services fit together.',
            '- Contact: hello@estatell.com',
        ];

        return response(
            implode("\n", $lines)."\n",
            headers: ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => self::CACHE],
        );
    }

    private function servicePath(string $slug): string
    {
        return route('services.show', $slug, absolute: false);
    }
}
