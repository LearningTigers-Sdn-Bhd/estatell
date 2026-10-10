import { ExternalLink } from 'lucide-react';
import type { FontStyleOption } from '@/components/pms/settings/print-general-form';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { sample } from '@/routes/settings/printing';

type Settings = App.Data.Property.PrintSettingsData;

type Night = {
    unit: string;
    day: number;
    text: string;
    unitAmount: number;
    amount: number;
};
type Row = { dates: string; text: string; amount: number };

const money = (amount: number) =>
    amount.toLocaleString('en-MY', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

const sampleNights: Night[] = [
    ...[12, 13, 14].map((day) => ({
        unit: 'Room 103 · Tan Wei Ming',
        day,
        text: 'Room',
        unitAmount: 250,
        amount: 250,
    })),
    {
        unit: 'Room 103 · Tan Wei Ming',
        day: 13,
        text: 'Island hopping trip',
        unitAmount: 360,
        amount: 360,
    },
    ...[12, 13, 14].map((day) => ({
        unit: 'Room 104 · Lee Mei Ling',
        day,
        text: 'Room',
        unitAmount: 230,
        amount: 230,
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
}: {
    settings: Settings;
    fonts: FontStyleOption[];
    propertyName: string;
}) {
    const font = fonts.find((option) => option.value === settings.font);
    const units = [...new Set(sampleNights.map((night) => night.unit))];
    const flat = [...sampleNights].sort((a, b) => a.day - b.day);
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
                <div className="flex flex-col gap-1 border-t pt-2 tabular-nums">
                    {settings.showUnitOnLines
                        ? units.map((unit) => (
                              <div key={unit} className="flex flex-col gap-1">
                                  <p className="font-bold">{unit}</p>
                                  {rowsFor(
                                      sampleNights.filter(
                                          (night) => night.unit === unit,
                                      ),
                                      settings.nightlyBreakdown,
                                  ).map((row) => (
                                      <LineRow
                                          key={row.dates + row.text}
                                          row={row}
                                      />
                                  ))}
                              </div>
                          ))
                        : rowsFor(flat, settings.nightlyBreakdown).map(
                              (row, index) => <LineRow key={index} row={row} />,
                          )}
                </div>
                <div className="flex flex-col gap-1 tabular-nums">
                    {taxLines.map((tax) => (
                        <div
                            key={tax.label}
                            className="flex justify-between gap-2"
                        >
                            <span className="ml-auto">{tax.label}</span>
                            <span className="w-20 text-right">
                                {money(tax.amount)}
                            </span>
                        </div>
                    ))}
                    <div className="flex justify-between gap-2 border-t pt-1 font-bold">
                        <span className="ml-auto">Total MYR</span>
                        <span className="w-20 text-right">{money(total)}</span>
                    </div>
                    {settings.showAmountInWords && (
                        <p className="text-xs text-muted-foreground">
                            Ringgit Malaysia two thousand and four only
                        </p>
                    )}
                </div>
                {settings.showCancellationPolicy && (
                    <div className="border-t pt-2">
                        <p className="font-bold">Cancellation policy</p>
                        <p className="text-xs text-muted-foreground">
                            Free cancellation until 2 days before arrival. After
                            that, one night is charged.
                        </p>
                    </div>
                )}
                {settings.bankDetails && (
                    <div
                        className={cn(
                            'pt-2',
                            !settings.showCancellationPolicy && 'border-t',
                        )}
                    >
                        <p className="font-bold">Payment details</p>
                        <p className="text-xs whitespace-pre-line text-muted-foreground">
                            {settings.bankDetails}
                        </p>
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

function LineRow({ row }: { row: Row }) {
    return (
        <div className="flex justify-between gap-2">
            <span className="w-16 shrink-0 text-muted-foreground">
                {row.dates}
            </span>
            <span className="min-w-0 flex-1 truncate">{row.text}</span>
            <span>{money(row.amount)}</span>
        </div>
    );
}
