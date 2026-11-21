<?php

namespace App\Http\Requests\Concerns;

use App\Enums\RemarkKind;

/**
 * The `remarks` object of the booking form and the Edit remarks Dialog: one optional text per `RemarkKind` value, up to
 * 500 characters. See docs/12-reservations/16-remarks.md.
 */
trait ValidatesRemarks
{
    /**
     * @return array<string, list<string>>
     */
    protected function remarkRules(): array
    {
        return [
            'remarks' => ['nullable', 'array:'.implode(',', array_column(RemarkKind::cases(), 'value'))],
            'remarks.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * The trimmed remarks that have text, by kind value, in `RemarkKind` order.
     *
     * @return array<string, string>
     */
    public function remarks(): array
    {
        $input = (array) ($this->validated('remarks') ?? []);
        $remarks = [];

        foreach (RemarkKind::cases() as $kind) {
            $body = trim((string) ($input[$kind->value] ?? ''));

            if ($body !== '') {
                $remarks[$kind->value] = $body;
            }
        }

        return $remarks;
    }
}
