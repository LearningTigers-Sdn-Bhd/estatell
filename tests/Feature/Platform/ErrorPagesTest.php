<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['app.debug' => false, 'inertia.ssr.enabled' => false]);
    $this->withoutVite();
    $this->withHeader('X-Inertia-Version', app(HandleInertiaRequests::class)->version(Request::create('/')) ?? '');
});

function registerErrorPageRoute(int $status, string $path = '/_test/error'): void
{
    Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], $path, fn () => abort($status, 'Private exception details.'))
        ->middleware('web');
}

class ErrorPageEarlyMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        throw new HttpException(503, 'Private maintenance details.', headers: [
            'Retry-After' => '60',
            'Refresh' => '15',
        ]);
    }
}

test('browser errors render the standalone page with their original status', function (int $status) {
    registerErrorPageRoute($status);

    $this->get('/_test/error')->assertStatus($status)
        ->assertHeader('Vary', 'X-Inertia')
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors')
            ->where('status', $status)
            ->where('recoveryUrl', $status === 503 ? url('/_test/error') : '/dashboard')
            ->missing('auth')
            ->missing('property')
            ->missing('navigation')
            ->missing('businessDate')
        );
})->with([403, 404, 419, 500, 503]);

test('local error previews render every supported status without shared domain props', function (int $status) {
    $this->app->instance('env', 'local');

    $this->get("/_preview/errors/{$status}")->assertStatus($status)
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors')
            ->where('status', $status)
            ->where('recoveryUrl', $status === 503 ? url('/_preview/errors/503') : '/dashboard')
            ->missing('auth')
            ->missing('property')
            ->missing('navigation')
        );
})->with([403, 404, 419, 500, 503]);

test('error previews are unavailable outside local development', function (int $status) {
    $this->app->instance('env', 'production');

    $this->get("/_preview/errors/{$status}")->assertNotFound();
})->with([403, 404, 419, 500, 503]);

test('local error previews reject unsupported statuses', function () {
    $this->app->instance('env', 'local');

    $this->get('/_preview/errors/418')->assertNotFound();
});

test('inertia visits receive an error page rather than an html error modal', function (int $status) {
    registerErrorPageRoute($status);

    $this->get('/_test/error', ['X-Inertia' => 'true'])->assertStatus($status)
        ->assertHeader('X-Inertia', 'true')
        ->assertHeader('Vary', 'X-Inertia')
        ->assertJsonPath('component', 'errors')
        ->assertJsonPath('props.status', $status)
        ->assertJsonMissingPaths(['props.auth', 'props.exception', 'props.message']);
})->with([403, 404, 419, 500, 503]);

test('an unmatched url renders the branded 404 without web middleware', function () {
    $this->get('/_test/does-not-exist')->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page
            ->component('errors')
            ->where('status', 404)
            ->where('recoveryUrl', '/dashboard')
        );
});

test('early maintenance renders the page and preserves retry headers', function (array $headers) {
    app(Kernel::class)->prependMiddleware(ErrorPageEarlyMaintenance::class);

    $response = $this->get('/_test/maintenance', $headers)->assertServiceUnavailable()
        ->assertHeader('Retry-After', '60')
        ->assertHeader('Refresh', '15')
        ->assertHeader('Vary', 'X-Inertia');

    if (isset($headers['X-Inertia'])) {
        $response->assertJsonPath('component', 'errors')->assertJsonPath('props.status', 503);
    } else {
        $response->assertInertia(fn (Assert $page) => $page
            ->component('errors')
            ->where('status', 503)
            ->where('recoveryUrl', url('/_test/maintenance'))
        );
    }
})->with(['browser' => [[]], 'inertia' => [['X-Inertia' => 'true']]]);

test('api paths and requests expecting json keep json errors', function (string $path, array $headers, int $status) {
    registerErrorPageRoute($status, $path);

    $this->get($path, $headers)->assertStatus($status)
        ->assertHeaderMissing('X-Inertia')
        ->assertJsonMissingPath('component')
        ->assertJsonStructure(['message']);
})->with([
    'api without accept' => ['/api/_test/error', []],
    'web json' => ['/_test/error', ['Accept' => 'application/json']],
    'inertia json' => ['/_test/error', ['X-Inertia' => 'true', 'Accept' => 'application/json']],
])->with([403, 404, 419, 500, 503]);

test('local server errors keep detailed framework diagnostics', function () {
    $this->app->instance('env', 'local');
    config(['app.debug' => true]);
    Route::get('/_test/error', fn () => throw new RuntimeException('Local diagnostic detail.'));

    $this->get('/_test/error', ['X-Inertia' => 'true'])->assertInternalServerError()
        ->assertHeaderMissing('X-Inertia')
        ->assertSee('Local diagnostic detail.');
});

