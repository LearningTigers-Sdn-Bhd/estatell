<?php

namespace App\Services\Platform;

use App\Models\Partner;

/**
 * The text behind a partner's "Copy message" button: the partner's saved template, or the default wording. The
 * browser fills `{partner}` and `{link}`, because the link holds no secret.
 */
class PartnerSignupMessage
{
    public const MAX_LENGTH = 1000;

    public const PLACEHOLDERS = ['partner', 'link'];

    /**
     * The template a partner who never edited it gets.
     */
    public function defaultTemplate(): string
    {
        return implode("\n", [
            'Hi! Set up your property on estatell with {partner}.',
            'Register here: {link}',
            "We'll help you get started once you've signed up.",
        ]);
    }

    /**
     * The partner's saved template, or the default.
     */
    public function templateFor(Partner $partner): string
    {
        return $partner->signup_message_template ?? $this->defaultTemplate();
    }

    /**
     * The placeholder names in a template that this message does not know.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $template): array
    {
        preg_match_all('/\{([^{}]*)\}/', $template, $matches);

        return array_values(array_unique(array_diff($matches[1], self::PLACEHOLDERS)));
    }
}
