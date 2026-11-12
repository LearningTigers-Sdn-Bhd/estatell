import { Form, Link, router } from '@inertiajs/react';
import {
    AppWindow,
    Cable,
    Calculator,
    CircleCheck,
    CircleMinus,
    CornerDownRight,
    CodeXml,
    ConciergeBell,
    FileText,
    Globe,
    Lock,
    Ship,
    Tablet,
    ToggleRight,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { PanelHeading } from '@/components/pms/panel-heading';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { update as updateMode } from '@/routes/admin/properties/booking-engine-mode';
import { eInvoiceGuide } from '@/routes/admin';
import { update as updateFeature } from '@/routes/admin/properties/features';

type PropertyFeature = App.Data.Platform.PropertyFeatureData;
type BookingEngineMode = App.Data.Platform.BookingEngineModeData;

/** Each feature's icon: the one its module uses in the staff Settings menu (Channels tab for the channel manager). */
const featureIcons: Record<App.Enums.PlatformFeature, LucideIcon> = {
    'channel-manager': Cable,
    boats: Ship,
    'accounting-integration': Calculator,
    'door-locks': Lock,
    kiosks: Tablet,
    'booking-engine': Globe,
    'e-invoicing': FileText,
    concierge: ConciergeBell,
};

/** On and Off for the partner's read-only rows. */
const onStatus: App.Data.Shared.BadgeData = {
    value: 'on',
    label: 'On',
    tone: 'success',
};
const offStatus: App.Data.Shared.BadgeData = {
    value: 'off',
    label: 'Off',
    tone: 'neutral',
};

/** What each lock provider is, shown under its switch. */
const providerNotes: Record<string, string> = {
    ttlock: 'PINs and cards from the TTLock cloud.',
    elock: 'Key cards written at the front desk with Estatell Keyport.',
};

/** Each booking engine mode's icon, shown before its label. */
const modeIcons: Record<string, LucideIcon> = {
    microsite: AppWindow,
    'api-only': CodeXml,
};

/** What happens when an admin switches to each mode, shown in the confirm dialog. */
const modeEffects: Record<string, string> = {
    'api-only':
        'The booking website goes offline. The owner creates an API token and adds return addresses on the API tab.',
    microsite:
        "The API token stops working at once, so the owner's website cannot take bookings. The owner publishes the microsite again from the General tab.",
};

/**
 * Microsite or API only, in the Setting column of the Booking engine mode row. A change asks first, because it takes
 * the current online booking offline.
 */
function BookingEngineModeControl({
    code,
    mode,
    readOnly,
}: {
    code: string;
    mode: BookingEngineMode;
    readOnly: boolean;
}) {
    const [next, setNext] = useState<string | null>(null);
    const label = (value: string) =>
        mode.options.find((option) => option.value === value)?.label ?? value;

    if (readOnly) {
        const Icon = modeIcons[mode.mode];

        return (
            <Badge variant="secondary">
                {Icon && <Icon aria-hidden />}
                {label(mode.mode)}
            </Badge>
        );
    }

    return (
        <>
            <ToggleGroup
                variant="outline"
                spacing={0}
                value={[mode.mode]}
                onValueChange={(value) =>
                    value[0] && value[0] !== mode.mode && setNext(value[0])
                }
                aria-label="Booking engine mode"
            >
                {mode.options.map((option) => {
                    const Icon = modeIcons[option.value];

                    return (
                        <ToggleGroupItem
                            key={option.value}
                            value={option.value}
                        >
                            {Icon && <Icon aria-hidden />}
                            {option.label}
                        </ToggleGroupItem>
                    );
                })}
            </ToggleGroup>

            <AlertDialog
                open={next !== null}
                onOpenChange={(open) => !open && setNext(null)}
            >
                <AlertDialogContent>
                    {next && (
                        <Form
                            {...updateMode.form({ property: code })}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setNext(null)}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="mode"
                                        value={next}
                                    />
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>
                                            Switch to {label(next)}?
                                        </AlertDialogTitle>
                                        <AlertDialogDescription>
                                            {modeEffects[next]}
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>
                                            Cancel
                                        </AlertDialogCancel>
                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && (
                                                <Spinner data-icon="inline-start" />
                                            )}
                                            Switch to {label(next)}
                                        </Button>
                                    </AlertDialogFooter>
                                </>
                            )}
                        </Form>
                    )}
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

/**
 * One row of the features table. A child row (a lock provider, the booking engine mode) starts with a corner arrow.
 * `onChange` missing means the switch is on and disabled: the setting is on while its parent is on.
 */
