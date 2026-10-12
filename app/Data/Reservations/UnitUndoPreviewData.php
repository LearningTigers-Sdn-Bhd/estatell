<?php

namespace App\Data\Reservations;

use Spatie\LaravelData\Data;

/**
 * What the Undo check-in and Undo check-out forms show when they open: the charges and payments that stay on the unit's folios (an undo
 * posts and reverses nothing), the folios an Undo check-out reopens, or an `error` saying why it cannot be undone.
 */
class UnitUndoPreviewData extends Data
{
    /**
     * @param  list<array{description: string, amount: string}>  $charges  The charges posted so far, each as a line.
     * @param  string  $paid  The net payments held on the unit's folios.
     * @param  list<string>  $reopens  The folios Undo check-out reopens, as "number" or "number · invoice".
     */
    public function __construct(
        public array $charges,
        public string $paid,
        public array $reopens,
        public ?string $error,
    ) {}

    public static function refused(string $error): self
    {
        return new self([], '0.00', [], $error);
    }
}
