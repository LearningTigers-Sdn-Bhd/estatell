<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Undo check-in form shows when it opens: the charges and payments that stay on the unit's folios (an undo
 * posts and reverses nothing), or an `error` saying why it cannot be undone.
 */
class UnitUndoPreviewData extends Data
{
    /**
     * @param  list<array{description: string, amount: string}>  $charges  The charges posted so far, each as a line.
     * @param  string  $paid  The net payments held on the unit's folios.
     */
    public function __construct(
        public array $charges,
        public string $paid,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self([], '0.00', $error);
    }
}
