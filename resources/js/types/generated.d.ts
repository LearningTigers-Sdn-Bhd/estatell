declare namespace App {
namespace Data {
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
};
export type AdminPropertyRowData = {
code: number,
name: string,
status: App.Data.Shared.BadgeData,
type: string | null,
city: string | null,
staffCount: number,
createdAt: string,
};
export type AdminPropertyStaffData = {
id: number,
name: string,
email: string,
verified: boolean,
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
export type RoleOptionData = {
value: string,
label: string,
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
role: string | null,
isYou: boolean,
joinedAt: string,
};
}
}
namespace Enums {
export type Currency = 'MYR';
export type DefaultRoleEnum = 'owner' | 'manager' | 'front_desk' | 'housekeeping' | 'accounts';
export type DormStrategy = 'bed_as_unit' | 'native_dorm';
export type Locale = 'en' | 'ms';
export type MediaDisk = 'local' | 'r2';
export type MissedArrivalPolicy = 'mark_no_show' | 'keep_as_late_arrival';
export type NavigationContext = 'front-desk' | 'finance' | 'reports' | 'settings';
export type NoShowCharge = 'first_night' | 'full_stay' | 'none';
export type OverdueDeparturePolicy = 'extend_one_night' | 'block';
export type PermissionEnum = 'settings.manage' | 'staff.view' | 'staff.manage' | 'roles.manage';
export type PlatformFeature = 'channel-manager';
export type PropertyStatus = 'onboarding' | 'pending_approval' | 'live' | 'suspended';
export type PropertyType = 'hotel' | 'hostel' | 'resort' | 'serviced_apartment' | 'homestay';
export type RegistrationCardScope = 'primary_only' | 'every_adult';
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
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
