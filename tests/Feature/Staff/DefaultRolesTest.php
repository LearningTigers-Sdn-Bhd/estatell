<?php

use App\Actions\Staff\SeedDefaultRoles;
use App\Actions\Staff\SyncPermissions;
use App\Enums\DefaultRoleEnum;
use App\Enums\PermissionEnum;
use App\Models\Property;
use App\Models\Role;
use Spatie\Permission\Models\Permission;

function defaultRole(Property $property, DefaultRoleEnum $defaultRole): Role
{
    return Role::query()->where('property_id', $property->id)->where('default_role', $defaultRole)->firstOrFail();
}

/**
 * @return list<string>
 */
function roleNames(Role $role): array
{
    return $role->permissions()->orderBy('name')->pluck('name')->all();
}

test('migrations create a permission row for every permission case', function () {
    expect(Permission::query()->orderBy('name')->pluck('name')->all())
        ->toEqualCanonicalizing(array_map(fn (PermissionEnum $permission) => $permission->value, PermissionEnum::cases()));
});

test('seeding creates the default roles with their default permissions', function () {
    $property = Property::factory()->create();

    app(SeedDefaultRoles::class)($property);

    expect(Role::query()->where('property_id', $property->id)->count())->toBe(5)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Owner)))->toEqualCanonicalizing(['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.manual', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'folio.view-manual-entries', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.anonymize', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'notification.manage', 'payment.record', 'payment.refund', 'payment.refund-other-method', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'roles.manage', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'settings.ota-lists', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.manual', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'folio.view-manual-entries', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'payment.record', 'payment.refund', 'payment.refund-other-method', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'settings.manage', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::FrontDesk)))->toBe(['company.view', 'folio.close', 'folio.discount', 'folio.post-charge', 'folio.print-voucher', 'front-desk.check-in', 'front-desk.check-out', 'guests.manage', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'payment.record', 'payment.refund', 'rates.view', 'registration-card.send-link', 'registration-card.sign', 'reservations.manage', 'reservations.view', 'rooms.block'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Housekeeping)))->toBe(['housekeeping.update', 'housekeeping.view', 'rates.view'])
        ->and(defaultRole($property, DefaultRoleEnum::FrontDesk)->name)->toBe('Front desk');
});

test('each property gets its own roles', function () {
    [$first, $second] = Property::factory()->count(2)->create();

    app(SeedDefaultRoles::class)($first);
    app(SeedDefaultRoles::class)($second);

    expect(defaultRole($first, DefaultRoleEnum::Owner)->id)->not->toBe(defaultRole($second, DefaultRoleEnum::Owner)->id);
});

test('seeding again keeps the roles and the owner edits', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    defaultRole($property, DefaultRoleEnum::Manager)->revokePermissionTo(PermissionEnum::SettingsManage->value);

    app(SeedDefaultRoles::class)($property);

    expect(Role::query()->where('property_id', $property->id)->count())->toBe(5)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.manual', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'folio.view-manual-entries', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'payment.record', 'payment.refund', 'payment.refund-other-method', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'staff.manage', 'staff.view']);
});

test('syncing grants only new permissions to default roles and never to custom roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $custom = Role::query()->create(['property_id' => $property->id, 'name' => 'Night manager']);
    defaultRole($property, DefaultRoleEnum::Manager)->revokePermissionTo(PermissionEnum::SettingsManage->value);
    Permission::query()->where('name', PermissionEnum::StaffManage->value)->delete();

    app(SyncPermissions::class)();
    app(SyncPermissions::class)();

    expect(Permission::query()->where('name', PermissionEnum::StaffManage->value)->count())->toBe(1)
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Owner)))->toContain('staff.manage')
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::Manager)))->toEqualCanonicalizing(['city-ledger.allocate', 'city-ledger.credit-note', 'city-ledger.invoice', 'city-ledger.payment', 'company.credit-override', 'company.manage', 'company.view', 'folio.close', 'folio.discount', 'folio.discount-open', 'folio.manual', 'folio.post-charge', 'folio.print-voucher', 'folio.reopen', 'folio.reverse', 'folio.transfer', 'folio.view-manual-entries', 'front-desk.check-in', 'front-desk.check-in-without-card', 'front-desk.check-out', 'front-desk.undo-check-in', 'front-desk.undo-check-out', 'front-desk.waive-fees', 'guests.blacklist', 'guests.manage', 'guests.merge', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'night-audit.force', 'night-audit.receive-report', 'night-audit.run', 'payment.record', 'payment.refund', 'payment.refund-other-method', 'rates.manage', 'rates.override-restrictions', 'rates.view', 'registration-card.manage-terms', 'registration-card.send-link', 'registration-card.sign', 'registration-card.void', 'reports.view', 'reservations.change-price', 'reservations.manage', 'reservations.view', 'rooms.block', 'rooms.manage', 'rooms.view', 'staff.manage', 'staff.view'])
        ->and(roleNames(defaultRole($property, DefaultRoleEnum::FrontDesk)))->toBe(['company.view', 'folio.close', 'folio.discount', 'folio.post-charge', 'folio.print-voucher', 'front-desk.check-in', 'front-desk.check-out', 'guests.manage', 'guests.view', 'guests.view-documents', 'housekeeping.inspect', 'housekeeping.update', 'housekeeping.view', 'payment.record', 'payment.refund', 'rates.view', 'registration-card.send-link', 'registration-card.sign', 'reservations.manage', 'reservations.view', 'rooms.block'])
        ->and(roleNames($custom))->toBe([]);
});

