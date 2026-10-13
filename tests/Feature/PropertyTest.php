<?php

use App\Actions\Property\IssuePropertyCode;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('property codes are issued in order with the ES prefix', function () {
    $first = Property::factory()->create();
    $second = Property::factory()->create();

    expect($first->code)->toBe('ES10101')
        ->and($second->code)->toBe('ES10102');
});

test('each prefix has its own counter', function () {
    Property::factory()->create();

    DB::transaction(function () {
        expect(app(IssuePropertyCode::class)('AS'))->toBe('AS10101')
            ->and(app(IssuePropertyCode::class)('AS'))->toBe('AS10102')
            ->and(app(IssuePropertyCode::class)())->toBe('ES10102');
    });
});

test('the database rejects a malformed property code', function () {
    $property = Property::factory()->create();

    expect(fn () => DB::table('properties')->where('id', $property->id)->update(['code' => 'ES123']))
        ->toThrow(QueryException::class, 'properties_code_check');
});

test('the property code cannot be mass assigned', function () {
    $property = Property::factory()->create();

    $property->update(['code' => 'XX99999', 'name' => 'Renamed']);

    expect($property->refresh()->code)->toBe('ES10101')
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
