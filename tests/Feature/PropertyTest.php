<?php

use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('property codes come from the sequence in order', function () {
    $first = Property::factory()->create();
    $second = Property::factory()->create();

    expect($first->code)->toBeGreaterThanOrEqual(10101)
        ->and($second->code)->toBe($first->code + 1);
});

test('the property code cannot be mass assigned', function () {
    $property = Property::factory()->create();

    $property->update(['code' => 99999, 'name' => 'Renamed']);

    expect($property->refresh()->code)->not->toBe(99999)
        ->and($property->name)->toBe('Renamed');
});

test('an admin cannot belong to a property', function () {
    $admin = User::factory()->admin()->create();

    expect(fn () => DB::table('users')->where('id', $admin->id)->update(['property_id' => Property::factory()->create()->id]))
        ->toThrow(QueryException::class, 'users_property_or_admin_check');
});

test('a staff user must belong to a property', function () {
    $staff = User::factory()->create();

    expect($staff->property)->toBeInstanceOf(Property::class)
        ->and(fn () => DB::table('users')->where('id', $staff->id)->update(['property_id' => null]))
        ->toThrow(QueryException::class, 'users_property_or_admin_check');
});

test('a property with users cannot be deleted', function () {
    $staff = User::factory()->create();

    expect(fn () => $staff->property->delete())->toThrow(QueryException::class);
});
