<?php

use App\Enums\DocumentType;
use App\Services\Onboarding\Wastays\PlannedNumberRunner;

it('returns a refused result so the caller can report the refusal, not an unused number', function () {
    $refused = new class
    {
        public function isRefused(): bool
        {
            return true;
        }
    };

    $result = app(PlannedNumberRunner::class)->run(DocumentType::Receipt, 12, fn () => $refused, true, 'test');

    expect($result)->toBe($refused);
});
