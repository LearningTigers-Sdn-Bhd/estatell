import { useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { useState } from 'react';
import { BookingSourceBadge } from '@/components/pms/booking-source-badge';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import { BookingSourceBadgeFields } from '@/components/pms/settings/booking-source-badge-fields';
import type { BadgeMode } from '@/components/pms/settings/booking-source-badge-fields';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { store, update } from '@/routes/settings/booking-sources';

type Source = App.Data.Reservations.BookingSourceRowData;
type Channel = App.Data.Reservations.BookingSourceChannelOptionData;
type Option = { value: string; label: string };

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The row to edit, or null for a new booking source. */
    source: Source | null;
    types: Option[];
    channels: Channel[];
    icons: Option[];
    colors: Option[];
    /** Closes this Dialog and asks the page to confirm the delete, so only one overlay is open at a time. */
    onDelete: (source: Source) => void;
};

function firstLetter(name: string): string {
    return name.match(/[A-Za-z0-9]/)?.[0]?.toUpperCase() ?? '';
}

function BookingSourceForm({
    open,
    onOpenChange,
    source,
    types,
    channels,
    icons,
    colors,
    onDelete,
}: Props) {
    const locked = source !== null && (source.isSystem || source.usedCount > 0);
    const form = useForm({
        name: source?.badge.name ?? '',
        type: source?.badge.type ?? 'direct',
        ota_channel_id: source?.otaChannelId ? String(source.otaChannelId) : '',
        channel_name: '',
        badge_icon: source?.badge.icon ?? 'globe',
        badge_letters: source?.badge.letters ?? '',
        badge_color: source?.badge.color ?? 'gray',
        is_active: source?.isActive ?? true,
    });
    const [mode, setMode] = useState<BadgeMode>(
        source ? (source.badge.icon ? 'icon' : 'letters') : 'icon',
    );
    const [badgeTouched, setBadgeTouched] = useState(source !== null);

    const channel = channels.find(
        (option) => String(option.id) === form.data.ota_channel_id,
    );
    const isOtherChannel = source === null && !!channel?.isOther;
    const isOta = form.data.type === 'ota';
    const previewName =
        (isOtherChannel ? form.data.channel_name : form.data.name).trim() ||
        'New booking source';

    /** A new source's badge follows its type, channel and name until the badge is edited by hand. */
    const change = (data: typeof form.data, name: string) => {
        if (badgeTouched || source !== null) {
            form.setData(data);

            return;
        }

        const picked = channels.find(
            (option) => String(option.id) === data.ota_channel_id,
        );

        if (data.type === 'ota') {
            setMode('letters');
            form.setData({
                ...data,
                badge_letters: firstLetter(
                    picked && !picked.isOther ? picked.name : name,
                ),
                badge_color: picked?.defaultColor ?? 'gray',
            });

            return;
        }

        setMode('icon');
        form.setData({ ...data, badge_icon: 'globe', badge_color: 'gray' });
    };

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        form.transform((data) => ({
            ...data,
            badge_icon: mode === 'icon' ? data.badge_icon : null,
            badge_letters: mode === 'letters' ? data.badge_letters : null,
        }));

        if (source) {
            form.put(update.url({ bookingSource: source.id }), options);
        } else {
            form.post(store.url(), options);
        }
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={source ? 'Edit booking source' : 'New booking source'}
            description={
                source
                    ? undefined
                    : 'Where a booking came from. Staff pick one on every booking.'
            }
            errors={form.errors}
            processing={form.processing}
            submitLabel={source ? 'Save changes' : 'Create booking source'}
            onSubmit={submit}
        >
            {!isOtherChannel && (
                <Field data-invalid={!!form.errors.name}>
                    <FieldLabel htmlFor="booking-source-name">Name</FieldLabel>
                    {source?.isSystem ? (
                        <p className="flex items-center gap-2 text-sm">
                            {source.badge.name}
                            <Lock
                                aria-label="Locked"
                                className="size-3.5 text-muted-foreground"
                            />
                        </p>
                    ) : (
                        <Input
                            id="booking-source-name"
                            name="name"
                            placeholder="e.g. WhatsApp"
                            maxLength={60}
                            value={form.data.name}
                            onChange={(event) => {
                                const name = event.target.value;

                                change({ ...form.data, name }, name);
                            }}
                            aria-invalid={!!form.errors.name}
                            autoFocus
                        />
                    )}
                    <FieldError>{form.errors.name}</FieldError>
                </Field>
            )}

            {source?.isSystem ? (
                <Field>
                    <FieldLabel>Type</FieldLabel>
                    <p className="flex items-center gap-2 text-sm">
                        {source.typeLabel}
                        <Lock
                            aria-label="Locked"
                            className="size-3.5 text-muted-foreground"
                        />
                    </p>
                </Field>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field data-invalid={!!form.errors.type}>
                        <FieldLabel htmlFor="booking-source-type">
                            Type
                        </FieldLabel>
                        {locked ? (
                            <p className="flex items-center gap-2 text-sm">
                                {source?.typeLabel}
                                <Lock
                                    aria-label="Locked"
                                    className="size-3.5 text-muted-foreground"
                                />
                            </p>
                        ) : (
                            <EnumSelect
                                id="booking-source-type"
                                name="type"
                                options={types}
                                value={form.data.type}
                                onValueChange={(type) =>
                                    change(
                                        {
                                            ...form.data,
                                            type: type as typeof form.data.type,
                                            ota_channel_id: '',
                                        },
                                        form.data.name,
                                    )
                                }
                                aria-invalid={!!form.errors.type}
                            />
                        )}
                        <FieldError>{form.errors.type}</FieldError>
                    </Field>
                    {isOta && (
                        <Field data-invalid={!!form.errors.ota_channel_id}>
                            <FieldLabel htmlFor="booking-source-channel">
                                OTA channel
                            </FieldLabel>
                            {locked ? (
                                <p className="flex items-center gap-2 text-sm">
                                    {source?.otaChannelName}
                                    <Lock
                                        aria-label="Locked"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                </p>
                            ) : (
                                <EnumSelect
                                    id="booking-source-channel"
                                    name="ota_channel_id"
                                    placeholder="e.g. Booking.com"
                                    options={channels.map((option) => ({
                                        value: String(option.id),
                                        label: option.name,
                                    }))}
                                    value={form.data.ota_channel_id}
                                    onValueChange={(id) =>
                                        change(
                                            {
                                                ...form.data,
                                                ota_channel_id: id,
                                            },
                                            form.data.channel_name ||
                                                form.data.name,
                                        )
                                    }
                                    aria-invalid={!!form.errors.ota_channel_id}
                                />
                            )}
                            <FieldError>
                                {form.errors.ota_channel_id}
                            </FieldError>
                        </Field>
                    )}
                </div>
            )}

            {isOtherChannel && (
                <Field
                    data-invalid={
                        !!(form.errors.channel_name || form.errors.name)
                    }
                >
                    <FieldLabel htmlFor="booking-source-channel-name">
                        Channel name
                    </FieldLabel>
                    <Input
                        id="booking-source-channel-name"
                        name="channel_name"
                        placeholder="e.g. Traveloka"
                        maxLength={60}
                        value={form.data.channel_name}
                        onChange={(event) => {
                            const channelName = event.target.value;

                            change(
                                { ...form.data, channel_name: channelName },
                                channelName,
                            );
                        }}
                        aria-invalid={
                            !!(form.errors.channel_name || form.errors.name)
                        }
                        autoFocus
                    />
                    <FieldError>
                        {form.errors.channel_name ?? form.errors.name}
                    </FieldError>
                </Field>
            )}

            <BookingSourceBadgeFields
                mode={mode}
                onModeChange={(next) => {
                    setBadgeTouched(true);
                    setMode(next);
                }}
                icon={form.data.badge_icon}
                onIconChange={(icon) => {
                    setBadgeTouched(true);
                    form.setData(
                        'badge_icon',
                        icon as typeof form.data.badge_icon,
                    );
                }}
                letters={form.data.badge_letters}
                onLettersChange={(letters) => {
                    setBadgeTouched(true);
                    form.setData('badge_letters', letters);
                }}
                color={form.data.badge_color}
                onColorChange={(color) => {
                    setBadgeTouched(true);
                    form.setData(
                        'badge_color',
                        color as typeof form.data.badge_color,
                    );
                }}
                icons={icons}
                colors={colors}
                errors={form.errors}
            />

            <div className="flex items-center gap-3">
                <span className="text-sm font-medium">Preview</span>
                <BookingSourceBadge
                    source={{
                        name: previewName,
                        icon: mode === 'icon' ? form.data.badge_icon : null,
                        letters:
                            mode === 'letters' ? form.data.badge_letters : null,
                        color: form.data.badge_color,
                    }}
                />
                <span className="text-sm">{previewName}</span>
            </div>

            {source && source.usedCount > 0 && (
                <p className="text-sm text-muted-foreground">
                    {source.isSystem
                        ? `Used by ${source.usedCount} ${source.usedCount === 1 ? 'booking' : 'bookings'}.`
                        : `In use by ${source.usedCount} ${source.usedCount === 1 ? 'booking' : 'bookings'}. Turn it off with Available.`}
                </p>
            )}
            {source && !source.isSystem && source.usedCount === 0 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
                        No booking uses this source.
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => onDelete(source)}
                    >
                        Delete booking source
                    </Button>
                </div>
            )}
        </FormOverlay>
    );
}

/**
 * Create or edit a booking source. The inner form is remounted each time the Dialog opens, and keeps the last row while
 * the Dialog closes.
 */
export function BookingSourceDialog(props: Props) {
    const key = useFreshOnOpen(props.open);
    const source = useRetainedValue(props.source);

    return (
        <BookingSourceForm
            key={`${key}-${source?.id ?? 'new'}`}
            {...props}
            source={props.open || props.source !== null ? props.source : source}
        />
    );
}