test('reservation permissions go to the right default roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $roles = fn (string $permission): array => collect(DefaultRoleEnum::cases())
        ->filter(fn (DefaultRoleEnum $role): bool => in_array($permission, roleNames(defaultRole($property, $role)), true))
        ->map(fn (DefaultRoleEnum $role): string => $role->value)
        ->values()
        ->all();

    expect($roles(PermissionEnum::ReservationsView->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::ReservationsManage->value))->toBe(['owner', 'manager', 'front_desk'])
        ->and(PermissionEnum::ReservationsView->group())->toBe('Reservations');
});

test('folio permissions go to the right default roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $roles = fn (string $permission): array => collect(DefaultRoleEnum::cases())
        ->filter(fn (DefaultRoleEnum $role): bool => in_array($permission, roleNames(defaultRole($property, $role)), true))
        ->map(fn (DefaultRoleEnum $role): string => $role->value)
        ->values()
        ->all();

    expect($roles(PermissionEnum::FolioPostCharge->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::FolioReverse->value))->toBe(['owner', 'manager', 'finance'])
        ->and($roles(PermissionEnum::FolioTransfer->value))->toBe(['owner', 'manager', 'finance'])
        ->and(PermissionEnum::FolioReverse->group())->toBe('Folio');
});

test('folio discount permissions go to the right default roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $roles = fn (string $permission): array => collect(DefaultRoleEnum::cases())
        ->filter(fn (DefaultRoleEnum $role): bool => in_array($permission, roleNames(defaultRole($property, $role)), true))
        ->map(fn (DefaultRoleEnum $role): string => $role->value)
        ->values()
        ->all();

    expect($roles(PermissionEnum::FolioDiscount->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::FolioDiscountOpen->value))->toBe(['owner', 'manager', 'finance'])
        ->and(PermissionEnum::FolioDiscountOpen->group())->toBe('Folio');
});

test('folio close and reopen permissions go to the right default roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $roles = fn (string $permission): array => collect(DefaultRoleEnum::cases())
        ->filter(fn (DefaultRoleEnum $role): bool => in_array($permission, roleNames(defaultRole($property, $role)), true))
        ->map(fn (DefaultRoleEnum $role): string => $role->value)
        ->values()
        ->all();

    expect($roles(PermissionEnum::FolioClose->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::FolioReopen->value))->toBe(['owner', 'manager', 'finance'])
        ->and(PermissionEnum::FolioReopen->group())->toBe('Folio');
});

test('payment permissions go to the right default roles', function () {
    $property = Property::factory()->create();
    app(SeedDefaultRoles::class)($property);
    $roles = fn (string $permission): array => collect(DefaultRoleEnum::cases())
        ->filter(fn (DefaultRoleEnum $role): bool => in_array($permission, roleNames(defaultRole($property, $role)), true))
        ->map(fn (DefaultRoleEnum $role): string => $role->value)
        ->values()
        ->all();

    expect($roles(PermissionEnum::PaymentRecord->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::PaymentRefund->value))->toBe(['owner', 'manager', 'front_desk', 'finance'])
        ->and($roles(PermissionEnum::PaymentRefundOtherMethod->value))->toBe(['owner', 'manager', 'finance'])
        ->and(PermissionEnum::PaymentRefund->group())->toBe('Payments');
});
