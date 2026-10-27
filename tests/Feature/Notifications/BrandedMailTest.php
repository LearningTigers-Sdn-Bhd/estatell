<?php

use App\Models\BusinessDate;
use App\Models\NightAudit;
use App\Models\NightAuditException;
use App\Models\PartnerInvitation;
use App\Models\Property;
use App\Models\PropertySetting;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Notifications\NightAuditCompleted;
use App\Notifications\NightAuditExceptions;
use App\Notifications\NightAuditFailed;
use App\Notifications\OwnerSetupNotification;
use App\Notifications\PartnerInvitationNotification;
use App\Notifications\StaffInvitationNotification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\URL;

test('existing email types render branded HTML and text with the correct property footer', function (string $type) {
    $property = Property::factory()->live()->create(['name' => 'Bayu Resort']);
    PropertySetting::factory()->for($property)->create();
    $user = User::factory()->for($property)->create();

    $notification = match ($type) {
        'staff' => new StaffInvitationNotification(StaffInvitation::factory()->for($property)->create(), 'invitation-token'),
        'partner' => new PartnerInvitationNotification(PartnerInvitation::factory()->create(), 'partner-token'),
        'owner' => new OwnerSetupNotification,
        'reset' => new ResetPassword('reset-token'),
        'verify' => new VerifyEmail,
        default => (function () use ($type, $property) {
            $date = BusinessDate::factory()->for($property)->create();
            $audit = NightAudit::factory()->completed()->create(['business_date_id' => $date->id]);

            if ($type === 'exceptions') {
                NightAuditException::factory()->create(['night_audit_id' => $audit->id]);
            }

            return match ($type) {
                'completed' => new NightAuditCompleted($audit),
                'exceptions' => new NightAuditExceptions($audit),
                'failed' => new NightAuditFailed($audit),
            };
        })(),
    };

    $message = $notification->toMail($user);
    $html = (string) app(Markdown::class)->render($message->markdown, $message->data());
    $text = (string) app(Markdown::class)->renderText($message->markdown, $message->data());

    expect($html)->toContain('estatell', '#006c49')->not->toContain('laravel.com/img');
    expect($text)->toContain('estatell');

    if ($type === 'partner') {
        expect($html)->not->toContain('Bayu Resort');
        expect($text)->not->toContain('Bayu Resort');
    } else {
        expect($html)->toContain('Bayu Resort · estatell');
        expect($text)->toContain('Bayu Resort · estatell');
    }

    expect($html)->toContain(e($message->actionUrl));
    expect($text)->toContain($message->actionUrl);

    if ($type === 'reset') {
        expect($message->actionUrl)->toContain('reset-token', urlencode($user->email));
    }

    if ($type === 'verify') {
        expect(URL::hasValidSignature(Request::create($message->actionUrl)))->toBeTrue();
    }
})->with(['staff', 'partner', 'owner', 'reset', 'verify', 'completed', 'exceptions', 'failed']);

test('platform account emails do not borrow a property footer', function () {
    $user = User::factory()->admin()->create();

    $message = (new ResetPassword('platform-token'))->toMail($user);
    $html = (string) app(Markdown::class)->render($message->markdown, $message->data());

    expect($html)->toContain('estatell')->not->toContain('· estatell');
});

test('email layout supports dark mode', function () {
    $user = User::factory()->admin()->create();

    $message = (new ResetPassword('dark-token'))->toMail($user);
    $html = (string) app(Markdown::class)->render($message->markdown, $message->data());

    expect($html)
        ->toContain('<meta name="color-scheme" content="light dark">', '<meta name="supported-color-schemes" content="light dark">')
        ->toContain('@media (prefers-color-scheme: dark)', '.button-primary', '#3ecf8e', '[data-ogsc]', '[data-ogsb]')
        ->toContain('#006c49');
});
