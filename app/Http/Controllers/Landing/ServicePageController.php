<?php

namespace App\Http\Controllers\Landing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ServicePageController extends Controller
{
    /**
     * The route only matches slugs listed in ServicePages, so every request that reaches here has a page.
     */
    public function show(string $slug): Response
    {
        return Inertia::render('services/show', ['slug' => $slug]);
    }
}
