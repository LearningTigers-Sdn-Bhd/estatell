<?php

namespace App\Actions\Onboarding\Wastays;

/**
 * What a setup import did: counts per part, and the wastays items it left out. Holds no personal data.
 */
class WastaysSetupSummary
{
    /** @var array<string, array<string, int>> */
    public array $parts = [];

    /** @var list<string> */
    public array $skippedNotes = [];

    public function count(string $part, string $outcome): void
    {
        $this->parts[$part] ??= ['created' => 0, 'linked' => 0, 'skipped' => 0];
        $this->parts[$part][$outcome]++;
    }

    public function skip(string $part, string $note): void
    {
        $this->count($part, 'skipped');
        $this->skippedNotes[] = "{$part}: {$note}";
    }
}
