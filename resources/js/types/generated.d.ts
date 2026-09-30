declare namespace App {
namespace Data {
namespace Catalogues {
export type ChargeItemData = {
id: number,
name: string,
code: string,
category: App.Enums.TransactionCategory,
isStarter: boolean,
description: string | null,
defaultAmount: string | null,
chargingUnit: App.Enums.ChargingUnit,
allowOverride: boolean,
active: boolean,
taxIds: number[],
};
export type ChargeItemTaxData = {
id: number,
name: string,
rateType: App.Enums.TaxRateType,
rate: string,
isInclusive: boolean,
foreignGuestsOnly: boolean,
enabled: boolean,
};
export type DiscountChargeData = {
id: number,
code: string,
name: string,
active: boolean,
};
export type DiscountData = {
id: number,
name: string,
code: string,
isStarter: boolean,
type: App.Enums.DiscountType,
value: string | null,
allowOverride: boolean,
scope: App.Enums.DiscountScope,
active: boolean,
transactionCodeIds: number[],
};
export type PaymentMethodData = {
id: number,
name: string,
type: App.Enums.PaymentMethodType,
typeLabel: string,
transactionCode: string,
code: string,
isSystem: boolean,
surchargeType: App.Enums.SurchargeType,
surchargeValue: string | null,
surchargeChargeItemId: number | null,
surchargeChargeItem: string | null,
requiresReference: boolean,
active: boolean,
};
export type RoomRevenuePreviewData = {
sampleAmount: string,
refusal: string | null,
localNet: string | null,
foreignNet: string | null,
lines: App.Data.Catalogues.RoomRevenuePreviewLineData[],
localTotal: string | null,
foreignTotal: string | null,
};
export type RoomRevenuePreviewLineData = {
taxId: number,
name: string,
inclusive: boolean,
localAmount: string | null,
foreignAmount: string | null,
};
export type SurchargeChargeItemData = {
id: number,
label: string,
active: boolean,
};
export type TaxData = {
id: number,
name: string,
code: string,
rateType: App.Enums.TaxRateType,
rate: string,
isInclusive: boolean,
foreignGuestsOnly: boolean,
registrationNumber: string | null,
enabled: boolean,
appliesTo: string[],
postingTransactionCode: string,
};
}
namespace Meals {
export type MealEntitlementData = {
date: string,
mealPeriodId: number,
guests: number,
};
export type MealInclusionData = {
mealPeriodId: number,
mealPeriodStartsAt: string,
startsOn: App.Enums.InclusionStart,
endsOn: App.Enums.InclusionEnd,
};
export type MealPeriodData = {
id: number,
name: string,
startsAt: string,
endsAt: string,
active: boolean,
};
}
namespace Navigation {
export type NavContextData = {
key: App.Enums.NavigationContext,
label: string,
icon: string,
shortcut: string,
href: string,
groups: App.Data.Navigation.NavGroupData[],
};
export type NavGroupData = {
label: string,
items: App.Data.Navigation.NavItemData[],
};
export type NavItemData = {
title: string,
href: string,
icon: string,
external: boolean,
active: boolean,
};
export type StaffNavigationData = {
active: App.Enums.NavigationContext | null,
contexts: App.Data.Navigation.NavContextData[],
};
}
namespace Notifications {
export type NotificationChannelPreferenceData = {
channel: App.Enums.NotificationChannel,
label: string,
supported: boolean,
enabled: boolean,
editable: boolean,
disabledReason: string | null,
};
export type NotificationPreferenceData = {
type: App.Enums.NotificationType,
label: string,
channels: App.Data.Notifications.NotificationChannelPreferenceData[],
};
}
namespace Onboarding {
export type OwnerSetupData = {
name: string,
email: string,
propertyName: string,
};
}
namespace Platform {
export type AdminPropertyData = {
code: number,
name: string,
status: App.Data.Shared.BadgeData,
type: string | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
stateCode: string | null,
postalCode: string | null,
countryCode: string | null,
contactEmail: string | null,
contactPhone: string | null,
registrationNumber: string | null,
tin: string | null,
createdAt: string,
wentLiveAt: string | null,
suspendedAt: string | null,
staffCount: number,
ownerName: string | null,
ownerEmail: string | null,
};
export type AdminPropertyRowData = {
code: number,
name: string,
status: App.Data.Shared.BadgeData,
type: string | null,
city: string | null,
ownerName: string | null,
ownerEmail: string | null,
createdAt: string,
};
export type AdminPropertyStaffData = {
id: number,
name: string,
email: string,
role: string | null,
verified: boolean,
deactivated: boolean,
canSendSetupLink: boolean,
createdAt: string,
};
export type PlatformSettingsData = {
requireGoLiveApproval: boolean,
defaultDormStrategy: App.Enums.DormStrategy,
mediaDisk: App.Enums.MediaDisk,
};
export type PropertyFeatureData = {
feature: App.Enums.PlatformFeature,
label: string,
description: string,
active: boolean,
};
}
namespace Property {
export type PropertyBasicsData = {
name: string,
type: App.Enums.PropertyType | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
stateCode: string | null,
postalCode: string | null,
};
export type PropertySettingsData = {
currency: App.Enums.Currency,
timeZone: string,
defaultLocale: App.Enums.Locale,
checkInTime: string,
checkOutTime: string,
arrivalGraceMinutes: number,
autoAssignRooms: boolean,
auditTime: string,
autoAuditEnabled: boolean,
missedArrivalPolicy: App.Enums.MissedArrivalPolicy,
overdueDeparturePolicy: App.Enums.OverdueDeparturePolicy,
noShowCharge: App.Enums.NoShowCharge,
registrationCardScope: App.Enums.RegistrationCardScope,
cardRequiredForCheckIn: boolean,
idDocumentRetentionDays: number | null,
securityDepositAmount: string,
allowSupportAccess: boolean,
currencyAndTimeZoneLocked: boolean,
};
export type PropertySummaryData = {
code: number,
name: string,
status: App.Enums.PropertyStatus,
};
}
namespace Rooms {
export type BedData = {
id: number,
roomId: number,
label: string,
archived: boolean,
};
export type GeneratedRoomData = {
number: string,
group: string | null,
taken: boolean,
};
export type RoomBlockData = {
id: number,
roomId: number,
roomNumber: string,
roomTypeName: string,
roomGroupId: number | null,
bedId: number | null,
bedLabel: string | null,
reason: App.Enums.RoomBlockReason,
startsOn: string,
endsOn: string,
notes: string | null,
completedOn: string | null,
};
export type RoomBlockRoomData = {
id: number,
number: string,
roomTypeName: string,
roomGroupId: number | null,
sellsBeds: boolean,
beds: App.Data.Rooms.BedData[],
};
export type RoomData = {
id: number,
number: string,
roomTypeId: number,
roomGroupId: number | null,
archived: boolean,
};
export type RoomGroupData = {
id: number,
name: string,
};
export type RoomTypeData = {
id: number,
name: string,
description: string | null,
sellUnit: App.Enums.SellUnit,
maxAdults: number,
maxChildren: number,
baseOccupancy: number,
smokingAllowed: boolean,
petsAllowed: boolean,
archived: boolean,
};
}
namespace Shared {
export type BadgeData = {
value: string,
label: string,
tone: App.Enums.Tone,
};
}
namespace Staff {
export type InvitationPreviewData = {
email: string,
propertyName: string,
role: string,
};
export type PermissionOptionData = {
value: string,
label: string,
group: string,
};
export type RoleOptionData = {
value: string,
label: string,
};
export type RoleRowData = {
id: number,
name: string,
isDefault: boolean,
isLocked: boolean,
staffCount: number,
permissions: string[],
};
export type StaffInvitationData = {
id: number,
email: string,
role: string,
invitedBy: string | null,
lastSentAt: string,
expiresAt: string,
expired: boolean,
};
export type StaffMemberData = {
id: number,
name: string,
email: string,
roleId: number | null,
role: string | null,
isYou: boolean,
joinedAt: string,
deactivatedAt: string | null,
};
}
}
namespace Enums {
export type ChargingUnit = 'per_item' | 'per_night' | 'per_stay' | 'per_guest_night' | 'per_guest';
export type Currency = 'MYR';
export type DefaultMealPeriod = 'breakfast' | 'lunch' | 'dinner';
export type DefaultRoleEnum = 'owner' | 'manager' | 'front_desk' | 'housekeeping' | 'finance';
export type DiscountScope = 'room_only' | 'all_charges' | 'selected';
export type DiscountType = 'percent' | 'fixed' | 'open';
export type DormStrategy = 'bed_as_unit' | 'native_dorm';
export type HousekeepingStatus = 'clean' | 'dirty' | 'inspected';
export type InclusionEnd = 'departure_day' | 'night_before_departure';
export type InclusionStart = 'arrival_day' | 'day_after_arrival';
export type Locale = 'en' | 'ms';
export type MediaDisk = 'local' | 'r2';
export type MissedArrivalPolicy = 'mark_no_show' | 'keep_as_late_arrival';
export type NavigationContext = 'front-desk' | 'finance' | 'reports' | 'settings';
export type NoShowCharge = 'first_night' | 'full_stay' | 'none';
export type NotificationChannel = 'mail' | 'database';
export type NotificationType = 'night_audit_completed' | 'night_audit_exceptions' | 'night_audit_failed' | 'registration_card_link' | 'onboarding_reminder' | 'dorm_strategy_changed' | 'support_access_used';
export type OverdueDeparturePolicy = 'extend_one_night' | 'block';
export type OwnerSetupMethod = 'email_link' | 'initial_password';
export type PaymentMethodType = 'cash' | 'card' | 'bank_transfer' | 'e_wallet' | 'ota_collected' | 'direct_bill';
export type PermissionEnum = 'settings.manage' | 'staff.view' | 'staff.manage' | 'roles.manage' | 'rooms.view' | 'rooms.manage' | 'rooms.block' | 'notification.manage';
export type PlatformFeature = 'channel-manager';
export type PropertyStatus = 'onboarding' | 'pending_approval' | 'live' | 'suspended';
export type PropertyType = 'hotel' | 'hostel' | 'resort' | 'serviced_apartment' | 'homestay';
export type RegistrationCardScope = 'primary_only' | 'every_adult';
export type RoomBlockReason = 'maintenance' | 'owner_use' | 'out_of_order' | 'other';
export type SellUnit = 'room' | 'bed';
export type SurchargeType = 'none' | 'fixed' | 'percent';
export type SystemTransactionCode = 'room_revenue' | 'no_show_revenue' | 'cancellation_revenue' | 'late_checkout_revenue' | 'early_departure_revenue' | 'food_and_beverage_revenue' | 'laundry_revenue' | 'minibar_revenue' | 'fee_revenue' | 'sst_tax' | 'tourism_tax' | 'cash_payment' | 'card_payment' | 'bank_transfer_payment' | 'ewallet_payment' | 'ota_collected_payment' | 'direct_bill_payment' | 'security_deposit' | 'refund' | 'adjustment' | 'discount';
export type TaxRateType = 'percent' | 'flat';
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
export type TransactionCategory = 'room' | 'food_and_beverage' | 'laundry' | 'minibar' | 'fee' | 'tax' | 'discount' | 'cash' | 'card' | 'bank_transfer' | 'e_wallet' | 'ota_collected' | 'direct_bill' | 'deposit' | 'refund' | 'adjustment' | 'other';
export type TransactionKind = 'charge' | 'payment' | 'adjustment' | 'tax';
}
}
declare namespace Illuminate {
export type CursorPaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
path: string,
per_page: number,
next_cursor: string | null,
next_page_url: string | null,
prev_cursor: string | null,
prev_page_url: string | null,
},
};
export type CursorPaginatorInterface<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type LengthAwarePaginator<TKey, TValue> = {
data: TKey extends string ? Record<TKey, TValue> : TValue[],
links: {
url: string | null,
label: string,
active: boolean,
}[],
meta: {
total: number,
current_page: number,
first_page_url: string,
from: number | null,
last_page: number,
last_page_url: string,
next_page_url: string | null,
path: string,
per_page: number,
prev_page_url: string | null,
to: number | null,
},
};
export type LengthAwarePaginatorInterface<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
declare namespace Spatie {
namespace LaravelData {
export type CursorPaginatedDataCollection<TKey, TValue> = Illuminate.CursorPaginator<TKey, TValue>;
export type PaginatedDataCollection<TKey, TValue> = Illuminate.LengthAwarePaginator<TKey, TValue>;
}
}
