import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * The shape of the Performance cards while the deferred figures load: four tiles, then Trend beside Channel mix and Lead time.
 */
export function PerformanceSkeleton() {
    return (
        <div className="flex flex-1 flex-col gap-4" aria-busy="true">
            <div className="grid grid-cols-2 gap-4 xl:grid-cols-4">
                {['occupancy', 'adr', 'revpar', 'lead'].map((key) => (
                    <Card key={key} size="sm">
                        <CardHeader>
                            <Skeleton className="h-5 w-24" />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            <Skeleton className="h-7 w-20" />
                            <Skeleton className="h-4 w-16" />
                        </CardContent>
                    </Card>
                ))}
            </div>
            <div className="grid gap-4 xl:flex-1 xl:grid-cols-3">
                <Card className="xl:col-span-2">
                    <CardHeader>
                        <Skeleton className="h-5 w-24" />
                    </CardHeader>
                    <CardContent className="flex flex-1 flex-col">
                        <Skeleton className="min-h-64 w-full flex-1" />
                    </CardContent>
                </Card>
                <div className="flex flex-col gap-4">
                    <Card className="flex-1">
                        <CardHeader>
                            <Skeleton className="h-5 w-28" />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            <Skeleton className="h-3 w-full" />
                            {[1, 2, 3, 4, 5].map((row) => (
                                <Skeleton key={row} className="h-5 w-full" />
                            ))}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <Skeleton className="h-5 w-48" />
                        </CardHeader>
                        <CardContent className="flex flex-col gap-2">
                            {[1, 2, 3, 4].map((row) => (
                                <Skeleton key={row} className="h-5 w-full" />
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    );
}
