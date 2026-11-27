<?php

namespace App\Http\Controllers\Property;

use App\Actions\Notifications\ResetGuestEmail;
use App\Actions\Notifications\SaveGuestEmail;
use App\Enums\GuestEmail;
use App\Http\Controllers\Controller;
use App\Http\Requests\Notifications\GuestEmailPreviewRequest;
use App\Http\Requests\Notifications\GuestEmailRequest;
use App\Models\Property;
use App\Services\Notifications\GuestEmailPreview;
use App\Services\Notifications\GuestEmailText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › Emails: a list of the emails the property sends to guests, and one editor page per email with a preview
 * rendered from the real mail template. See
 * docs/04-notifications/04-guest-emails.md.
 */
class GuestEmailController extends Controller
{
    /**
     * The emails the property sends, grouped by the module that sends them. Each row opens its editor.
     */
    public function index(Property $property, GuestEmailText $text): Response
    {
        $emails = $text->available($property);
        abort_if($emails === [], 404);

        return Inertia::render('settings/emails/index', [
            'emails' => array_map(fn (GuestEmail $email): array => [
                'value' => $email->value,
                'label' => $email->label(),
                'module' => $email->module(),
                'sent_when' => $email->sentWhen(),
                'customised' => $text->for($property, $email)['customised'],
            ], $emails),
        ]);
    }

    public function edit(Property $property, GuestEmail $email, GuestEmailText $text, GuestEmailPreview $preview): Response
    {
        abort_unless(in_array($email, $text->available($property), true), 404);

        $current = $text->for($property, $email);

        return Inertia::render('settings/emails/edit', [
            'email' => [
                'value' => $email->value,
                'label' => $email->label(),
                'module' => $email->module(),
                'description' => $email->description(),
                'placeholders' => $email->placeholders(),
                ...$current,
            ],
            'preview' => $preview->example($property, $email, $current['subject'], $current['body']),
        ]);
    }

    /**
     * The email as the guest gets it, with the wording being edited and example details. Unknown placeholders are
     * left out of the preview; saving refuses them.
     */
    public function preview(GuestEmailPreviewRequest $request, Property $property, GuestEmail $email, GuestEmailText $text, GuestEmailPreview $preview): JsonResponse
    {
        abort_unless(in_array($email, $text->available($property), true), 404);

        return response()->json($preview->example($property, $email, $request->string('subject')->toString(), $request->string('body')->toString()));
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
