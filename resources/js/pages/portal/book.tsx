import { Head } from '@inertiajs/react';
import { BookForm } from '@/components/pms/portal/book-form';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { show } from '@/routes/portal';

export default function PortalBook({
    propertyName,
    propertyCode,
    companyName,
    companySuspended,
    today,
    cancelCutoffDays,
    roomTypes,
    autoAddOns,
    currency,
}: {
    propertyName: string;
    propertyCode: string;
    companyName: string;
    companySuspended: boolean;
    today: string;
    cancelCutoffDays: number | null;
    roomTypes: App.Data.Portal.PortalRoomTypeData[];
    autoAddOns: string[];
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <>
            <Head title={`Book at ${propertyName}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Book a stay"
                    description={`${propertyName}, billed to ${companyName}.`}
                    back={{
                        href: show({ property: propertyCode }),
                        label: propertyName,
                    }}
                />

                {companySuspended ? (
                    <Alert>
                        <AlertDescription>
                            Your company can&apos;t book at this property right
                            now. Contact the property.
                        </AlertDescription>
                    </Alert>
                ) : roomTypes.length === 0 ? (
                    <Alert>
                        <AlertDescription>
                            No rates are open to your company yet. Contact the
                            property.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <BookForm
                        propertyCode={propertyCode}
                        roomTypes={roomTypes}
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
