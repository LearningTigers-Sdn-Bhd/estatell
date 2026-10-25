import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The shape of the Performance cards while the deferred figures load: a row of three metric cards, then a row of three.
 */
export function PerformanceSkeleton() {
    return (
        <div
            className="grid gap-3 xl:flex-1 xl:grid-cols-3 xl:grid-rows-[1fr_auto]"
            aria-busy="true"
        >
            {Array.from({ length: 6 }, (_, card) => (
                <Card key={card}>
                    <CardHeader>
                        <Skeleton className="h-5 w-28" />
                    </CardHeader>
                    <CardContent className="flex flex-1 flex-col gap-2">
                        <Skeleton className="h-7 w-24" />
                        <Skeleton className="min-h-24 w-full flex-1" />
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
