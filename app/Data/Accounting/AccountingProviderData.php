<?php

namespace App\Data\Accounting;

use App\Enums\AccountingProvider;
use Spatie\LaravelData\Data;

/**
 * One accounting software the property can pick, with the words and extra fields its form needs.
 */
class AccountingProviderData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public string $description,
        public string $keyLabel,
        public string $keyHelp,
        public bool $needsCompanySubdomain,
        public bool $supportsSandbox,
    ) {}

    public static function fromEnum(AccountingProvider $provider): self
    {
        return new self(
            value: $provider->value,
            label: $provider->label(),
            description: $provider->description(),
            keyLabel: $provider->keyLabel(),
            keyHelp: $provider->keyHelp(),
            needsCompanySubdomain: $provider->needsCompanySubdomain(),
            supportsSandbox: $provider->supportsSandbox(),
        );
    }
}
