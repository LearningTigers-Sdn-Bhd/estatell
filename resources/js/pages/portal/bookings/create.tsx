import { Head } from '@inertiajs/react';
import type { EnumOption } from '@/components/pms/enum-select';
import { BookForm } from '@/components/pms/portal/book-form';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { create, index } from '@/routes/portal/bookings';

export default function PortalBookingCreate({
    propertyName,
    propertyCode,
    companyName,
    companySuspended,
    today,
    cancelCutoffDays,
    ratePlans,
    roomTypes,
    idTypes,
    autoAddOns,
    currency,
}: {
    propertyName: string;
    propertyCode: string;
    companyName: string;
    companySuspended: boolean;
    today: string;
    cancelCutoffDays: number | null;
    ratePlans: App.Data.Portal.PortalRatePlanData[];
    roomTypes: App.Data.Portal.PortalRoomTypeData[];
    idTypes: EnumOption[];
    autoAddOns: string[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <>
            <Head title={`Book at ${propertyName}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="New booking"
                    description={`${propertyName}, billed to ${companyName}.`}
                    back={{
                        href: index({ property: propertyCode }),
                        label: 'Bookings',
                    }}
                />

                {companySuspended ? (
                    <Alert>
                        <AlertDescription>
                            Your company can&apos;t book at this property right
                            now. Contact the property.
                        </AlertDescription>
                    </Alert>
                ) : ratePlans.length === 0 ? (
                    <Alert>
                        <AlertDescription>
                            No rates are open to your company yet. Contact the
                            property.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <BookForm
                        propertyCode={propertyCode}
                        ratePlans={ratePlans}
                        roomTypes={roomTypes}
                        idTypes={idTypes}
                        today={today}
                        cancelCutoffDays={cancelCutoffDays}
                        autoAddOns={autoAddOns}
                        companyName={companyName}
                        currency={currency}
                    />
                )}
            </div>
        </>
    );
}

PortalBookingCreate.layout = (props: { propertyCode: string }) => ({
    breadcrumbs: [
        { title: 'Bookings', href: index({ property: props.propertyCode }) },
        {
            title: 'New booking',
            href: create({ property: props.propertyCode }),
        },
    ],
});