test('server errors discard shared domain props and still report the original exception', function () {
    Exceptions::fake([RuntimeException::class]);
    Route::get('/_test/error', function () {
        Inertia::share('property', fn () => throw new LogicException('Failing shared domain data.'));

        throw new RuntimeException('Private server detail.');
    })->middleware('web');

    $this->get('/_test/error', ['X-Inertia' => 'true'])->assertInternalServerError()
        ->assertJsonPath('component', 'errors')
        ->assertJsonPath('props', ['status' => 500, 'recoveryUrl' => '/dashboard'])
        ->assertDontSee('Private server detail.')
        ->assertDontSee('Failing shared domain data.');

    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Private server detail.');
});

test('early errors use the saved appearance and default invalid cookies to system', function (string $appearance, bool $dark) {
    $response = $this->withUnencryptedCookie('appearance', $appearance)
        ->get('/_test/does-not-exist')->assertNotFound();

    $response->assertViewHas('appearance', in_array($appearance, ['light', 'dark', 'system'], true) ? $appearance : 'system');

    if ($dark) {
        $response->assertSee('class="dark"', escape: false);
    } else {
        $response->assertDontSee('class="dark"', escape: false);
    }
})->with([
    'light' => ['light', false],
    'dark' => ['dark', true],
    'system' => ['system', false],
    'invalid' => ['invalid', false],
]);

test('failed submissions recover to the referring page without replaying the submitted data', function (string $method, int $status) {
    registerErrorPageRoute($status);

    $this->withHeaders(['X-Inertia' => 'true', 'Referer' => 'http://localhost/reservations?tab=arrivals'])
        ->{$method}('http://localhost/_test/error', ['private_note' => 'Never replay this.'])
        ->assertStatus($status)
        ->assertJsonPath('props.recoveryUrl', '/reservations?tab=arrivals')
        ->assertDontSee('Never replay this.');
})->with(['post', 'put', 'patch', 'delete'])->with([419, 503]);

test('back actions recover to a same-origin page', function (int $status) {
    registerErrorPageRoute($status);

    $this->get('https://estatell.test/_test/error', [
        'X-Inertia' => 'true',
        'Referer' => 'https://estatell.test:443/reservations?status=in_house#guest',
    ])->assertStatus($status)->assertJsonPath('props.recoveryUrl', '/reservations?status=in_house');
})->with([403, 404]);

test('invalid recovery referrers fall back to the dashboard redirect', function (?string $referrer) {
    registerErrorPageRoute(419);
    $headers = ['X-Inertia' => 'true'];

    if ($referrer !== null) {
        $headers['Referer'] = $referrer;
    }

    $this->post('https://estatell.test/_test/error', [], $headers)->assertStatus(419)
        ->assertJsonPath('props.recoveryUrl', '/dashboard');
})->with([
    'missing' => [null],
    'external host' => ['https://example.com/reservations'],
    'different scheme' => ['http://estatell.test/reservations'],
    'different port' => ['https://estatell.test:8443/reservations'],
    'relative' => ['/reservations'],
    'protocol relative' => ['//example.com/reservations'],
    'network path' => ['https://estatell.test//example.com'],
    'backslash' => ['https://estatell.test/\\example.com'],
    'malformed' => ['not a url'],
]);

test('maintenance retry preserves the requested get url and query', function () {
    registerErrorPageRoute(503);

    $this->get('https://estatell.test/_test/error?tab=arrivals', [
        'X-Inertia' => 'true',
        'Referer' => 'https://estatell.test/reservations',
    ])->assertServiceUnavailable()
        ->assertJsonPath('props.recoveryUrl', 'https://estatell.test/_test/error?tab=arrivals');
});

test('other statuses keep framework rendering', function () {
    registerErrorPageRoute(429);

    $this->get('/_test/error', ['X-Inertia' => 'true'])->assertTooManyRequests()
        ->assertHeaderMissing('X-Inertia');
});

test('authentication and validation failures keep their existing redirects', function () {
    Route::get('/_test/auth', fn () => 'Allowed')->middleware(['web', 'auth']);
    Route::post('/_test/validation', function (Request $request) {
        $request->validate(['name' => ['required']]);

        return 'Allowed';
    })->middleware('web');

    $this->get('/_test/auth')->assertRedirect(route('login'));
    $this->from('/_test/form')->post('/_test/validation')->assertRedirect('/_test/form')
        ->assertSessionHasErrors('name');
});
