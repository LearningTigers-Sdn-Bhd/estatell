<?php

use App\Enums\DocumentType;
use App\Models\BusinessDate;
use App\Models\DocumentSequence;
use App\Models\Property;
use App\Services\NightAudit\DocumentReference;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(12, 0));
});

function liveProperty(string $name = 'Grand River Hotel', string $date = '2026-10-10'): Property
{
    $property = Property::factory()->live()->create(['name' => $name]);
    BusinessDate::factory()->for($property)->create(['date' => $date]);

    return $property;
}

test('a reference is the prefix, two-digit year, type code and five-digit number', function (DocumentType $type, int $code) {
    expect(app(DocumentReference::class)->format('GRH', 2026, $type, 42))->toBe("GRH-26{$code}00042");
})->with([
    [DocumentType::Reservation, 1],
    [DocumentType::RegistrationCard, 2],
    [DocumentType::Folio, 3],
    [DocumentType::Receipt, 5],
    [DocumentType::Invoice, 7],
    [DocumentType::CreditNote, 8],
]);

test('a number past five digits just grows', function () {
    expect(app(DocumentReference::class)->format('GRH', 2026, DocumentType::Receipt, 123456))->toBe('GRH-265123456');
});

test('numbers count up from one', function () {
    $property = liveProperty();
    $next = app(NextDocumentNumber::class);

    $first = $next($property, DocumentType::Receipt);
    $second = $next($property, DocumentType::Receipt);

    expect($first->number)->toBe(1)
        ->and($first->year)->toBe(2026)
        ->and($first->reference)->toBe('GRH-26500001')
        ->and($second->number)->toBe(2)
        ->and($second->reference)->toBe('GRH-26500002')
        ->and(DocumentSequence::query()->sole()->last_number)->toBe(2);
});

test('each document type has its own sequence', function () {
    $property = liveProperty();
    $next = app(NextDocumentNumber::class);

    $next($property, DocumentType::Receipt);
    $next($property, DocumentType::Receipt);

    expect($next($property, DocumentType::Folio)->reference)->toBe('GRH-26300001')
        ->and($next($property, DocumentType::CreditNote)->reference)->toBe('GRH-26800001');
});

test('each property has its own sequence and prefix', function () {
    $next = app(NextDocumentNumber::class);
    $grand = liveProperty('Grand River Hotel');
    $pulau = liveProperty('Pulau Inn');

    $next($grand, DocumentType::Receipt);

    expect($next($pulau, DocumentType::Receipt)->reference)->toBe('PUI-26500001')
        ->and($next($grand, DocumentType::Receipt)->reference)->toBe('GRH-26500002');
});

test('the year is the business date year, not the calendar year', function () {
    $this->travelTo(now()->setDate(2027, 1, 1)->setTime(1, 30));
    $property = liveProperty(date: '2026-12-31');

    $issued = app(NextDocumentNumber::class)($property, DocumentType::Receipt);

    expect($issued->year)->toBe(2026)
        ->and($issued->reference)->toBe('GRH-26500001');
});

test('a new year starts again at one', function () {
    $property = liveProperty(date: '2026-12-31');
    $next = app(NextDocumentNumber::class);

    $next($property, DocumentType::Receipt);
    $next($property, DocumentType::Receipt);

    $property->businessDates()->current()->update(['status' => 'closed', 'closed_at' => now()]);
    BusinessDate::factory()->for($property)->create(['date' => '2027-01-01']);

    expect($next($property, DocumentType::Receipt)->reference)->toBe('GRH-27500001')
        ->and($next($property, DocumentType::Receipt)->number)->toBe(2)
        ->and(DocumentSequence::query()->count())->toBe(2);
});

test('before go live the calendar year in the property time zone is used', function () {
    $property = Property::factory()->create(['name' => 'Grand River Hotel']);

    expect(app(NextDocumentNumber::class)($property, DocumentType::Reservation)->reference)->toBe('GRH-26100001');
});

test('the floor keeps a stale counter above the highest stored number', function () {
    $property = liveProperty();
    $next = app(NextDocumentNumber::class);

    $next($property, DocumentType::Folio);

    expect($next($property, DocumentType::Folio, floor: 40)->number)->toBe(41)
        ->and($next($property, DocumentType::Folio, floor: 10)->number)->toBe(42);
});

test('a number is given back when the document it belongs to rolls back', function () {
    $property = liveProperty();
    $next = app(NextDocumentNumber::class);

    try {
        DB::transaction(function () use ($next, $property) {
            $next($property, DocumentType::Invoice);

            throw new RuntimeException('document failed');
        });
    } catch (RuntimeException) {
    }

    expect($next($property, DocumentType::Invoice)->number)->toBe(1);
});

test('the sequence never issues the same number twice', function () {
    $property = liveProperty();
    $next = app(NextDocumentNumber::class);

    $numbers = collect(range(1, 25))->map(fn () => $next($property, DocumentType::Receipt)->number);

    expect($numbers->unique()->count())->toBe(25)
        ->and($numbers->max())->toBe(25);
});
