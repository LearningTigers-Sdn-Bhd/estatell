<?php

namespace App\Services\Registration;

use App\Enums\SystemTransactionCode;
use App\Enums\TaxRateType;
use App\Enums\TermsPlaceholder;
use App\Models\Property;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Fills placeholders in a terms text, renders its markdown and lists its checklist items.
 *
 * A checklist item is a task-list line (`- [ ] text`). Its position in the list is its identity.
 */
class TermsRenderer
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([a-z_]+)\s*\}\}/';

    private const ITEM_PATTERN = '/^[ \t]*[-*+][ \t]+\[[ xX]\][ \t]+(.+?)[ \t]*$/m';

    /**
     * The values the placeholders take for a property. Needs the property settings.
     *
     * @return array<string, string> keyed by `TermsPlaceholder` value
     */
    public function valuesFor(Property $property): array
    {
        $settings = $property->settings;

        return [
            TermsPlaceholder::PropertyName->value => $property->name,
            TermsPlaceholder::CheckInTime->value => $settings === null ? '' : $this->time($settings->check_in_time),
            TermsPlaceholder::CheckOutTime->value => $settings === null ? '' : $this->time($settings->check_out_time),
            TermsPlaceholder::SecurityDeposit->value => $settings === null ? '' : "{$settings->currency->value} {$settings->security_deposit_amount}",
            TermsPlaceholder::TourismTaxRate->value => $settings === null ? '' : $this->tourismTax($property, $settings->currency->value),
        ];
    }

    /**
     * Fill the placeholders, render the markdown and list the checklist items.
     * The checklist lines are left out of the html, because the card draws them as checkboxes.
     *
     * @param  array<string, string>  $values  keyed by `TermsPlaceholder` value
     * @return array{html: string, text: string, items: list<array{position: int, text: string}>}
     */
    public function render(string $body, array $values): array
    {
        $filled = $this->fill($body, $values);

        $items = $this->items($filled);

        return [
            'html' => Str::markdown(preg_replace(self::ITEM_PATTERN, '', $filled) ?? $filled, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'text' => $filled,
            'items' => array_map(
                fn (string $text, int $position): array => ['position' => $position, 'text' => $text],
                $items,
                array_keys($items),
            ),
        ];
    }

    /**
     * The terms in reading order, split where the checklist lines are: each part is the rendered text
     * followed by the checklist items that come right after it. A PDF draws the boxes where the author wrote them.
     *
     * @param  array<string, string>  $values  keyed by `TermsPlaceholder` value
     * @return list<array{html: string, items: list<array{position: int, text: string}>}>
     */
    public function parts(string $body, array $values): array
    {
        $pieces = preg_split('/((?:^[ \t]*[-*+][ \t]+\[[ xX]\][ \t]+.+?[ \t]*(?:\R|$))+)/m', $this->fill($body, $values), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $position = 0;
        $parts = [];

        for ($index = 0; $index < count($pieces); $index += 2) {
            $items = [];

            foreach ($this->items($pieces[$index + 1] ?? '') as $text) {
                $items[] = ['position' => $position++, 'text' => $text];
            }

            $parts[] = [
                'html' => Str::markdown($pieces[$index], ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                'items' => $items,
            ];
        }

        return $parts;
    }

    /**
     * The checklist items of a terms text, in order, as written.
     *
     * @return list<string>
     */
    public function items(string $body): array
    {
        preg_match_all(self::ITEM_PATTERN, $body, $matches);

        return array_values($matches[1]);
    }

    /**
     * The placeholder names in a terms text that are not in `TermsPlaceholder`.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $body): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $body, $matches);

        $known = array_column(TermsPlaceholder::cases(), 'value');

        return array_values(array_unique(array_diff($matches[1], $known)));
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $body, array $values): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            fn (array $match): string => $values[$match[1]] ?? $match[0],
            $body,
        ) ?? $body;
    }

    private function time(string $time): string
    {
        return Carbon::parse($time)->format('g:i A');
    }

    private function tourismTax(Property $property, string $currency): string
    {
        $tax = $property->taxes()
            ->enabled()
            ->whereHas('transactionCode', fn ($query) => $query->where('system_key', SystemTransactionCode::TourismTax->value))
            ->first();

        if ($tax === null) {
            return '';
        }

        $rate = rtrim(rtrim($tax->rate, '0'), '.');

        return $tax->rate_type === TaxRateType::Percent
            ? "{$rate}%"
            : __(':currency :rate per night', ['currency' => $currency, 'rate' => $tax->rate]);
    }
}
