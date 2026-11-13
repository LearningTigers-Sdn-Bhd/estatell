import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { ReactNode } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { PageHeader } from '@/components/pms/page-header';

/** Shared editorial frame for the concierge's forms, guide and stay pages. */
export function GuestFrame({
    back,
    title,
    description,
    children,
    centerContent = false,
}: {
    back: { href: string; label: string };
    title: string;
    description?: string;
    children: ReactNode;
    centerContent?: boolean;
}) {
    const { property, stay } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
        stay?: App.Data.Concierge.ConciergeStayData;
    }>().props;
    const guestProperty = property ?? stay?.property;

    const navigation = (
        <div className="flex flex-wrap items-center justify-between gap-4">
            <Link
                href={back.href}
                className={buttonVariants({
                    variant: 'ghost',
                    className: 'min-h-11',
                })}
            >
                <ArrowLeft className="size-4" aria-hidden />
                {back.label}
            </Link>
            <div className="flex min-w-0 items-center gap-3">
                {guestProperty?.logoUrl && (
                    <img
                        src={guestProperty.logoUrl}
                        alt=""
                        width={32}
                        height={32}
                        className="size-8 shrink-0 object-contain"
                    />
                )}
                <p className="text-sm font-medium">
                    {guestProperty?.name ?? 'Guest concierge'}
                </p>
            </div>
        </div>
    );

    return (
        <>
            <Head title={title} />
            <div className="min-h-0 flex-1 overflow-y-auto">
                <div className="concierge-page">
                    <div className="flex flex-col gap-5 border-b pb-5">
                        {navigation}
                        <PageHeader title={title} description={description} />
                    </div>
                    <div
                        className={
                            centerContent
                                ? 'concierge-centered-content flex flex-1 flex-col gap-6 sm:gap-8'
                                : 'contents'
                        }
                    >
                        <div className="concierge-page-content flex flex-col gap-6 sm:gap-8">
                            {children}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
