import { ConnectionActions } from '@/components/pms/channels/connection-actions';
import { StatusBadge } from '@/components/pms/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

type OtaRow = App.Data.Channels.ChannelOtaRowData;

/**
 * The OTA table of the admin Channels tab: one line per OTA with its hotel ID and status, and Connect for an OTA that
 * has a Channex adapter and no connection yet. The tab gives it its heading.
 */
export function ChannelOtasTable({
    code,
    otas,
}: {
    code: string;
    otas: OtaRow[];
}) {
    return (
        <div className="overflow-x-auto rounded-xl border">
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
                                        <StatusBadge status={ota.status} />
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
                            <TableCell>
                                <ConnectionActions code={code} ota={ota} />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
