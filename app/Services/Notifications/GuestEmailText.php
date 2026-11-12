<?php

namespace App\Services\Notifications;

use App\Enums\GuestEmail;
use App\Features\EInvoicing;
use App\Models\Property;
use App\Models\PropertyGuestEmail;
use Laravel\Pennant\Feature;

/**
 * Reads the subject and message a property uses for a guest email, and fills in its placeholders.
 */
class GuestEmailText
{
    /**
     * The guest emails this property sends, given its features.
     *
     * @return list<GuestEmail>
     */
    public function available(Property $property): array
    {
        $available = [];
        foreach (GuestEmail::cases() as $email) {
            $sends = match ($email) {
                GuestEmail::EInvoice => Feature::for($property)->active(EInvoicing::class),
            };

            if ($sends) {
                $available[] = $email;
            }
        }

        return $available;
    }

    /**
     * The property's own text, or the default when it has none.
     *
     * @return array{subject: string, body: string, customised: bool}
     */
    public function for(Property $property, GuestEmail $email): array
    {
        $saved = PropertyGuestEmail::query()
            ->where('property_id', $property->id)
            ->where('email', $email)
            ->first();

        return [
            'subject' => $saved->subject ?? $email->defaultSubject(),
            'body' => $saved->body ?? $email->defaultBody(),
            'customised' => $saved !== null,
        ];
    }

    /**
     * Replaces each `{placeholder}` with its value. A placeholder with no value becomes an empty string.
     *
     * @param  array<string, string>  $values
     */
    public function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback('/\{([a-z_]+)\}/', fn (array $match): string => $values[$match[1]] ?? '', $text);
    }

    /**
     * Placeholders in the text that the email does not know, for example a typo such as `{guestname}`.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(GuestEmail $email, string $text): array
    {
        preg_match_all('/\{([^{}]*)\}/', $text, $matches);
        $known = array_column($email->placeholders(), 'key');

        return array_values(array_unique(array_diff($matches[1], $known)));
    }
}
