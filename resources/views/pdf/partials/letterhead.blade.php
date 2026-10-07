{{--
    The property letterhead: the logo, then the property name and the lines switched on in the printing settings.
    Use it as a component so the document heading sits on the right:
    @component('pdf.partials.letterhead', ['print' => $print]) <div class="title">…</div> @endcomponent
--}}
@php
    /** @var \App\Services\Property\PrintLayout $print */
@endphp
<table class="letterhead">
    <tr>
        @if ($print->logoDataUri !== null)
            <td class="logo"><img src="{{ $print->logoDataUri }}" alt=""></td>
        @endif
        <td>
            <div class="heading">{{ $print->propertyName }}</div>
            @foreach ($print->addressLines as $line)
                <div class="small">{{ $line }}</div>
            @endforeach
            @foreach ($print->contactLines as $line)
                <div class="small">{{ $line }}</div>
            @endforeach
            @foreach ($print->registrationLines as $line)
                <div class="small muted">{{ $line }}</div>
            @endforeach
        </td>
        @if (trim($slot) !== '')
            <td class="num">{{ $slot }}</td>
        @endif
    </tr>
</table>
