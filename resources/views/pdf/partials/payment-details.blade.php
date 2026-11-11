{{-- The available bank accounts, one per line, then the Payment notes text. Prints nothing when both are empty. --}}
@if ($bankAccounts !== [] || $notes !== null)
    <div class="heading gap-16">{{ __('Payment details') }}</div>
    @foreach ($bankAccounts as $line)
        <div class="small">{{ $line }}</div>
    @endforeach
    @if ($notes !== null)
        <div class="small">{!! nl2br(e($notes)) !!}</div>
    @endif
@endif
