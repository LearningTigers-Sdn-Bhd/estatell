<?php

use App\Services\Landing\ServicePages;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.url' => 'https://estatell.example']);
});

test('every service page is public, indexable and renders its slug', function () {
    $this->withoutVite();

    expect(ServicePages::slugs())->not->toBeEmpty();

    foreach (ServicePages::slugs() as $slug) {
        $this->get(route('services.show', $slug))
            ->assertOk()
            ->assertDontSee('noindex', false)
            ->assertInertia(fn (Assert $page) => $page->component('services/show')->where('slug', $slug));
    }
});

test('an unknown service slug is a 404', function () {
    $this->withoutVite();

    $this->get('/services/does-not-exist')->assertNotFound();
});

test('sitemap.xml lists the home page, every service page and the legal pages', function () {
    $response = $this->get('/sitemap.xml')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    foreach (['/', ...array_map(fn (string $slug) => "/services/{$slug}", ServicePages::slugs()), '/terms-and-conditions', '/privacy-policy'] as $path) {
        $response->assertSee("<loc>https://estatell.example{$path}</loc>", false);
    }
});

test('llms.txt names every service with its page URL', function () {
    $response = $this->get('/llms.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');
    $response->assertSee('# Estatell');

    foreach (ServicePages::all() as $service) {
        $response->assertSee("[{$service['name']}](https://estatell.example/services/{$service['slug']})", false);
    }
});

test('absolute URLs come from APP_URL, never from the request host', function () {
    $this->withoutVite();

    $this->withHeader('Host', 'evil.test')->get('/sitemap.xml')->assertDontSee('evil.test')->assertSee('https://estatell.example');
    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('siteUrl', 'https://estatell.example'));
});

test('in production robots.txt lets crawlers reach the public pages and points at the sitemap', function () {
    $this->app['env'] = 'production';

    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Allow: /services/')
        ->assertSee('Allow: /sitemap.xml')
        ->assertSee('Allow: /llms.txt')
        ->assertSee('Sitemap: https://estatell.example/sitemap.xml');
});

test('outside production robots.txt blocks every crawler', function () {
    $this->get('/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /')
        ->assertDontSee('Allow:')
        ->assertDontSee('Sitemap:');
});
