<x-mail::message :property-name="$propertyName ?? null">
@isset($mailEyebrow)
<p class="eyebrow">{{ $mailEyebrow }}</p>
@endisset

# {{ $mailTitle ?? $subject ?? __('Hello!') }}

@if (! empty($greeting))
{{ $greeting }}

@endif
@if (isset($auditSummary))
@if ($auditFailed)
<x-mail::panel tone="error">
@foreach (array_slice($introLines, 0, 2) as $line)
{{ $line }}

@endforeach
</x-mail::panel>
@foreach (array_slice($introLines, 2) as $line)
<p>{{ $line }}</p>
@endforeach
@else
@if ($auditSummary->exceptions->isNotEmpty())
<x-mail::panel tone="warning">
@foreach ($auditExceptions as $line)
{{ $line }}

@endforeach
</x-mail::panel>
<x-mail::button :url="$actionUrl">
{{ $actionText }} →
</x-mail::button>
@endif
@if ($auditSummary->statistics !== null)
<x-mail::table>
| {{ __('Occupancy') }} | ADR | RevPAR |
| :--- | :--- | :--- |
| {{ $auditSummary->statistics->occupancy }}% | {{ $auditSummary->currency }} {{ $auditSummary->statistics->adr }} | {{ $auditSummary->currency }} {{ $auditSummary->statistics->revpar }} |
</x-mail::table>
<p>{{ $auditFigures[1] }}</p>

@endif
@if ($auditSummary->revenue !== [])
<h3>{{ __('Revenue by code:') }}</h3>
<x-mail::table>
| {{ __('Revenue') }} | {{ __('Amount') }} |
| :--- | ---: |
@foreach ($auditSummary->revenue as $row)
| {{ $row['name'] }} | {{ $auditSummary->currency }} {{ $row['amount']->getAmount() }} |
@endforeach
</x-mail::table>
@endif
@if ($auditSummary->payments !== [] || ! $auditSummary->refunds->isZero())
<h3>{{ __('Payments by method:') }}</h3>
<x-mail::table>
| {{ __('Payment method') }} | {{ __('Amount') }} |
| :--- | ---: |
@foreach ($auditSummary->payments as $row)
| {{ $row['name'] }} | {{ $auditSummary->currency }} {{ $row['amount']->getAmount() }} |
@endforeach
@if (! $auditSummary->refunds->isZero())
| {{ __('Refunds') }} | {{ $auditSummary->currency }} {{ $auditSummary->refunds->getAmount() }} |
@endif
</x-mail::table>
@endif
@if ($auditSummary->meals->isNotEmpty())
<h3>{{ __('Meals to prepare on :date:', ['date' => $auditSummary->mealDate->toFormattedDateString()]) }}</h3>
<x-mail::table>
| {{ __('Meal') }} | {{ __('Guests') }} |
| :--- | ---: |
@foreach ($auditSummary->meals as $meal)
| {{ $meal->mealPeriodName }} | {{ $meal->guests }} |
@endforeach
</x-mail::table>
@endif
@endif
@else
@foreach ($introLines as $line)
{{ $line }}

@endforeach
@endif

@isset($mailDetails)
<x-mail::table>
| {{ __('Details') }} | |
| :--- | :--- |
@foreach ($mailDetails as $label => $value)
| {{ $label }} | {{ $value }} |
@endforeach
</x-mail::table>
@endisset

@isset($setupSteps)
<x-mail::panel>
@foreach ($setupSteps as $step)
{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} · {{ $step }}

@endforeach
</x-mail::panel>
@endisset

@isset($actionText)
@if (! isset($auditSummary) || $auditFailed || $auditSummary->exceptions->isEmpty())
<x-mail::button :url="$actionUrl">
{{ $actionText }} →
</x-mail::button>
@endif
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<x-slot:subcopy>
@lang("If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".'into your web browser:', ['actionText' => $actionText])
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
