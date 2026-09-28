<?php

namespace App\Http\Controllers\Account;

use App\Actions\Notifications\UpdateUserNotificationPreference;
use App\Data\Notifications\NotificationPreferenceData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\UpdateNotificationPreferenceRequest;
use App\Services\Notifications\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function edit(Request $request, NotificationPreferences $preferences): Response
    {
        $user = $request->user();

        abort_if($user->is_admin || $user->property === null, 404);

        return Inertia::render('account/notifications', [
            'preferences' => NotificationPreferenceData::forUser($user, $preferences),
        ]);
    }

    public function update(
        UpdateNotificationPreferenceRequest $request,
        NotificationType $type,
        NotificationChannel $channel,
        UpdateUserNotificationPreference $updatePreference,
    ): RedirectResponse {
        $user = $request->user();

        abort_if($user->is_admin || $user->property === null, 404);

        $updated = $updatePreference($user, $type, $channel, $request->boolean('enabled'));

        Inertia::flash('toast', [
            'type' => $updated ? 'success' : 'error',
            'message' => $updated ? __('Notification preference updated.') : __('This notification preference cannot be changed.'),
        ]);

        return to_route('account.notifications.edit');
    }
}
