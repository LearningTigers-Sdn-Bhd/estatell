<?php

namespace App\Http\Controllers\Property;

use App\Actions\Notifications\ResetGuestEmail;
use App\Actions\Notifications\SaveGuestEmail;
use App\Enums\GuestEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\GuestEmailRequest;
use App\Models\Property;
use App\Services\Notifications\GuestEmailText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Emails: the wording of the emails the property sends to guests. See
 * docs/04-notifications/04-guest-emails.md.
 */
class GuestEmailController extends Controller
{
    public function edit(Property $property, GuestEmailText $text): Response
    {
        $emails = $text->available($property);
        abort_if($emails === [], 404);

        return Inertia::render('settings/emails', [
            'emails' => array_map(fn (GuestEmail $email): array => [
                'value' => $email->value,
                'label' => $email->label(),
                'description' => $email->description(),
                'placeholders' => $email->placeholders(),
                'default' => ['subject' => $email->defaultSubject(), 'body' => $email->defaultBody()],
                ...$text->for($property, $email),
            ], $emails),
        ]);
    }

    public function update(GuestEmailRequest $request, Property $property, GuestEmail $email, GuestEmailText $text, SaveGuestEmail $save): RedirectResponse
    {
        abort_unless(in_array($email, $text->available($property), true), 404);

        $save($request->user(), $property, $email, $request->string('subject')->trim()->toString(), $request->string('body')->trim()->toString());
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email saved.', ['email' => $email->label()])]);

        return back();
    }

    public function reset(Request $request, Property $property, GuestEmail $email, GuestEmailText $text, ResetGuestEmail $reset): RedirectResponse
    {
        abort_unless(in_array($email, $text->available($property), true), 404);

        $reset($request->user(), $property, $email);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':email is back to the default text.', ['email' => $email->label()])]);

        return back();
    }
}
