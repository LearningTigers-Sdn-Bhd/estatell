import { lazy, Suspense } from 'react';

const CountryFlagSvg = lazy(() => import('@/components/pms/country-flag-svg'));

const FLAG_CLASS = 'h-3.5 w-5 shrink-0 rounded-xs';

/**
 * The flag of an ISO 3166-1 alpha-2 country code. An empty box holds its place until the flags load, or when a code has no flag.
 */
export function CountryFlag({ code }: { code: string }) {
    return (
        <Suspense fallback={<span aria-hidden className={FLAG_CLASS} />}>
            <CountryFlagSvg code={code} className={FLAG_CLASS} />
        </Suspense>
    );
}
