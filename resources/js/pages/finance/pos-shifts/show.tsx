import { Head } from '@inertiajs/react';
import { Banknote, Calculator, Printer, Scale, Wallet } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { PosZReport } from '@/components/pms/pos/pos-z-report';
import { StatCard } from '@/components/pms/stat-card';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index } from '@/routes/finance/pos-shifts';

type Props = {
    shift: App.Data.Pos.PosShiftData;
    movements: {
        id: number;
        kind: string;
        amount: string;
        reason: string;
        createdAt: string;
    }[];
    currency: App.Data.Property.CurrencyData | null;
};

function dateTime(value: string): string {
    return new Date(value).toLocaleString('en-GB', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });
}

export default function PosShiftsShow({ shift, movements, currency }: Props) {
    const money = (amount: string | null) =>
        amount === null ? (
            '–'
        ) : (
            <MoneyText amount={amount} currency={currency} />
        );

    return (
        <>
            <Head title={`Shift ${shift.shiftNumber}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Shift ${shift.shiftNumber}`}
                    description={`${shift.outletName} · ${shift.registerName}`}
                    badge={
                        <StatusBadge
                            status={{
                                value: shift.status,
                                label: shift.statusLabel,
                                tone:
                                    shift.status === 'open'
                                        ? 'success'
                                        : shift.status === 'closed'
                                          ? 'neutral'
                                          : 'warning',
                            }}
                        />
                    }
                    back={{ href: index(), label: 'POS shifts' }}
                    actions={
                        shift.zReport && (
                            <Button
                                variant="outline"
                                onClick={() => window.print()}
                            >
                                <Printer data-icon="inline-start" />
                                Print Z-report
                            </Button>
                        )
                    }
                />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        icon={Wallet}
                        label="Opening cash"
                        value={money(shift.openingCash)}
                        hint={`Opened ${dateTime(shift.openedAt)} by ${shift.openedBy}`}
                    />
                    <StatCard
                        icon={Calculator}
                        label="Expected cash"
                        value={money(shift.expectedCash)}
                        hint="What the drawer should hold"
                    />
                    <StatCard
                        icon={Banknote}
                        label="Counted cash"
                        value={money(shift.countedCash)}
                        hint={
                            shift.closedAt === null
                                ? 'Not closed yet'
                                : `Closed ${dateTime(shift.closedAt)}`
                        }
                    />
                    <StatCard
                        icon={Scale}
                        label="Variance"
                        value={money(shift.cashVariance)}
                        hint={
                            shift.cashVariance === null
                                ? 'Known at close'
                                : Number(shift.cashVariance) < 0
                                  ? 'Short'
                                  : Number(shift.cashVariance) > 0
                                    ? 'Over'
                                    : 'Balanced'
                        }
                    />
                </div>
                <div className="grid items-start gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Cash movements</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Kind</TableHead>
                                        <TableHead>Reason</TableHead>
                                        <TableHead className="text-right">
                                            Amount ({currency?.code ?? 'MYR'})
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {movements.map((movement) => (
                                        <TableRow key={movement.id}>
                                            <TableCell>
                                                {movement.kind}
                                            </TableCell>
                                            <TableCell>
                                                {movement.reason}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <MoneyText
                                                    amount={movement.amount}
                                                    currency={currency}
                                                    plain
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    {movements.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={3}
                                                className="text-muted-foreground"
                                            >
                                                No cash movements.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Z-report</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {shift.zReport ? (
                                <PosZReport
                                    report={shift.zReport}
                                    currency={currency}
                                />
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    The Z-report is frozen when the shift
                                    closes.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

PosShiftsShow.layout = {
    breadcrumbs: [{ title: 'POS shifts', href: index() }],
};
