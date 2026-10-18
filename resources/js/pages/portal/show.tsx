import { Head, Link } from '@inertiajs/react';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { buttonVariants } from '@/components/ui/button';
import { book, index } from '@/routes/portal';
import { index as bookings } from '@/routes/portal/bookings';

export default function PortalShow({
    companyName,
    companyStatus,
    companySuspended,
    propertyName,
    propertyCode,
    openBalance,
    currency,
}: {
    companyName: string;
    companyStatus: string;
    companySuspended: boolean;
    propertyName: string;
    propertyCode: string;
    openBalance: string;
    currency: App.Data.Property.CurrencyData | null;
}) {
    return (
        <>
            <Head title={propertyName} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={propertyName}
                    description={companyName}
                    back={{ href: index(), label: 'Your properties' }}
                />

                <dl className="grid max-w-3xl gap-3 sm:grid-cols-2">
                    <div className="flex flex-col gap-1 rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">
                            Company status
                        </dt>
                        <dd className="text-sm">{companyStatus}</dd>
                    </div>
                    <div className="flex flex-col gap-1 rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">
                            Open balance
                        </dt>
                        <dd className="text-sm">
                            <MoneyText
                                amount={openBalance}
                                currency={currency}
                            />
                        </dd>
                    </div>
                </dl>

                {companySuspended ? (
                    <p className="text-sm text-muted-foreground">
                        This company is suspended. You can read its details but
                        not book.
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        <Link
                            href={book({ property: propertyCode })}
                            className={buttonVariants()}
                        >
                            Book a stay
                        </Link>
                        <Link
                            href={bookings({ property: propertyCode })}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            Your bookings
                        </Link>
                    </div>
                )}
            </div>
        </>
    );
}
