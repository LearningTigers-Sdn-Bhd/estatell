{{--
    The property letterhead: the logo, then the property name, the registered name and the lines switched on in the printing settings.
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
        <td class="property-details">
            <div class="heading">{{ $print->propertyName }}</div>
            @if ($print->legalName !== null)
                <div class="small">{{ $print->legalName }}</div>
            @endif
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
            <td class="document-heading">{{ $slot }}</td>
        @endif
    </tr>
</table>
