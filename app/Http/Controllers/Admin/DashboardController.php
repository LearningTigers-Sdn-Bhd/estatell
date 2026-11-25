<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Platform\AdminDashboardSummary;
use App\Services\Platform\AdminDashboardTodo;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform admin dashboard. Both cards load after the page. See docs/01-platform/10-admin-dashboard.md.
 */
class DashboardController extends Controller
{
    public function show(AdminDashboardTodo $todo, AdminDashboardSummary $summary): Response
    {
        return Inertia::render('admin/dashboard', [
            'todo' => Inertia::defer(fn () => $todo->get(), 'todo'),
            'summary' => Inertia::defer(fn () => $summary->get(), 'summary'),
        ]);
    }
}
