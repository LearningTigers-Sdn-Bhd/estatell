<?php

namespace App\Http\Controllers\Admin;

use App\Actions\BookingEngine\CreateSiteTemplate;
use App\Actions\BookingEngine\SetSiteTemplateStatus;
use App\Actions\BookingEngine\UpdateSiteTemplate;
use App\Data\BookingEngine\SiteTemplateData;
use App\Enums\SiteTemplateStatus;
use App\Enums\SiteTemplateType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetSiteTemplateStatusRequest;
use App\Http\Requests\Admin\SiteTemplateRequest;
use App\Models\Property;
use App\Models\SiteTemplate;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The booking website template registry. See docs/28-booking-engine/08-microsite.md#template-registry.
 */
class SiteTemplateController extends Controller
{
    /**
     * List every template. The list is small, so it loads once and the browser filters it.
     */
    public function index(): Response
    {
        return Inertia::render('admin/site-templates/index', [
            'templates' => SiteTemplate::query()->with(['slots', 'property:id,code,name'])->withCount('sites')
                ->orderByRaw("case status when 'available' then 0 when 'draft' then 1 else 2 end")
                ->orderBy('name')
                ->get()
                ->map(fn (SiteTemplate $template): SiteTemplateData => SiteTemplateData::fromModel($template))
                ->values(),
            'typeOptions' => SiteTemplateType::options(),
            'statusOptions' => SiteTemplateStatus::options(),
            'properties' => Property::query()->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn (Property $property): array => ['value' => (string) $property->id, 'label' => $property->code.' · '.$property->name])
                ->values(),
        ]);
    }

    public function store(SiteTemplateRequest $request, CreateSiteTemplate $createSiteTemplate): RedirectResponse
    {
        $template = $createSiteTemplate(
            $request->user(),
            $request->string('key')->toString(),
            $request->string('name')->toString(),
            SiteTemplateType::from($request->string('type')->toString()),
            $request->property(),
            $request->filled('preview_url') ? $request->string('preview_url')->toString() : null,
            $request->slots(),
            $request->boolean('allows_main_color'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added as a draft.', ['name' => $template->name])]);

        return back();
    }

    public function update(SiteTemplateRequest $request, SiteTemplate $siteTemplate, UpdateSiteTemplate $updateSiteTemplate): RedirectResponse
    {
        $updateSiteTemplate(
            $request->user(),
            $siteTemplate,
            $request->string('name')->toString(),
            $request->filled('preview_url') ? $request->string('preview_url')->toString() : null,
            $request->slots(),
            $request->boolean('allows_main_color'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved.')]);

        return back();
    }

    public function status(SetSiteTemplateStatusRequest $request, SiteTemplate $siteTemplate, SetSiteTemplateStatus $setSiteTemplateStatus): RedirectResponse
    {
        Inertia::flash('toast', $setSiteTemplateStatus($request->user(), $siteTemplate, $request->status())
            ? ['type' => 'success', 'message' => __('Template saved.')]
            : ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

        return back();
    }
}
