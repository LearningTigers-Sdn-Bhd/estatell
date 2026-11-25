<?php

use App\Enums\DocumentType;
use App\Models\BusinessDate;
use App\Models\DocumentSequence;
use App\Models\Property;
use App\Services\NightAudit\DocumentReference;
use App\Services\NightAudit\NextDocumentNumber;

it('gives POS orders and shifts their own two-digit type codes', function () {
    expect(DocumentType::PosOrder->code())->toBe(10)
        ->and(DocumentType::PosShift->code())->toBe(11)
        ->and(app(DocumentReference::class)->format('GRH', 2026, DocumentType::PosOrder, 42))->toBe('GRH-26'.'10'.'00042')
        ->and(app(DocumentReference::class)->format('GRH', 2026, DocumentType::PosShift, 7))->toBe('GRH-26'.'11'.'00007');
});

it('keeps type codes unique so no two document types share a reference', function () {
    $codes = array_map(fn (DocumentType $type): int => $type->code(), DocumentType::cases());

    expect($codes)->toBe(array_values(array_unique($codes)));
});

it('issues separate gap-free sequences for POS orders and shifts', function () {
    $property = Property::factory()->live()->create();
    BusinessDate::factory()->create(['property_id' => $property->id]);
    $next = app(NextDocumentNumber::class);

    expect($next($property, DocumentType::PosOrder)->number)->toBe(1)
        ->and($next($property, DocumentType::PosOrder)->number)->toBe(2)
        ->and($next($property, DocumentType::PosShift)->number)->toBe(1);

    expect(DocumentSequence::query()->where('property_id', $property->id)->pluck('type')->map(fn ($type) => $type->value)->sort()->values()->all())->toBe(['pos_order', 'pos_shift']);
});
