import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { buttonVariants } from '@/components/ui/button';
import { properties as propertiesPage, show } from '@/routes/portal';

type Property = App.Data.Portal.PortalPropertyData;

function PropertyRow({ property }: { property: Property }) {
    const open = property.state === 'open';

    return (
        <li
            className={`flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4 ${open ? '' : 'bg-muted/40 text-muted-foreground'}`}
        >
            <div className="flex min-w-0 flex-col gap-1">
                <span className="truncate font-medium">
                    {property.propertyName}
                </span>
                <span className="truncate text-sm text-muted-foreground">
                    {property.companyName}
                </span>
            </div>
            {open ? (
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                    <span className="flex flex-col">
                        <span className="text-xs text-muted-foreground">
                            Open balance
                        </span>
                        <MoneyText
                            amount={property.openBalance}
                            currency={property.currency}
                        />
                    </span>
                    <span className="flex flex-col">
                        <span className="text-xs text-muted-foreground">
                            Arrivals, next 7 days
                        </span>
                        <span className="tabular-nums">
                            {property.arrivals}
                        </span>
                    </span>
                    <Link
                        href={show({ property: property.propertyCode })}
                        className={buttonVariants({ size: 'sm' })}
                    >
                        Open
                    </Link>
                </div>
            ) : (
                <span className="text-sm">{property.stateLabel}</span>
            )}
        </li>
    );
}

export default function PortalProperties({
    properties,
}: {
    properties: Property[];
}) {
    return (
        <>
            <Head title="Properties" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Properties"
                    description="The properties that gave you a company portal login."
                />

                {properties.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No properties yet"
                        description="A property links your login to your company. Ask the property to set your email as the company's portal login."
                    />
                ) : (
                    <ul className="flex max-w-3xl flex-col gap-3">
                        {properties.map((property) => (
                            <PropertyRow
                                key={property.linkId}
                                property={property}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

PortalProperties.layout = {
    breadcrumbs: [{ title: 'Properties', href: propertiesPage() }],
};