function FeatureRow({
    icon,
    label,
    description,
    checked,
    onChange,
    switchLabel,
    readOnly,
    child = false,
    setting,
}: {
    /** The feature's icon. A child row always shows the corner arrow. */
    icon?: LucideIcon;
    label: string;
    description: ReactNode;
    checked: boolean;
    onChange?: (checked: boolean) => void;
    switchLabel: string;
    readOnly: boolean;
    child?: boolean;
    setting?: ReactNode;
}) {
    const Icon = child ? CornerDownRight : (icon ?? CornerDownRight);

    return (
        <TableRow>
            <TableCell>
                {readOnly ? (
                    <StatusBadge
                        status={checked ? onStatus : offStatus}
                        icon={checked ? CircleCheck : CircleMinus}
                    />
                ) : (
                    <Switch
                        size="sm"
                        checked={checked}
                        disabled={onChange === undefined}
                        aria-label={switchLabel}
                        onCheckedChange={onChange}
                    />
                )}
            </TableCell>
            <TableCell className={cn('whitespace-normal', child && 'ps-8')}>
                <div className="flex min-w-0 items-start gap-2">
                    <Icon
                        aria-hidden
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <span className="font-medium">{label}</span>
                        <span className="text-xs text-muted-foreground">
                            {description}
                        </span>
                    </div>
                </div>
            </TableCell>
            <TableCell className="text-right">{setting}</TableCell>
        </TableRow>
    );
}

/**
 * The Features tab body: a table with the switch on the left. Door locks and Booking engine show their settings as
 * child rows while they are on. With `readOnly` (the partner page) each row shows On or Off, with no switch.
 */
export function PropertyFeatures({
    code,
    features,
    bookingEngineMode,
    readOnly = false,
}: {
    code: string;
    features: PropertyFeature[];
    bookingEngineMode?: BookingEngineMode;
    readOnly?: boolean;
}) {
    const toggle = (
        feature: PropertyFeature['feature'],
        active: boolean,
        providers?: string[],
    ) => {
        const item = features.find((entry) => entry.feature === feature);
        // Turning Door locks on starts with every provider. The admin then narrows it.
        const next =
            providers ??
            (item?.providerOptions
                ? item.providerOptions.map((option) => option.value)
                : undefined);

        router
            .optimistic<{ features: PropertyFeature[] }>((props) => ({
                features: props.features.map((entry) =>
                    entry.feature === feature
                        ? { ...entry, active, providers: next ?? null }
                        : entry,
                ),
            }))
            .put(
                updateFeature.url({ property: code, feature }),
                next ? { active, providers: next } : { active },
                { preserveScroll: true },
            );
    };

    const providerRows = (item: PropertyFeature) => {
        if (!item.providerOptions || !item.active) {
            return null;
        }

        const on = item.providers ?? [];

        return item.providerOptions.map((option) => {
            const enabled = on.includes(option.value);
            // The last provider on stays on. Turn Door locks off to stop using both.
            const last = enabled && on.length === 1;

            return (
                <FeatureRow
                    key={option.value}
                    child
                    readOnly={readOnly}
                    label={option.label}
                    description={`${providerNotes[option.value] ?? ''}${
                        !readOnly && last
                            ? ' At least one provider must stay on. Turn Door locks off to stop using both.'
                            : ''
                    }`}
                    checked={enabled}
                    switchLabel={
                        last
                            ? `${option.label} stays on while Door locks is on`
                            : `Turn ${option.label} ${enabled ? 'off' : 'on'}`
                    }
                    onChange={
                        last
                            ? undefined
                            : (checked) =>
                                  toggle(
                                      item.feature,
                                      true,
                                      checked
                                          ? [...on, option.value]
                                          : on.filter(
                                                (value) =>
                                                    value !== option.value,
                                            ),
                                  )
                    }
                />
            );
        });
    };

    const modeRow = (item: PropertyFeature) =>
        item.feature === 'booking-engine' &&
        item.active &&
        bookingEngineMode && (
            <FeatureRow
                child
                readOnly={readOnly}
                label="Booking engine mode"
                description="A microsite run by Estatell, or the owner's own website through the API."
                checked
                switchLabel={`Booking engine mode is on while ${item.label} is on`}
                setting={
                    <BookingEngineModeControl
                        code={code}
                        mode={bookingEngineMode}
                        readOnly={readOnly}
                    />
                }
            />
        );

    return (
        <div className="flex flex-col gap-4">
            <PanelHeading
                icon={ToggleRight}
                title="Features"
                description={
                    readOnly
                        ? 'The modules this property uses. Ask Estatell support to change them.'
                        : 'Turn on the modules this property uses. A feature’s settings show under it while it is on.'
                }
            />
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-16">On</TableHead>
                            <TableHead>Feature</TableHead>
                            <TableHead className="text-right">
                                Setting
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {features.map((item) => (
                            <Fragment key={item.feature}>
                                <FeatureRow
                                    readOnly={readOnly}
                                    icon={featureIcons[item.feature]}
                                    label={item.label}
                                    description={
                                        item.feature === 'e-invoicing' ? (
                                            <>
                                                {item.description}{' '}
                                                <Link
                                                    href={eInvoiceGuide()}
                                                    className="font-medium text-primary underline underline-offset-4 hover:text-primary/80"
                                                >
                                                    Read official guide &rarr;
                                                </Link>
                                            </>
                                        ) : (
                                            item.description
                                        )
                                    }
                                    checked={item.active}
                                    switchLabel={`Turn ${item.label} ${item.active ? 'off' : 'on'}`}
                                    onChange={(active) =>
                                        toggle(item.feature, active)
                                    }
                                />
                                {providerRows(item)}
                                {modeRow(item)}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
