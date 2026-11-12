import { ExternalLink } from 'lucide-react';
import type { FontStyleOption } from '@/components/pms/settings/print-general-form';
import type { PrintSampleColumn } from '@/components/pms/settings/print-sample-table';
import { PrintSampleTable } from '@/components/pms/settings/print-sample-table';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { sample } from '@/routes/settings/printing';

type Settings = App.Data.Property.PrintSettingsData;

type Night = {
    unit: string;
    room: string;
    day: number;
    text: string;
    unitAmount: number;
    amount: number;
    nightly: boolean;
};
type Row = { dates: string; room: string; text: string; amount: number };

const money = (amount: number) =>
    amount.toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const sampleNights: Night[] = [
    ...[12, 13, 14].map((day) => ({
        unit: 'Room 103 · Tan Wei Ming',
        room: '103',
        day,
        text: 'Room',
        unitAmount: 250,
        amount: 250,
        nightly: true,
    })),
    {
        unit: 'Room 103 · Tan Wei Ming',
        room: '103',
        day: 13,
        text: 'Island hopping trip',
        unitAmount: 360,
        amount: 360,
        nightly: false,
    },
    ...[12, 13, 14].map((day) => ({
        unit: 'Room 104 · Lee Mei Ling',
        room: '104',
        day,
        text: 'Room',
        unitAmount: 230,
        amount: 230,
        nightly: true,
    })),
];

const taxes = [
    { label: 'Service tax 8%', amount: 144 },
    { label: 'Tourism tax', amount: 60 },
];

/** Merges equal consecutive nights of one unit, like the PDF line grouper. */
function rowsFor(nights: Night[], nightly: boolean): Row[] {
    const runs: { first: Night; last: Night; count: number; sum: number }[] =
        [];

    for (const night of nights) {
        const run = runs.find(
            (candidate) =>
                night.nightly &&
                !nightly &&
                candidate.first.unit === night.unit &&
                candidate.first.text === night.text &&
                candidate.first.unitAmount === night.unitAmount &&
                candidate.last.day + 1 === night.day,
        );

        if (run) {
            run.last = night;
            run.count += 1;
            run.sum += night.amount;
        } else {
            runs.push({
                first: night,
                last: night,
                count: 1,
                sum: night.amount,
            });
        }
    }

    return runs.map((run) => ({
        room: run.first.room,
        dates:
            run.count === 1
                ? `${run.first.day} Oct`
                : `${run.first.day}–${run.last.day} Oct`,
        text:
            run.count === 1
                ? run.first.text
                : `${run.first.text}, ${run.count} nights × ${money(run.first.unitAmount)}`,
        amount: run.sum,
    }));
}

/**
 * A browser drawing of a sample booking summary from the form state. Browser fonts differ from dompdf fonts, so it is
 * close but not exact; Open sample PDF shows the real output of the saved settings.
 */
export function PrintDocumentsPreview({
    settings,
    fonts,
    propertyName,
    bankAccounts,
}: {
    settings: Settings;
    fonts: FontStyleOption[];
    propertyName: string;
    bankAccounts: string[];
}) {
    const font = fonts.find((option) => option.value === settings.font);
    const units = [...new Set(sampleNights.map((night) => night.unit))];
    const flat = [...sampleNights].sort(
        (a, b) => a.day - b.day || a.room.localeCompare(b.room),
    );
    // Two units in the sample, so the Room column shows whenever the lines are one list.
    const showRoom = !settings.showUnitOnLines;
    const taxLines = settings.taxBreakdown
        ? taxes
        : [
              {
                  label: 'Tax',
                  amount: taxes.reduce((sum, tax) => sum + tax.amount, 0),
              },
          ];
    const total = 2004;

    return (
        <aside className="flex flex-col gap-3 lg:sticky lg:top-4">
            <div
                className="flex flex-col gap-3 rounded-lg border bg-card p-4 text-sm text-card-foreground"
                style={{ fontFamily: font?.bodyFamily }}
            >
                <p
                    className="text-base font-bold"
                    style={{ fontFamily: font?.headingFamily }}
                >
                    {propertyName}
                </p>
                <p className="text-xs text-muted-foreground">
                    Booking summary · Sample
                </p>
                <div className="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <p className="text-muted-foreground">Bill to</p>
                        <p>Acme Travel Sdn Bhd</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Stay</p>
                        <p>12–15 Oct</p>
                    </div>
                </div>
                <PrintSampleTable
                    tableStyle={settings.tableStyle}
                    className="text-sm"
                    showHead={showRoom}
                    columns={lineColumns(showRoom)}
                    groups={
                        settings.showUnitOnLines
                            ? units.map((unit) => ({
                                  heading: unit,
                                  rows: rowsFor(
                                      sampleNights.filter(
                                          (night) => night.unit === unit,
                                      ),
                                      settings.nightlyBreakdown,
                                  ).map(lineCells),
                              }))
                            : [
                                  {
                                      rows: rowsFor(
                                          flat,
                                          settings.nightlyBreakdown,
                                      ).map(lineCells),
                                  },
                              ]
                    }
                    summaryAlign="right"
                    summary={taxLines.map((tax) => ({
                        label: tax.label,
                        amount: money(tax.amount),
                    }))}
                    total={{ label: 'Total MYR', amount: money(total) }}
                />
                {settings.showAmountInWords && (
                    <p className="-mt-2 text-xs text-muted-foreground">
                        Ringgit Malaysia two thousand and four only
                    </p>
                )}
                {settings.showCancellationPolicy && (
                    <div className="border-t pt-2">
                        <p className="font-bold">Cancellation policy</p>
                        <p className="text-xs text-muted-foreground">
                            Free cancellation until 2 days before arrival. After
                            that, one night is charged.
                        </p>
                    </div>
                )}
                {(bankAccounts.length > 0 || settings.bankDetails) && (
                    <div
                        className={cn(
                            'pt-2',
                            !settings.showCancellationPolicy && 'border-t',
                        )}
                    >
                        <p className="font-bold">Payment details</p>
                        {bankAccounts.map((line) => (
                            <p
                                key={line}
                                className="text-xs text-muted-foreground"
                            >
                                {line}
                            </p>
                        ))}
                        {settings.bankDetails && (
                            <p className="text-xs whitespace-pre-line text-muted-foreground">
                                {settings.bankDetails}
                            </p>
                        )}
                    </div>
                )}
            </div>
            <a
                href={sample.url(undefined, {
                    query: { document: 'booking-summary' },
                })}
                target="_blank"
                rel="noreferrer"
                className={buttonVariants({
                    variant: 'outline',
                    className: 'self-start',
                })}
            >
                <ExternalLink data-icon="inline-start" />
                Open sample PDF
            </a>
        </aside>
    );
}

/** The columns of the PDF lines partial: Room shows only when the lines are one list. */
function lineColumns(showRoom: boolean): PrintSampleColumn[] {
    return [
        {
            key: 'dates',
            label: 'Date',
            className: 'w-16 whitespace-nowrap text-muted-foreground',
        },
        ...(showRoom
            ? [{ key: 'room', label: 'Room', className: 'w-10' }]
            : []),
        { key: 'text', label: 'Description' },
        { key: 'amount', label: 'Amount', align: 'right' as const },
    ];
}

function lineCells(row: Row) {
    return { ...row, amount: money(row.amount) };
}
