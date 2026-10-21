import { Link } from '@inertiajs/react';
import { StatusBadge } from '@/components/pms/status-badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { connect } from '@/routes/admin/properties/channels';
import { map } from '@/routes/admin/properties/channels/connections';

type OtaRow = App.Data.Channels.ChannelOtaRowData;

/**
 * The OTAs card of the admin Channels tab: one line per OTA with its hotel ID and status, and Connect for an OTA that
 * has a Channex adapter and no connection yet.
 */
export function ChannelOtasCard({
    code,
    otas,
}: {
    code: string;
    otas: OtaRow[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>OTAs</CardTitle>
                <CardDescription>
                    Connect each OTA the property sells on. Channex then sends
                    availability and rates there and brings bookings back.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Channel</TableHead>
                                <TableHead>Hotel ID</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {otas.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        No OTA channels have a Channex adapter.
                                    </TableCell>
                                </TableRow>
                            )}
                            {otas.map((ota) => (
                                <TableRow key={ota.otaChannelId}>
                                    <TableCell className="font-medium">
                                        {ota.name}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {ota.hotelId ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        {ota.status ? (
                                            <div className="flex flex-col items-start gap-1">
                                                <StatusBadge
                                                    status={ota.status}
                                                />
                                                {ota.lastError && (
                                                    <span className="text-xs text-destructive">
                                                        {ota.lastError}
                                                    </span>
                                                )}
                                            </div>
                                        ) : ota.note ? (
                                            <span className="text-muted-foreground">
                                                {ota.note}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                Not connected
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {ota.connectionId !== null && (
                                            <Link
                                                href={map({
                                                    property: code,
                                                    connection:
                                                        ota.connectionId,
                                                })}
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                {ota.status?.value === 'draft'
                                                    ? 'Continue'
                                                    : 'Mapping'}
                                            </Link>
                                        )}
                                        {ota.canConnect && (
                                            <Link
                                                href={connect({
                                                    property: code,
                                                    otaChannel:
                                                        ota.otaChannelId,
                                                })}
                                                className={buttonVariants({
                                                    variant: 'outline',
                                                    size: 'sm',
                                                })}
                                            >
                                                Connect
                                            </Link>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}
