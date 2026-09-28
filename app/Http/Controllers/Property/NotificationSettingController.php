<?php

namespace App\Http\Controllers\Property;

use App\Actions\Notifications\UpdatePropertyNotificationSetting;
use App\Data\Notifications\NotificationPreferenceData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferenceRequest;
use App\Models\Property;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingController extends Controller
{
    public function index(Property $property, NotificationPreferences $preferences): Response
    {
        return Inertia::render('settings/notifications', [
            'preferences' => NotificationPreferenceData::forProperty($property, $preferences),
        ]);
    }

    public function update(
        UpdateNotificationPreferenceRequest $request,
        Property $property,
        NotificationType $type,
        NotificationChannel $channel,
        UpdatePropertyNotificationSetting $updateSetting,
    ): RedirectResponse {
        $updated = $updateSetting($property, $type, $channel, $request->boolean('enabled'));

        Inertia::flash('toast', [
            'type' => $updated ? 'success' : 'error',
            'message' => $updated ? __('Notification setting updated.') : __('This notification setting cannot be changed.'),
        ]);

        return to_route('settings.notifications.index', ['property' => $property->code]);
    }
}
