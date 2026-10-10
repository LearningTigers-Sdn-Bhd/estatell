{{--
    The Rooms block of a customer document that has no Rooms section of its own (the folio invoice, the AR invoice):
    one line per unit, for example "Room 103 · Tan Wei Ming · 12–15 Oct". Pass $rooms (list<string>); an empty list
    prints nothing. The caller leaves the list empty when Room and guest on lines is on, because the group headings
    name each room then. See docs/02-property/05-printing-settings.md#rooms-block.
--}}
@php
    /** @var list<string> $rooms */
@endphp
@if ($rooms !== [])
    <div class="heading gap-16">{{ __('Rooms') }}</div>
    <table>
        @foreach ($rooms as $line)
            <tr class="rule"><td>{{ $line }}</td></tr>
        @endforeach
    </table>
@endif
