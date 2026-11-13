import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Phone } from 'lucide-react';
import type { ReactNode } from 'react';
import { buttonVariants } from '@/components/ui/button';
import { PageHeader } from '@/components/pms/page-header';
import { contact, stayContact } from '@/routes/concierge';

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
    const { component, props } = usePage<{
        property: App.Data.Property.PropertySummaryData | null;
        stay?: App.Data.Concierge.ConciergeStayData;
        token?: string;
    }>();
    const { property, stay, token } = props;
    const guestProperty = stay?.property ?? property;
    const stayToken = stay?.token ?? token;
    const contactHref = stayToken
        ? stayContact.url({ token: stayToken })
        : guestProperty
          ? contact.url({ property: guestProperty.code })
          : null;

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
                    {contactHref && component !== 'concierge/contact' && (
                        <footer
                            aria-label="Guest support"
                            className="mt-auto flex flex-col items-start gap-4 border-t border-border/40 pt-6 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div className="flex items-center gap-3">
                                <Phone
                                    className="size-5 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <div className="flex flex-col gap-1">
                                    <p className="text-sm font-medium">
                                        Need help?
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        For urgent help, contact the front desk
                                        directly.
                                    </p>
                                </div>
                            </div>
                            <Link
                                href={contactHref}
                                className={buttonVariants({
                                    variant: 'outline',
                                    className: 'min-h-11 w-full sm:w-fit',
                                })}
                            >
                                Contact front desk <ArrowRight aria-hidden />
                            </Link>
                        </footer>
                    )}
                </div>
            </div>
        </>
    );
}
