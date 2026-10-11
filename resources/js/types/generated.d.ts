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
export type RoomRevenuePolicyData = {
chargeMode: App.Enums.PolicyChargeMode,
amount: string | null,
percent: string | null,
percentBasis: App.Enums.PolicyRateBasis | null,
appliesTo: App.Enums.PolicyScope,
freeUntilDays: number | null,
ownTaxes: boolean,
taxIds: number[],
textEn: string,
textMs: string,
};
export type RoomRevenuePolicyPreviewData = {
sampleNight: string,
sampleNights: number,
lines: App.Data.Catalogues.RoomRevenuePolicyPreviewLineData[],
};
export type RoomRevenuePolicyPreviewLineData = {
label: string,
free: boolean,
net: string | null,
localTotal: string | null,
foreignTotal: string | null,
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
collectAtCheckIn: boolean,
registrationNumber: string | null,
enabled: boolean,
appliesTo: string[],
postingTransactionCode: string,
};
}
namespace CityLedger {
export type AgingReportData = {
asAt: string,
rows: App.Data.CityLedger.AgingRowData[],
totals: App.Data.CityLedger.AgingRowData,
};
export type AgingRowData = {
companyId: number | null,
companyName: string,
current: string,
days1to30: string,
days31to60: string,
days61to90: string,
over90: string,
credit: string,
total: string,
};
export type ArAllocationRowData = {
id: number,
paymentId: number,
companyId: number,
receiptNumber: string,
receivedOn: string,
amount: string,
isReversed: boolean,
reversalReason: string | null,
canReverse: boolean,
};
export type ArInvoiceData = {
row: App.Data.CityLedger.ArInvoiceRowData,
reason: string | null,
creditForNumber: string | null,
creditForId: number | null,
folioNumber: string | null,
reservationId: number | null,
reservationReference: string | null,
credited: string,
creditNotes: App.Data.CityLedger.ArInvoiceRowData[],
lines: App.Data.CityLedger.ArInvoiceLineData[],
allocations: App.Data.CityLedger.ArAllocationRowData[],
creditBlockedBy: App.Data.CityLedger.ArAllocationRowData[],
canVoid: boolean,
canCreditNote: boolean,
};
export type ArInvoiceLineData = {
postingDate: string,
description: string,
amount: string,
};
export type ArInvoiceRowData = {
id: number,
number: string,
companyId: number,
companyName: string,
type: App.Enums.ArInvoiceType,
typeLabel: string,
issuedOn: string,
dueOn: string | null,
amount: string,
balance: string | null,
status: App.Data.Shared.BadgeData,
overdue: boolean,
};
export type ArPaymentMethodOptionData = {
id: number,
name: string,
requiresReference: boolean,
};
export type ArPaymentReferenceCheckData = {
warning: string | null,
};
export type ArPaymentRowData = {
id: number,
receiptNumber: string,
receivedOn: string,
methodName: string,
reference: string | null,
notes: string | null,
amount: string,
unallocated: string,
isVoided: boolean,
canAllocate: boolean,
canVoid: boolean,
};
export type CompanyData = {
id: number,
name: string,
type: App.Enums.CompanyType,
typeLabel: string,
brn: string | null,
tin: string | null,
sstRegistrationNumber: string | null,
billingAddressLine1: string | null,
billingAddressLine2: string | null,
billingCity: string | null,
billingStateCode: string | null,
billingPostalCode: string | null,
billingCountryCode: string | null,
billingAddress: string | null,
profileManagedByCompany: boolean,
contactEmail: string | null,
contactPhone: string | null,
creditLimit: string | null,
paymentTermsDays: number | null,
directBillEnabled: boolean,
status: App.Data.Shared.BadgeData,
createdAt: string,
credit: App.Data.CityLedger.CreditExposureData | null,
};
export type CompanyOptionData = {
id: number,
name: string,
};
export type CompanyRowData = {
id: number,
name: string,
type: App.Enums.CompanyType,
typeLabel: string,
contactEmail: string | null,
creditLimit: string | null,
paymentTermsDays: number | null,
status: App.Data.Shared.BadgeData,
overLimit: boolean,
};
export type CreditExposureData = {
openAr: string,
inHouse: string,
exposure: string,
limit: string | null,
headroom: string | null,
overLimit: boolean,
};
export type NewArPaymentData = {
paymentMethodId: number,
amount: string,
idempotencyKey: string,
receivedOn: string | null,
reference: string | null,
notes: string | null,
};
export type OpenArInvoiceData = {
id: number,
number: string,
dueOn: string | null,
balance: string,
};
export type StatementData = {
from: string,
to: string,
openingBalance: string,
lines: App.Data.CityLedger.StatementLineData[],
closingBalance: string,
aging: App.Data.CityLedger.AgingRowData,
};
export type StatementLineData = {
date: string,
kind: string,
kindLabel: string,
number: string,
description: string | null,
amount: string,
balance: string,
};
}
namespace Folio {
export type DiscountQuoteData = {
lines: App.Data.Folio.DiscountQuoteLineData[],
subtotal: string,
amount: string | null,
amountRefusal: string | null,
fingerprint: string,
};
export type DiscountQuoteLineData = {
folioLineId: number,
description: string,
netAmount: string,
share: string | null,
};
export type FolioData = {
key: string,
kind: string,
name: string,
status: App.Data.Shared.BadgeData | null,
number: string | null,
invoiceNumber: string | null,
balance: string | null,
projectedTotal: string,
lineCount: number,
rows: App.Data.Folio.FolioRowData[],
canClose: boolean,
canReopen: boolean,
canApplyDiscount: boolean,
canPostCharge: boolean,
postUnits: App.Data.Folio.FolioOptionData[],
transferTargets: App.Data.Folio.FolioOptionData[],
canRecordPayment: boolean,
paymentUnitId: number | null,
canPrintVoucher: boolean,
canSettleToCityLedger: boolean,
payerCompanyName: string | null,
paymentPurposes: {
value: string,
label: string,
}[],
defaultPaymentPurpose: string | null,
closedNotice: string | null,
settleCreditWarning: string | null,
canPrintInvoice: boolean,
};
export type FolioDiscountOptionData = {
id: number,
name: string,
type: string,
typeLabel: string,
value: string | null,
scopeLabel: string,
canEnterAmount: boolean,
};
export type FolioDiscountQuoteResponseData = {
quote: App.Data.Folio.DiscountQuoteData,
error: string | null,
};
export type FolioOptionData = {
id: number,
label: string,
};
export type FolioRailItemData = {
key: string,
kind: string,
name: string,
subtitle: string | null,
status: App.Data.Shared.BadgeData | null,
balance: string | null,
};
export type FolioRowData = {
key: string,
state: string,
date: string,
description: string,
level: number,
unit: string | null,
amount: string,
tags: App.Data.Shared.BadgeData[],
lineId: number | null,
actions: string[],
note: string | null,
paymentId: number | null,
refundable: string | null,
hasReceipt: boolean,
kind: App.Enums.TransactionKind | null,
printable: boolean,
};
export type NewChargeData = {
chargeItemId: number | null,
transactionCodeId: number | null,
description: string | null,
quantity: number,
unitAmount: string,
idempotencyKey: string | null,
unitAddOnId: number | null,
};
export type RoutingRuleRowData = {
id: number,
unit: string,
charges: string,
target: string,
sourceLabel: string,
canRemove: boolean,
};
export type RoutingRulesData = {
rules: App.Data.Folio.RoutingRuleRowData[],
categories: {
value: string,
label: string,
}[],
targets: App.Data.Folio.FolioOptionData[],
};
}
namespace Guests {
export type GuestData = {
id: number,
fullName: string,
email: string | null,
phone: string | null,
nationalityCode: string | null,
nationalityName: string | null,
dateOfBirth: string | null,
gender: string | null,
genderLabel: string | null,
preferredLocale: string | null,
preferredLocaleLabel: string | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
state: string | null,
postalCode: string | null,
countryCode: string | null,
countryName: string | null,
isVip: boolean,
blacklistedAt: string | null,
blacklistReason: string | null,
anonymizedAt: string | null,
tags: App.Data.Guests.TagData[],
createdAt: string,
};
export type GuestIdentityDocumentData = {
id: number,
type: string,
typeLabel: string,
maskedNumber: string,
number: string | null,
issuingCountryCode: string | null,
issuingCountryName: string | null,
expiresOn: string | null,
isExpired: boolean,
hasFrontScan: boolean,
hasBackScan: boolean,
};
export type GuestMatchData = {
id: number,
fullName: string,
email: string | null,
phone: string | null,
isVip: boolean,
isBlacklisted: boolean,
reasons: string[],
isStrong: boolean,
profile: App.Data.Guests.GuestProfileData,
};
export type GuestNoteData = {
id: number,
body: string,
authorName: string | null,
createdAt: string,
};
export type GuestOptionData = {
id: number,
fullName: string,
phone: string | null,
email: string | null,
isVip: boolean,
isBlacklisted: boolean,
profile: App.Data.Guests.GuestProfileData,
};
export type GuestProfileData = {
nationalityCode: string | null,
dateOfBirth: string | null,
gender: string | null,
preferredLocale: string | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
state: string | null,
postalCode: string | null,
countryCode: string | null,
blacklistReason: string | null,
};
export type GuestRowData = {
id: number,
fullName: string,
email: string | null,
phone: string | null,
nationalityName: string | null,
isVip: boolean,
isBlacklisted: boolean,
isAnonymized: boolean,
tags: string[],
createdAt: string,
};
export type TagData = {
id: number,
name: string,
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
action: string | null,
};
export type StaffNavigationData = {
active: App.Enums.NavigationContext | null,
contexts: App.Data.Navigation.NavContextData[],
};
}
namespace NightAudit {
export type AuditStepData = {
step: App.Enums.AuditStep,
label: string,
status: App.Enums.AuditStepStatus | null,
processed: number,
failed: number,
error: string | null,
};
export type BusinessDateData = {
date: string,
status: App.Enums.BusinessDateStatus,
timeZone: string,
auditTime: string,
serverNow: string,
};
export type NightAuditProgressData = {
date: string,
status: App.Enums.AuditStatus,
updatedAt: string,
steps: App.Data.NightAudit.AuditStepData[],
openExceptions: number,
};
export type NightAuditReservationData = {
reservation: App.Data.Reservations.ReservationData,
units: App.Data.Reservations.ReservationUnitData[],
paymentOptions: App.Data.Payments.PaymentFormOptionsData | null,
canWaiveFees: boolean,
canAssignRoom: boolean,
currency: App.Enums.Currency | null,
};
export type NightAuditSheetData = {
date: string | null,
nextDate: string | null,
status: App.Enums.BusinessDateStatus | null,
blockers: App.Data.NightAudit.ReadinessGroupData[],
warnings: App.Data.NightAudit.ReadinessGroupData[],
audit: App.Data.NightAudit.NightAuditProgressData | null,
dateEnded: boolean,
summary: string,
canForce: boolean,
canViewPacket: boolean,
};
export type ReadinessActionData = {
kind: string,
label: string,
url: string,
unitId: number | null,
confirm: string | null,
dialog: string | null,
icon: string,
};
export type ReadinessGroupData = {
check: App.Enums.AuditCheck,
blocker: boolean,
reservationId: number,
reference: string,
guest: string,
headline: string,
dates: string,
consequence: string | null,
reasons: string[],
actions: App.Data.NightAudit.ReadinessActionData[],
units: App.Data.NightAudit.ReadinessUnitData[],
openUnits: boolean,
};
export type ReadinessUnitData = {
unitId: number,
guest: string,
detail: string,
reason: string | null,
actions: App.Data.NightAudit.ReadinessActionData[],
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
export type GoLiveCheckData = {
key: string,
label: string,
done: boolean,
};
export type OwnerSetupData = {
name: string,
email: string,
propertyName: string,
};
}
namespace Payments {
export type NewPaymentData = {
paymentMethodId: number,
purpose: App.Enums.PaymentPurpose,
amount: string,
reference: string | null,
idempotencyKey: string,
};
export type PaymentFormOptionsData = {
methods: App.Data.Payments.PaymentMethodOptionData[],
};
export type PaymentMethodOptionData = {
id: number,
name: string,
requiresReference: boolean,
};
export type PaymentQuoteData = {
amount: string | null,
surcharge: string | null,
surchargeTax: string | null,
total: string | null,
error: string | null,
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
export type PrintSettingsData = {
font: App.Enums.PdfFont,
fontSize: App.Enums.PdfFontSize,
footerText: string | null,
showLogo: boolean,
showAddress: boolean,
showContact: boolean,
showRegistrationNumber: boolean,
showTin: boolean,
showUnitOnLines: boolean,
nightlyBreakdown: boolean,
taxBreakdown: boolean,
showAmountInWords: boolean,
showCancellationPolicy: boolean,
showSignatureLines: boolean,
bankDetails: string | null,
taxIds: number[],
};
export type PrintTaxData = {
id: number,
name: string,
code: string,
registrationNumber: string | null,
enabled: boolean,
};
export type PropertyAgeGroupData = {
id: number,
label: string,
minAge: number,
maxAge: number,
};
export type PropertyBasicsData = {
name: string,
type: App.Enums.PropertyType | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
stateCode: string | null,
postalCode: string | null,
};
export type PropertyProfileData = {
name: string,
typeLabel: string | null,
countryName: string | null,
addressLine1: string | null,
addressLine2: string | null,
city: string | null,
stateCode: string | null,
postalCode: string | null,
contactPhone: string | null,
contactEmail: string | null,
registrationNumber: string | null,
tin: string | null,
logoUrl: string | null,
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
weekendNights: App.Enums.WeekendNights,
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
export type WeekendNightsOptionData = {
value: string,
label: string,
isoWeekdays: number[],
};
}
namespace Rates {
export type NewRatePlanRoomTypeData = {
id: number,
name: string,
sellUnit: App.Enums.SellUnit,
sellUnitLabel: string,
fitsBases: App.Enums.PricingBasis[],
};
export type PackagePriceData = {
id: number,
roomTypeId: number,
arrivalFrom: string,
arrivalTo: string,
amount: string,
};
export type PackagePriceRoomTypeData = {
id: number,
name: string,
sellUnitLabel: string,
};
export type RateBulkPlanData = {
id: number,
name: string,
pricingBasis: App.Enums.PricingBasis,
derived: boolean,
followsName: string | null,
hasOwnPrices: boolean,
};
export type RateCalendarAvailabilityData = {
date: string,
units: number,
sold: number,
open: number,
};
export type RateCalendarCellData = {
date: string,
amount: string | null,
minStay: number | null,
maxStay: number | null,
closedToArrival: boolean,
closedToDeparture: boolean,
stopSell: boolean,
occupancy: App.Data.Rates.RateCalendarOccupancyData[],
arrivalNotAllowed: boolean,
};
export type RateCalendarData = {
from: string,
to: string,
dates: string[],
roomTypes: App.Data.Rates.RateCalendarRoomTypeData[],
ending: App.Data.Rates.RateCalendarEndingData[],
};
export type RateCalendarEndingData = {
ratePlanId: number,
ratePlanName: string,
roomTypeNames: string[],
};
export type RateCalendarInclusionData = {
name: string,
quantity: number,
chargingUnit: string,
};
export type RateCalendarOccupancyData = {
adults: number,
amount: string,
};
export type RateCalendarPlanData = {
id: number,
name: string,
pricingBasis: App.Enums.PricingBasis,
derived: boolean,
followsName: string | null,
fixedNights: number | null,
packageUnit: App.Enums.PackageUnit | null,
inclusions: App.Data.Rates.RateCalendarInclusionData[],
cells: App.Data.Rates.RateCalendarCellData[],
};
export type RateCalendarRoomTypeData = {
id: number,
name: string,
baseOccupancy: number,
maxAdults: number,
plans: App.Data.Rates.RateCalendarPlanData[],
availability: App.Data.Rates.RateCalendarAvailabilityData[],
};
export type RatePlanChildPriceData = {
ageGroupId: number,
label: string,
minAge: number,
maxAge: number,
pricing: App.Enums.AgeBandPricing,
value: string | null,
};
export type RatePlanData = {
id: number,
name: string,
description: string | null,
pricingBasis: App.Enums.PricingBasis,
fixedNights: number | null,
packageUnit: App.Enums.PackageUnit | null,
usesChildPrices: boolean,
family: string,
pricingLabel: string,
familyTone: App.Enums.Tone,
pricingTone: App.Enums.Tone,
extraAdultAmount: string,
extraChildAmount: string,
singleSupplement: string,
archived: boolean,
status: App.Data.Shared.BadgeData,
isDerived: boolean,
checkChildPrices: boolean,
roomTypeIds: number[],
roomTypeNames: string[],
};
export type RatePlanDerivationData = {
parentRatePlanId: number | null,
derivation: App.Enums.RateDerivation | null,
direction: string | null,
value: string | null,
parents: App.Data.Rates.RatePlanParentOptionData[],
cannotFollowReason: string | null,
};
export type RatePlanDerivationPreviewRowData = {
roomTypeId: number,
name: string,
parentAmount: string | null,
derivedAmount: string | null,
};
export type RatePlanInclusionChargeItemData = {
id: number,
name: string,
active: boolean,
chargingUnit: App.Enums.ChargingUnit,
defaultAmount: string | null,
};
export type RatePlanInclusionData = {
id: number,
chargeItemId: number,
mealPeriodId: number | null,
chargingUnit: App.Enums.ChargingUnit,
quantity: number,
allocatedAmount: string,
startsOn: App.Enums.InclusionStart,
endsOn: App.Enums.InclusionEnd,
};
export type RatePlanInclusionMealPeriodData = {
id: number,
name: string,
active: boolean,
defaultStartsOn: App.Enums.InclusionStart,
defaultEndsOn: App.Enums.InclusionEnd,
};
export type RatePlanParentDiscountsData = {
parentName: string,
};
export type RatePlanParentOptionData = {
id: number,
name: string,
};
export type RatePlanRoomTypeChoiceData = {
id: number,
name: string,
sellUnit: App.Enums.SellUnit,
fits: boolean,
};
export type RatePlanStayDiscountData = {
minNights: number,
discountType: App.Enums.StayDiscountType,
value: string,
fromNight: number,
};
export type RatePricingRoomTypeData = {
id: number,
name: string,
baseOccupancy: number,
maxAdults: number,
pricedUntil: string | null,
ending: boolean,
};
export type StayDiscountPreviewByNightData = {
nights: App.Data.Rates.StayDiscountPreviewNightData[],
total: string,
discount: string,
};
export type StayDiscountPreviewData = {
arrival: string,
nights: number,
stayLengths: number[],
ignoredRules: number,
rows: App.Data.Rates.StayDiscountPreviewRowData[],
};
export type StayDiscountPreviewNightData = {
amount: string,
discount: string,
unitAmount: string,
};
export type StayDiscountPreviewRowData = {
roomTypeId: number,
name: string,
adults: number,
children: number,
unit: string,
refusal: string | null,
stays: App.Data.Rates.StayDiscountPreviewStayData[],
byNight: App.Data.Rates.StayDiscountPreviewByNightData | null,
};
export type StayDiscountPreviewStayData = {
nights: number,
total: string,
discount: string,
nightAmounts: string[],
};
}
namespace Registration {
export type RegistrationCardDoneData = {
unitGuestId: number,
nextUnitGuestId: number | null,
nextGuestName: string | null,
nextPosition: number | null,
total: number,
locale: string,
labels: Record<string, string>,
};
export type RegistrationCardFieldData = {
field: string,
label: string,
group: string,
groupLabel: string,
visibility: string,
displayOnly: boolean,
canHide: boolean,
canRequire: boolean,
note: string | null,
};
export type RegistrationCardPageData = {
unitGuestId: number,
reservationId: number,
guestName: string,
unitLabel: string,
cardId: number,
number: string | null,
status: App.Data.Shared.BadgeData,
signedAt: string | null,
signerName: string | null,
voidReason: string | null,
locale: string,
localeOptions: {
value: string,
label: string,
}[],
fields: {
field: string,
label: string,
group: string,
visibility: string,
value: string | null,
}[],
values: Record<string, string | boolean | null>,
documents: {
id: number,
label: string,
}[],
termsText: string,
checklist: {
position: number,
text: string,
}[],
labels: Record<string, string>,
canVoid: boolean,
signedFields: {
label: string,
value: string | null,
}[] | null,
signedTicks: number[] | null,
draftFields: {
label: string,
value: string,
}[] | null,
cardDocumentId: number | null,
walkPosition: number | null,
walkTotal: number,
};
export type TermsDocumentData = {
id: number,
name: string,
isDefault: boolean,
canDelete: boolean,
};
export type TermsEditorData = {
documentId: number,
locale: string,
localeOptions: {
value: string,
label: string,
}[],
version: number | null,
body: string,
isDraft: boolean,
publishedAt: string | null,
publishedBy: string | null,
presetLabel: string | null,
newerTemplateBody: string | null,
placeholderValues: Record<string, string>,
history: App.Data.Registration.TermsVersionData[],
};
export type TermsVersionData = {
version: number,
body: string,
publishedAt: string,
publishedBy: string | null,
isTemplate: boolean,
};
}
namespace Reports {
export type ArrivalRow = {
key: number,
reservation: string,
guest: string,
phone: string | null,
arrival: string,
arrival_time: string,
nights: number,
unit: string,
room_type: string,
room: string | null,
guests: string,
adults: number,
children: number,
company: string | null,
status: App.Data.Shared.BadgeData,
balance: string,
};
export type DepartureRow = {
key: number,
reservation: string,
guest: string,
phone: string | null,
departure: string,
departure_time: string,
nights: number,
unit: string,
room_type: string,
room: string | null,
guests: string,
adults: number,
children: number,
company: string | null,
status: App.Data.Shared.BadgeData,
balance: string,
};
export type DepositLiabilityRow = {
key: number,
folio: string,
reservation: string,
payer: string,
arrival: string | null,
departure: string | null,
status: App.Data.Shared.BadgeData,
received: string,
refunded: string,
charged: string,
deposit: string,
held: string,
};
export type FolioLedgerRow = {
key: number,
date: string,
folio: string,
reservation: string,
guest: string,
code: string,
description: string,
kind: string,
staff: string | null,
amount: string,
};
export type InHouseRow = {
key: number,
reservation: string,
guest: string,
room: string | null,
arrival: string,
arrival_time: string,
departure: string,
departure_time: string,
guests: string,
adults: number,
children: number,
company: string | null,
balance: string,
};
export type MealCountRow = {
key: string,
date: string,
meal: string,
adults: number,
children: number,
total: number,
};
export type NightAuditHistoryRow = {
key: string,
date: string,
status: App.Data.Shared.BadgeData,
trigger: string,
run_by: string,
started: string,
started_date: string,
started_time: string,
completed: string,
completed_date: string,
completed_time: string,
forced: boolean,
forceReason: string | null,
exceptions: string,
exceptions_open: number,
exceptions_total: number,
occupancy: string | null,
revenue: string | null,
packet: boolean,
};
export type NonNationalGuestRow = {
key: number,
reservation: string,
guest: string,
nationality: string,
identity: string,
id_type: string,
id_number: string,
room: string | null,
arrival: string,
arrival_time: string,
departure: string,
departure_time: string,
nights: number,
guests: string,
adults: number,
children: number,
tourism_tax: string,
};
export type OccupancyRow = {
key: string,
date: string,
live: boolean,
available: number,
sold: number,
occupancy: string,
adr: string,
revpar: string,
revenue: string,
arrivals: number,
departures: number,
noshows: number,
guests: number,
};
export type OutstandingBalanceRow = {
key: number,
folio: string,
reservation: string,
payer: string,
arrival: string | null,
departure: string | null,
status: App.Data.Shared.BadgeData,
charges: string,
payments: string,
balance: string,
days: number | null,
};
export type PaymentRow = {
key: string,
date: string,
receipt: string,
payer: string,
method: string,
status: App.Data.Shared.BadgeData,
staff: string,
amount: string,
reference: string | null,
refund_of: string | null,
staff_id: number,
};
export type PoliceReportRow = {
key: string,
guest: string,
reservation: string,
room: string | null,
nationality: string,
gender: string,
date_of_birth: string | null,
identity: string,
id_type: string,
id_number: string,
address: string,
phone: string,
arrival: string,
arrival_time: string,
checked_in_at: string,
checked_in_date: string,
checked_in_time: string,
departure: string,
departure_time: string,
checked_out_at: string,
checked_out_date: string,
checked_out_time: string,
status: App.Data.Shared.BadgeData,
};
export type ReportBreakdown = {
title: string,
headings: string[],
rows: string[][],
total: string[],
subtotalRows: number[],
};
export type ReportColumn = {
key: string,
label: string,
align: App.Enums.ColumnAlign,
defaultVisible: boolean,
};
export type ReportFilters = {
from: string,
to: string,
group: App.Enums.ReportGroup | null,
code: string | null,
staff: number | null,
refundsOnly: boolean,
foreignOnly: boolean,
};
export type ReportLayoutColumnData = {
key: string,
label: string,
align: App.Enums.ColumnAlign,
visible: boolean,
defaultAlign: App.Enums.ColumnAlign,
};
export type ReportLayoutData = {
orientation: App.Enums.PageOrientation,
columns: App.Data.Reports.ReportLayoutColumnData[],
};
export type ReportTotals = {
figures: Record<string, number | string | null>,
};
export type RevenueRow = {
key: string,
group: App.Enums.ReportGroup,
period: string,
code: string,
description: string,
lines: number,
amount: string,
from: string,
to: string,
};
export type TaxRow = {
key: string,
group: App.Enums.ReportGroup,
period: string,
tax: string,
registration: string | null,
lines: number,
amount: string,
};
}
namespace Reservations {
export type AddUnitOptionsData = {
roomTypes: App.Data.Reservations.RoomTypeOptionData[],
ratePlans: App.Data.Reservations.RatePlanOptionData[],
};
export type BookingQuoteData = {
nights: App.Data.Reservations.BookingQuoteNightData[],
unitTotal: string | null,
lineTotal: string | null,
addOnsTotal: string,
isOverridden: boolean,
beds: number,
violations: string[],
available: number,
errors: Record<string, string>,
};
export type BookingQuoteNightData = {
date: string,
base: string,
extra: string,
supplement: string,
discount: string,
amount: string,
};
export type ChargeCodeOptionData = {
id: number,
code: string,
name: string,
};
export type ChargeItemOptionData = {
id: number,
name: string,
chargingUnit: string,
chargingUnitLabel: string,
defaultAmount: string | null,
allowOverride: boolean,
};
export type CheckInAllPreviewData = {
rows: {
unitId: number,
label: string,
guest: string | null,
room: string | null,
cardsMissing: number,
ready: boolean,
note: string | null,
}[],
canCheckInWithoutCard: boolean,
error: string | null,
};
export type CheckInCollectionData = {
taxes: {
name: string,
amount: string,
exempt: boolean,
}[],
taxesTotal: string,
taxesHidden: boolean,
deposit: string | null,
};
export type CheckOutCollectData = {
paymentMethodId: number,
amount: string,
reference: string | null,
idempotencyKey: string,
};
export type CollectNowData = {
taxes: boolean,
deposit: boolean,
paymentMethodId: number,
reference: string | null,
idempotencyKey: string,
};
export type DiscountOptionData = {
id: number,
name: string,
};
export type FreeUnitData = {
id: number,
label: string,
};
export type NewAddOnData = {
chargeItemId: number,
quantity: number | null,
unitAmount: string | null,
serviceDate: string | null,
};
export type NewBookingData = {
source: App.Enums.ReservationSource,
units: App.Data.Reservations.NewUnitData[],
guests: App.Data.Reservations.NewBookingGuestData[],
picks: App.Data.Reservations.UnitPickData[],
billingMode: App.Enums.BillingMode,
channelName: string | null,
externalReference: string | null,
groupName: string | null,
notes: string | null,
companyId: number | null,
creditOverrideReason: string | null,
};
export type NewBookingGuestData = {
guestId: number | null,
profile: Record<string, any>,
updateProfile: boolean,
unitIndex: number | null,
};
export type NewReservationData = {
bookerGuestId: number,
source: App.Enums.ReservationSource,
units: App.Data.Reservations.NewUnitData[],
billingMode: App.Enums.BillingMode,
channelName: string | null,
externalReference: string | null,
companyId: number | null,
groupName: string | null,
notes: string | null,
creditOverrideReason: string | null,
};
export type NewUnitData = {
roomTypeId: number,
ratePlanId: number,
arrivalDate: string,
departureDate: string,
adults: number,
childAges: number[],
beds: number,
discountId: number | null,
lateArrivalExpected: boolean,
overrideTotal: string | null,
overrideReason: string | null,
restrictionOverrideReason: string | null,
addOns: App.Data.Reservations.NewAddOnData[],
arrivalTime: string | null,
departureTime: string | null,
};
export type RatePlanOptionData = {
id: number,
name: string,
roomTypeIds: number[],
fixedNights: number | null,
companyIds: number[],
};
export type ReservationActivityData = {
id: number,
kind: string | null,
text: string,
reason: string | null,
causerName: string | null,
createdAt: string,
};
export type ReservationAddOnData = {
id: number,
unitId: number,
unitLabel: string,
chargeItem: string,
quantity: number,
serviceDate: string | null,
unitAmount: string,
total: string,
posted: boolean,
folioId: number | null,
folioLabel: string | null,
postedOn: string | null,
routedTo: string | null,
};
export type ReservationBookerData = {
id: number | null,
name: string,
isVip: boolean,
isBlacklisted: boolean,
phone: string | null,
email: string | null,
};
export type ReservationData = {
id: number,
reference: string,
title: string,
groupName: string | null,
isGroup: boolean,
isClosed: boolean,
status: App.Data.Shared.BadgeData,
firstArrival: string | null,
lastDeparture: string | null,
unitCount: number,
roomNights: number,
stayTotal: string,
balance: string | null,
billingMode: string,
billingModeLabel: string,
source: string,
sourceLabel: string,
channelName: string | null,
externalReference: string | null,
notes: string | null,
companyId: number | null,
companyName: string | null,
createdOn: string,
createdBy: string | null,
booker: App.Data.Reservations.ReservationBookerData,
};
export type ReservationGuestData = {
guestId: number | null,
name: string | null,
phone: string | null,
unitId: number | null,
unitLabel: string | null,
unitStatus: App.Data.Shared.BadgeData | null,
roles: string[],
cardUnitGuestId: number | null,
};
export type ReservationListItemData = {
id: number,
reference: string,
title: string,
bookerName: string,
bookerId: number | null,
isGroup: boolean,
isVip: boolean,
isBlacklisted: boolean,
isRepeat: boolean,
isCancelled: boolean,
status: App.Data.Shared.BadgeData,
source: string,
sourceLabel: string,
channelName: string | null,
externalReference: string | null,
unitCount: number,
adults: number,
children: number,
firstArrival: string | null,
lastDeparture: string | null,
nights: number,
roomTypes: string[],
bookedOn: string,
total: string,
paid: string,
billedToCompany: string,
balance: string,
roomBox: App.Data.Reservations.ReservationRoomBoxData,
};
export type ReservationNightData = {
date: string,
assigned: string | null,
ratePlan: string,
amount: string,
isOverridden: boolean,
};
export type ReservationRoomBoxData = {
state: App.Enums.ReservationRoomBoxState,
label: string,
tone: App.Enums.Tone,
tooltip: string | null,
numbers: string[],
more: number,
count: number,
isBed: boolean,
units: App.Data.Reservations.ReservationRoomBoxUnitData[],
};
export type ReservationRoomBoxUnitData = {
roomType: string,
assigned: string | null,
};
export type ReservationStatusCountData = {
value: string,
label: string,
count: number,
};
export type ReservationUnitData = {
id: number,
roomType: string,
ratePlan: string,
status: App.Data.Shared.BadgeData,
arrival: string,
departure: string,
arrivalTime: string,
departureTime: string,
nightCount: number,
adults: number,
children: number,
childAges: number[],
assigned: string[],
allNightsAssigned: boolean,
primaryGuest: string | null,
primaryGuestId: number | null,
total: string,
nights: App.Data.Reservations.ReservationNightData[],
priceOverrideReason: string | null,
priceOverrideBy: string | null,
actions: App.Data.Reservations.UnitActionData[],
};
export type ReservationUnitOptionData = {
id: number,
roomType: string,
isBed: boolean,
assigned: string | null,
label: string,
status: App.Data.Shared.BadgeData,
arrival: string,
departure: string,
};
export type RoomTypeOptionData = {
id: number,
name: string,
isBed: boolean,
maxAdults: number,
maxChildren: number,
};
export type UnitActionData = {
key: App.Enums.UnitAction,
label: string,
};
export type UnitCheckInPreviewData = {
room: string | null,
roomState: App.Enums.CheckInRoomState,
cardsMissing: number,
cardRequired: boolean,
canCheckInWithoutCard: boolean,
needsNationality: boolean,
countries: {
value: string,
label: string,
}[],
creditExceeded: boolean,
canOverrideCredit: boolean,
collection: App.Data.Reservations.CheckInCollectionData | null,
error: string | null,
};
export type UnitCheckOutPreviewData = {
balance: string,
owing: string,
depositHeld: string,
refundDefault: string,
depositMethod: string | null,
canRefund: boolean,
folioKey: string | null,
error: string | null,
canCollect: boolean,
early: App.Data.Reservations.UnitEarlyDepartureData | null,
};
export type UnitDatesPreviewData = {
previousTotal: string | null,
newTotal: string | null,
difference: string | null,
nights: number,
isEarlyDeparture: boolean,
fee: string | null,
restricted: boolean,
error: string | null,
};
export type UnitEarlyDepartureData = {
departure: string,
nightsRemoved: number,
fee: string,
feeTotal: string,
withinFreeWindow: boolean,
};
export type UnitFeePreviewData = {
fee: string | null,
withinFreeWindow: boolean,
freeUntilDays: number | null,
daysBeforeArrival: number | null,
unpostedAddOns: number,
error: string | null,
};
export type UnitLateCheckOutPreviewData = {
fee: string | null,
departureTime: string,
standardTime: string,
error: string | null,
};
export type UnitMovePreviewData = {
current: string | null,
roomTypes: {
id: number,
name: string,
}[],
roomTypeId: number | null,
sellsBeds: boolean,
reasonRequired: boolean,
units: App.Data.Reservations.FreeUnitData[],
error: string | null,
};
export type UnitPickData = {
unitIndex: number,
roomId: number | null,
bedId: number | null,
};
export type UnitReinstatePreviewData = {
previousTotal: string | null,
newTotal: string | null,
error: string | null,
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
termsDocumentId: number | null,
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
isOwner: boolean,
isYou: boolean,
joinedAt: string,
deactivatedAt: string | null,
temporaryPasswordExpiresAt: string | null,
};
export type TemporaryPasswordMessageData = {
text: string,
count: number,
skipped: number,
};
export type TemporaryPasswordSlipData = {
property: string,
name: string,
email: string,
role: string | null,
temporaryPassword: string,
expiresOn: string,
loginUrl: string,
};
}
}
namespace Enums {
export type AgeBandPricing = 'free' | 'percent' | 'fixed';
export type ArInvoiceStatus = 'open' | 'settled' | 'void';
export type ArInvoiceType = 'invoice' | 'credit_note';
export type AuditCheck = 'in_house_without_room' | 'arrival_not_checked_in' | 'departure_not_checked_out' | 'folio_open_after_check_out' | 'master_not_billed' | 'money_held_on_closed_booking' | 'carried_exception';
export type AuditExceptionType = 'missed_arrival' | 'overdue_departure' | 'room_charge' | 'add_on' | 'unexpected_error';
export type AuditFix = 'assign' | 'no-show' | 'extend';
export type AuditStatus = 'running' | 'completed' | 'failed';
export type AuditStep = 'claim_business_date' | 'resolve_missed_arrivals' | 'resolve_overdue_departures' | 'post_room_charges' | 'post_scheduled_add_ons' | 'update_room_statuses' | 'snapshot_statistics' | 'close_and_open_next';
export type AuditStepStatus = 'running' | 'completed' | 'failed';
export type AuditTrigger = 'scheduled' | 'manual';
export type BillingMode = 'master_pays' | 'master_pays_all' | 'each_pays';
export type BusinessDateStatus = 'open' | 'auditing' | 'closed';
export type ChargingUnit = 'per_item' | 'per_night' | 'per_stay' | 'per_guest_night' | 'per_guest';
export type CheckInRoomState = 'assigned' | 'auto' | 'none';
export type ColumnAlign = 'left' | 'center' | 'right';
export type CompanyImportMode = 'simple' | 'detailed';
export type CompanyStatus = 'active' | 'suspended';
export type CompanyType = 'corporate' | 'travel_agent' | 'government';
export type Currency = 'MYR';
export type DefaultMealPeriod = 'breakfast' | 'lunch' | 'dinner';
export type DefaultRoleEnum = 'owner' | 'manager' | 'front_desk' | 'housekeeping' | 'finance';
export type DiscountScope = 'room_only' | 'all_charges' | 'selected';
export type DiscountType = 'percent' | 'fixed' | 'open';
export type DocumentType = 'reservation' | 'folio' | 'invoice' | 'credit_note' | 'receipt' | 'registration_card';
export type DormStrategy = 'bed_as_unit' | 'native_dorm';
export type EarlyDeparturePolicy = 'charge_full' | 'charge_used_nights';
export type FieldVisibility = 'hidden' | 'optional' | 'required';
export type FolioRowTag = 'scheduled' | 'overridden' | 'reversed' | 'reversal' | 'refund';
export type FolioStatus = 'open' | 'closed' | 'voided';
export type FolioType = 'master' | 'unit' | 'third_party';
export type Gender = 'male' | 'female';
export type GuestMatchReason = 'phone' | 'email' | 'id_number' | 'name_and_birth_date' | 'name';
export type HousekeepingStatus = 'clean' | 'dirty' | 'inspected';
export type IdentityDocumentType = 'mykad' | 'passport' | 'other';
export type InclusionEnd = 'departure_day' | 'night_before_departure';
export type InclusionStart = 'arrival_day' | 'day_after_arrival';
export type Locale = 'en' | 'ms';
export type MediaDisk = 'local' | 'r2';
export type MissedArrivalPolicy = 'mark_no_show' | 'keep_as_late_arrival';
export type NavigationContext = 'front-desk' | 'finance' | 'reports' | 'settings';
export type NotificationChannel = 'mail' | 'database';
export type NotificationType = 'night_audit_completed' | 'night_audit_exceptions' | 'night_audit_failed' | 'registration_card_link' | 'onboarding_reminder' | 'dorm_strategy_changed' | 'support_access_used';
export type OverdueDeparturePolicy = 'extend_one_night' | 'block';
export type OwnerSetupMethod = 'email_link' | 'initial_password';
export type PackageUnit = 'per_guest' | 'per_room';
export type PageOrientation = 'portrait' | 'landscape';
export type PaymentMethodType = 'cash' | 'card' | 'bank_transfer' | 'e_wallet' | 'ota_collected' | 'direct_bill';
export type PaymentPurpose = 'advance' | 'settlement' | 'security_deposit';
export type PaymentReportStatus = 'pending' | 'completed' | 'failed' | 'voided';
export type PaymentStage = 'before_arrival' | 'in_house' | 'departed';
export type PaymentStatus = 'pending' | 'completed' | 'failed';
export type PdfFont = 'office' | 'modern' | 'fancy' | 'handwritten';
export type PdfFontSize = 'small' | 'medium' | 'large';
export type PermissionEnum = 'settings.manage' | 'staff.view' | 'staff.manage' | 'roles.manage' | 'rooms.view' | 'rooms.manage' | 'rooms.block' | 'notification.manage' | 'rates.view' | 'rates.manage' | 'rates.override-restrictions' | 'guests.view' | 'guests.manage' | 'guests.blacklist' | 'guests.view-documents' | 'guests.merge' | 'guests.anonymize' | 'reservations.view' | 'reservations.manage' | 'folio.post-charge' | 'folio.reverse' | 'folio.transfer' | 'folio.discount' | 'folio.discount-open' | 'folio.close' | 'folio.reopen' | 'folio.print-voucher' | 'payment.record' | 'payment.refund' | 'company.view' | 'company.manage' | 'company.credit-override' | 'city-ledger.invoice' | 'city-ledger.credit-note' | 'city-ledger.payment' | 'city-ledger.allocate' | 'registration-card.manage-terms' | 'registration-card.sign' | 'registration-card.send-link' | 'registration-card.void' | 'front-desk.check-in' | 'front-desk.check-out' | 'front-desk.check-in-without-card' | 'front-desk.waive-fees' | 'night-audit.run' | 'night-audit.force' | 'night-audit.receive-report' | 'reports.view';
export type PlatformFeature = 'channel-manager';
export type PolicyChargeMode = 'free' | 'fixed_amount' | 'percentage';
export type PolicyRateBasis = 'booked_rate' | 'current_rate';
export type PolicyScope = 'first_night' | 'whole_stay' | 'unused_nights' | 'one_charge';
export type PolicyTab = 'no-show' | 'cancellation' | 'early-departure' | 'late-checkout';
export type PricingBasis = 'per_room' | 'per_guest' | 'per_bed' | 'package';
export type PropertyStatus = 'onboarding' | 'pending_approval' | 'live' | 'suspended';
export type PropertyType = 'hotel' | 'hostel' | 'resort' | 'serviced_apartment' | 'homestay';
export type RateDerivation = 'percent' | 'amount';
export type RatePlanStatus = 'active' | 'archived';
export type RegistrationCardField = 'full_name' | 'nationality' | 'identity_document' | 'date_of_birth' | 'gender' | 'address' | 'phone' | 'email' | 'vehicle_plate' | 'remarks' | 'marketing_consent' | 'booking_number' | 'check_in' | 'check_out' | 'nights' | 'guest_count' | 'room' | 'rate_per_night' | 'total_charges';
export type RegistrationCardFieldGroup = 'guest' | 'stay' | 'prices';
export type RegistrationCardScope = 'primary_only' | 'every_adult';
export type RegistrationCardStatus = 'draft' | 'signed' | 'void';
export type ReportCategory = 'front-desk' | 'revenue' | 'finance' | 'kitchen' | 'audit';
export type ReportDatePreset = 'today' | 'yesterday' | 'tomorrow' | 'next-7-days' | 'this-week' | 'last-week' | 'next-week' | 'this-month' | 'last-month' | 'this-year' | 'last-30-days';
export type ReportGroup = 'day' | 'month' | 'method' | 'staff';
export type ReportKey = 'arrivals' | 'departures' | 'in-house' | 'occupancy' | 'revenue' | 'payments' | 'folio-ledger' | 'tax' | 'meal-counts' | 'night-audit-history' | 'outstanding-balance' | 'deposit-liability' | 'non-national-guests' | 'police-report';
export type ReservationRoomBoxState = 'assigned' | 'unassigned' | 'auto_assign' | 'cancelled' | 'no_show';
export type ReservationSource = 'direct' | 'walk_in' | 'phone' | 'channel' | 'portal';
export type ReservationStatus = 'confirmed' | 'in_house' | 'completed' | 'cancelled';
export type RoomBlockReason = 'maintenance' | 'owner_use' | 'out_of_order' | 'other';
export type RoutingRuleSource = 'billing_mode' | 'manual';
export type SellUnit = 'room' | 'bed';
export type SpreadsheetFormat = 'csv' | 'xlsx';
export type StayDiscountType = 'percent' | 'amount';
export type SurchargeType = 'none' | 'fixed' | 'percent';
export type SystemTransactionCode = 'room_revenue' | 'no_show_revenue' | 'cancellation_revenue' | 'late_checkout_revenue' | 'early_departure_revenue' | 'food_and_beverage_revenue' | 'laundry_revenue' | 'minibar_revenue' | 'fee_revenue' | 'sst_tax' | 'tourism_tax' | 'cash_payment' | 'card_payment' | 'bank_transfer_payment' | 'ewallet_payment' | 'ota_collected_payment' | 'direct_bill_payment' | 'security_deposit' | 'refund' | 'adjustment' | 'discount';
export type TaxRateType = 'percent' | 'flat';
export type TemporaryPasswordExportFormat = 'message' | 'csv' | 'xlsx' | 'pdf';
export type TermsPlaceholder = 'property_name' | 'check_in_time' | 'check_out_time' | 'security_deposit' | 'tourism_tax_rate';
export type TermsPreset = 'standard_hotel' | 'hostel' | 'resort' | 'serviced_apartment' | 'homestay';
export type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'danger';
export type TransactionCategory = 'room' | 'food_and_beverage' | 'laundry' | 'minibar' | 'fee' | 'tax' | 'discount' | 'cash' | 'card' | 'bank_transfer' | 'e_wallet' | 'ota_collected' | 'direct_bill' | 'deposit' | 'refund' | 'adjustment' | 'other';
export type TransactionKind = 'charge' | 'payment' | 'adjustment' | 'tax';
export type UnitAction = 'check-in' | 'check-out' | 'late-check-out' | 'change-dates' | 'cancel' | 'no-show' | 'reinstate' | 'move-room';
export type UnitStatus = 'confirmed' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show';
export type Weekday = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';
export type WeekendNights = 'fri_sat' | 'sat_sun';
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
