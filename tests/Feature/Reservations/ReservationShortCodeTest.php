<?php

use App\Models\Property;
use App\Models\Reservation;
use App\Services\Reservations\ReservationShortCode;
use Illuminate\Database\QueryException;
use Random\Engine;
use Random\Randomizer;

/**
 * A code generator that always picks the same code.
 */
function repeatingShortCode(): ReservationShortCode
{
    return new ReservationShortCode(new Randomizer(new class implements Engine
    {
        public function generate(): string
        {
            return "\0\0\0\0\0\0\0\0";
        }
    }));
}

test('a new code has six characters and no look-alikes', function () {
    expect(app(ReservationShortCode::class)->generate(Property::factory()->create()))->toMatch('/^[A-HJKMNP-Z2-9]{6}$/');
});

test('every reservation gets its own code through the factory', function () {
    $property = Property::factory()->create();
    $codes = Reservation::factory()->count(3)->create(['property_id' => $property->id])->pluck('short_code');

    expect($codes->unique())->toHaveCount(3);
});

test('two properties can use the same code, but one property cannot', function () {
    [$first, $second] = Property::factory()->count(2)->create();
    Reservation::factory()->create(['property_id' => $first->id, 'short_code' => 'K7M2QX']);

    expect(Reservation::factory()->create(['property_id' => $second->id, 'short_code' => 'K7M2QX'])->short_code)->toBe('K7M2QX');
    expect(fn () => Reservation::factory()->create(['property_id' => $first->id, 'short_code' => 'K7M2QX']))->toThrow(QueryException::class);
});

test('a code in use at another property is still free', function () {
    [$first, $second] = Property::factory()->count(2)->create();
    $generator = repeatingShortCode();
    Reservation::factory()->create(['property_id' => $first->id, 'short_code' => $generator->generate($first)]);

    expect($generator->generate($second))->toHaveLength(6);
});

test('it stops with an error after five codes in use, rather than saving a duplicate', function () {
    $property = Property::factory()->create();
    $generator = repeatingShortCode();
    Reservation::factory()->create(['property_id' => $property->id, 'short_code' => $generator->generate($property)]);

    expect(fn () => $generator->generate($property))->toThrow(RuntimeException::class);
});

test('the migration gives existing reservations their own codes', function () {
    $property = Property::factory()->create();
    $ids = Reservation::factory()->count(3)->create(['property_id' => $property->id])->pluck('id');
    $migration = include database_path('migrations/2026_11_06_064811_add_short_code_to_reservations_table.php');

    $migration->down();
    $migration->up();

    $codes = Reservation::query()->whereIn('id', $ids)->pluck('short_code');

    expect($codes->unique())->toHaveCount(3);
    $codes->each(fn (string $code) => expect($code)->toMatch('/^[A-HJKMNP-Z2-9]{6}$/'));
});
