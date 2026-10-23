<?php

namespace App\Actions\Onboarding\Wastays;

/**
 * What a replayed day did: counts per kind of event, and notes for rows left out. Holds no personal data.
 */
class WastaysReplaySummary
{
    /** @var array<string, int> */
    public array $counts = [];

    /** @var list<string> */
    public array $notes = [];

    public function count(string $label): void
    {
        $this->counts[$label] = ($this->counts[$label] ?? 0) + 1;
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }
}
