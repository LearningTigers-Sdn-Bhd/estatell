import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { MoneyText } from '@/components/pms/money-text';
import { PageHeader } from '@/components/pms/page-header';
import { PosZReport } from '@/components/pms/pos/pos-z-report';
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

export default function PosShiftsShow({ shift, movements, currency }: Props) {
    return (
        <>
            <Head title={`Shift ${shift.shiftNumber}`} />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`Shift ${shift.shiftNumber}`}
                    description={`${shift.outletName} · ${shift.registerName} · ${shift.statusLabel}`}
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
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Cash</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-1 text-sm">
                            <p>
                                Opening cash{' '}
                                <MoneyText
                                    amount={shift.openingCash}
                                    currency={currency}
                                />
                            </p>
                            {shift.expectedCash !== null && (
                                <p>
                                    Expected{' '}
                                    <MoneyText
                                        amount={shift.expectedCash}
                                        currency={currency}
                                    />
                                </p>
                            )}
                            {shift.countedCash !== null && (
                                <p>
                                    Counted{' '}
                                    <MoneyText
                                        amount={shift.countedCash}
                                        currency={currency}
                                    />
                                </p>
                            )}
                            {shift.cashVariance !== null && (
                                <p
                                    className={
                                        Number(shift.cashVariance) !== 0
                                            ? 'font-medium text-destructive'
                                            : undefined
                                    }
                                >
                                    Variance{' '}
                                    <MoneyText
                                        amount={shift.cashVariance}
                                        currency={currency}
                                    />
                                </p>
                            )}
                            {shift.zReport === null && (
                                <p className="text-muted-foreground">
                                    The Z-report is frozen when the shift
                                    closes.
                                </p>
                            )}
                        </CardContent>
                    </Card>
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
                                            Amount
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
                </div>
                {shift.zReport && (
                    <Card>
                        <CardContent className="pt-6">
                            <PosZReport
                                report={shift.zReport}
                                currency={currency}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}

PosShiftsShow.layout = {
    breadcrumbs: [{ title: 'POS shifts', href: index() }],
};
