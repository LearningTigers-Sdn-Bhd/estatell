import { Link } from '@inertiajs/react';
import type { ComponentProps, ReactNode } from 'react';

/**
 * A cell of a report table that opens another page, for example the Revenue amount that opens the Folio ledger.
 * Use it in the `cells` of `ReportScreen`; pass a Wayfinder route for `href`.
 */
export function ReportCellLink({
    href,
    children,
}: {
    href: ComponentProps<typeof Link>['href'];
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="font-medium underline-offset-4 hover:underline"
        >
            {children}
        </Link>
    );
}
