import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * One skeleton cell for each tile the user may see, while the deferred tiles load.
 */
export function TilesSkeleton({
    units,
    reports,
}: {
    units: boolean;
    reports: boolean;
}) {
    const cell = (key: string, className?: string) => (
        <Card key={key} className={className}>
            <CardHeader>
                <Skeleton className="h-5 w-24" />
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                <Skeleton className="h-8 w-20" />
                <Skeleton className="h-4 w-32" />
            </CardContent>
        </Card>
    );

    return (
        <>
            {units && (
                <>
                    {cell('tonight', 'lg:col-span-2')}
                    {cell('arrivals')}
                    {cell('departures')}
                </>
            )}
            {reports && cell('month', 'lg:col-span-2')}
        </>
    );
}
