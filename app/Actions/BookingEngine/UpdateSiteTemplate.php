<?php

namespace App\Actions\BookingEngine;

use App\Models\SiteTemplate;
use App\Models\SiteTemplateSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Change a template's name, preview link and slots. The key, type and property stay as they were, because sites use them.
 */
class UpdateSiteTemplate
{
    public function __construct(private SyncSiteTemplateSlots $syncSlots) {}

    /**
     * @param  list<array{key: string, label: string, required: bool}>  $slots
     */
    public function __invoke(User $admin, SiteTemplate $template, string $name, ?string $previewUrl, array $slots, bool $allowsMainColor): void
    {
        DB::transaction(function () use ($admin, $template, $name, $previewUrl, $slots, $allowsMainColor): void {
            $template = SiteTemplate::query()->with('slots')->lockForUpdate()->findOrFail($template->id);

            $before = [
                'name' => $template->name,
                'preview_url' => $template->preview_url,
                'allows_main_color' => $template->allows_main_color,
                'slots' => $template->slots->map(fn (SiteTemplateSlot $slot): array => ['key' => $slot->key, 'label' => $slot->label, 'required' => $slot->required])->all(),
            ];
            $after = ['name' => $name, 'preview_url' => $previewUrl, 'allows_main_color' => $allowsMainColor, 'slots' => $slots];

            if ($before === $after) {
                return;
            }

            $template->update(['name' => $name, 'preview_url' => $previewUrl, 'allows_main_color' => $allowsMainColor]);
            ($this->syncSlots)($template, $slots);

            activity()
                ->performedOn($template)
                ->causedBy($admin)
                ->event('site-template.updated')
                ->withProperties(['from' => $before, 'to' => $after])
                ->log('Booking website template changed');
        });
    }
}
