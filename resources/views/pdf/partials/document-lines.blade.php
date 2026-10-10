{{--
    The lines of a customer document: the groups, the tax summary, the total and, when switched on, the amount in
    words. Expects $groups (App\Services\Folio\DocumentLineGroups), $currency (App\Enums\Currency) and $settings
    (App\Models\PrintSetting). Empty values never print. See docs/02-property/05-printing-settings.md#line-grouper.
--}}
@php
    /** @var \App\Services\Folio\DocumentLineGroups $groups */
    /** @var \App\Enums\Currency $currency */
    /** @var \App\Models\PrintSetting $settings */
    $money = fn (string $amount): string => number_format((float) $amount, 2);
    $dates = fn (\App\Services\Folio\DocumentLineRow $row): string => $row->dateFrom->isSameDay($row->dateTo)
        ? $row->dateFrom->format('j M')
        : ($row->dateFrom->isSameMonth($row->dateTo)
            ? $row->dateFrom->format('j').'–'.$row->dateTo->format('j M')
            : $row->dateFrom->format('j M').' – '.$row->dateTo->format('j M'));
@endphp
<table class="gap-16">
    @foreach ($groups->groups as $group)
        @if ($group->heading !== null)
            <tr class="rule">
                <th colspan="3">{{ $group->heading }}</th>
            </tr>
        @endif
        @foreach ($group->rows as $row)
            <tr class="rule">
                <td class="muted" style="width: 18%;">{{ $dates($row) }}</td>
                <td>{{ $row->text() }}</td>
                <td class="num">{{ $money($row->amount) }}</td>
            </tr>
        @endforeach
    @endforeach
    @foreach ($groups->taxes as $tax)
        <tr>
            <td colspan="2" class="num">{{ $tax->label }}</td>
            <td class="num">{{ $money($tax->amount) }}</td>
        </tr>
    @endforeach
    <tr class="total">
        <td colspan="2" class="num">{{ __('Total :currency', ['currency' => $currency->value]) }}</td>
        <td class="num">{{ $money($groups->total) }}</td>
    </tr>
</table>
@if ($settings->show_amount_in_words)
    <div class="small muted gap-8">{{ app(\App\Services\Property\AmountInWords::class)->for($groups->total, $currency) }}</div>
@endif
