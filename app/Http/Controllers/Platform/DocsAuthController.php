<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\DocsAuthToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DocsAuthController extends Controller
{
    /**
     * Issues an authenticated token and redirects staff to the documentation site.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $targetUrl = $this->resolveTargetUrl($request->query('return_to'));
        $token = DocsAuthToken::generate($user);

        $separator = str_contains($targetUrl, '?') ? '&' : '?';
        $redirectUrl = $targetUrl.$separator.'token='.urlencode($token);

        return redirect()->away($redirectUrl);
    }

    private function resolveTargetUrl(?string $returnTo): string
    {
        $defaultDocsUrl = rtrim((string) config('estatell.docs_url', 'https://docs.estatell.com'), '/').'/en/docs';

        if ($returnTo === null || $returnTo === '') {
            return $defaultDocsUrl;
        }

        $parsedReturn = parse_url($returnTo);
        if ($parsedReturn === false || ! isset($parsedReturn['host'])) {
            return $defaultDocsUrl;
        }

        $configuredDocsHost = parse_url(config('estatell.docs_url', 'https://docs.estatell.com'), PHP_URL_HOST);
        $allowedHosts = array_filter([
            $configuredDocsHost,
            'docs.estatell.com',
            app()->environment('local', 'testing') ? 'localhost' : null,
            app()->environment('local', 'testing') ? '127.0.0.1' : null,
        ]);

        if (! in_array($parsedReturn['host'], $allowedHosts, true)) {
            return $defaultDocsUrl;
        }

        return $returnTo;
    }
}
