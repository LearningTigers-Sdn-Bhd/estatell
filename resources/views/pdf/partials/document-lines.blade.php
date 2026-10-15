{{--
    The lines of a customer document: the groups, the tax summary, the total and, when switched on, the amount in
    words. Pass `'words' => false` to leave the words out (the booking summary prints them under Balance due). Expects $groups (App\Services\Folio\DocumentLineGroups), $currency (Brick\Money\Currency) and $settings
    (App\Models\PrintSetting). A tax that has registration numbers prints them next to its label, or under the one "Tax" line with Tax breakdown off. Empty values never print. See docs/02-property/05-printing-settings.md#line-grouper.
--}}
@php
    /** @var \App\Services\Folio\DocumentLineGroups $groups */
    /** @var \Brick\Money\Currency $currency */
    /** @var \App\Models\PrintSetting $settings */
    $words ??= true;
    $room = $groups->showRoomColumn;
    $span = $room ? 3 : 2;
    $money = fn (string $amount): string => number_format((float) $amount, 2);
    $dates = fn (\App\Services\Folio\DocumentLineRow $row): string => $row->dateFrom->isSameDay($row->dateTo)
        ? $row->dateFrom->format('j M')
        : ($row->dateFrom->isSameMonth($row->dateTo)
            ? $row->dateFrom->format('j').'–'.$row->dateTo->format('j M')
            : $row->dateFrom->format('j M').' – '.$row->dateTo->format('j M'));
@endphp
<table class="gap-16">
    @if ($room)
        <thead>
            <tr class="rule">
                <th>{{ __('Date') }}</th>
                <th>{{ __('Room') }}</th>
                <th>{{ __('Description') }}</th>
                <th class="num">{{ __('Amount') }}</th>
            </tr>
        </thead>
    @endif
    @foreach ($groups->groups as $group)
        @if ($group->heading !== null)
            <tr class="rule">
                <th colspan="{{ $span + 1 }}">{{ $group->heading }}</th>
            </tr>
        @endif
        @foreach ($group->rows as $row)
            <tr class="rule">
                <td class="muted nowrap" style="width: 22%;">{{ $dates($row) }}</td>
                @if ($room)
                    <td style="width: 10%;">{{ $row->room }}</td>
                @endif
                <td>{{ $row->text() }}</td>
                <td class="num">{{ $money($row->amount) }}</td>
            </tr>
        @endforeach
    @endforeach
    @foreach ($groups->taxes as $tax)
        <tr>
            <td colspan="{{ $span }}" class="num">{{ $tax->label }}@if ($settings->tax_breakdown && $tax->numbers !== []) · {{ implode(', ', $tax->numbers) }}@endif</td>
            <td class="num">{{ $money($tax->amount) }}</td>
        </tr>
        @if (! $settings->tax_breakdown && $tax->numbers !== [])
            <tr>
                <td colspan="{{ $span + 1 }}" class="num small muted" style="padding-top: 0;">{{ implode(', ', $tax->numbers) }}</td>
            </tr>
        @endif
    @endforeach
    <tr class="total">
        <td colspan="{{ $span }}" class="num">{{ __('Total :currency', ['currency' => $currency->getCurrencyCode()]) }}</td>
        <td class="num">{{ $money($groups->total) }}</td>
    </tr>
</table>
@if ($words && $settings->show_amount_in_words)
    <div class="small muted gap-8">{{ app(\App\Services\Property\AmountInWords::class)->for($groups->total, $currency) }}</div>
@endif
