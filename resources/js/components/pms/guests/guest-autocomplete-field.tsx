import {
    ArrowDown,
    ArrowUp,
    CornerDownLeft,
    TriangleAlert,
    UserRound,
} from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { PhoneInput } from '@/components/pms/phone-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Kbd, KbdGroup } from '@/components/ui/kbd';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import {
    GUEST_SEARCH_MIN_LENGTH,
    useGuestSearch,
} from '@/hooks/use-guest-search';
import { cn } from '@/lib/utils';

type Guest = App.Data.Guests.GuestOptionData;
type GuestField = 'full_name' | 'phone' | 'email';

type Props = {
    idPrefix: string;
    values: Record<GuestField, string>;
    /** The linked guest, or null while the details are for a new guest. */
    linked: Pick<
        Guest,
        | 'id'
        | 'fullName'
        | 'phone'
        | 'email'
        | 'isVip'
        | 'isBlacklisted'
        | 'profile'
    > | null;
    errors: Partial<Record<GuestField | 'guest_id', string>>;
    onChange: (field: GuestField, value: string) => void;
    onPick: (guest: Guest) => void;
    onUnlink: () => void;
    /** Called when a field is left, for the duplicate check. */
    onLeave?: () => void;
    /** Without `guests.manage` the fields only search: a guest has to be picked. */
    existingOnly?: boolean;
    /** Extra classes for each label, for example `lg:sr-only` when a table header names the column. */
    labelClassName?: Partial<Record<GuestField, string>>;
    /** Shown at the end of the Name input, for example the Booker and VIP badges. */
    nameBadges?: ReactNode;
    /** Receives the parts so the caller decides where each one goes. */
    children: (parts: GuestAutocompleteParts) => ReactNode;
};

export type GuestAutocompleteParts = {
    name: ReactNode;
    phone: ReactNode;
    email: ReactNode;
    /** The card of the linked guest with the blacklist warning, or null. */
    linked: ReactNode;
    /** The hint for staff who may only pick guests, and the guest error. */
    notes: ReactNode;
};

/**
 * Name, Phone and Email of a guest, each searching the property's guests as the user types. Typing never forces a pick:
 * Escape keeps the typed text as a new guest. Picking a guest links the row and fills the fields, and Change guest
 * unlinks it again and keeps what was typed. The component keeps the search state and hands the parts to `children`,
 * so a table row can put Name and Phone in its cells and Email in the expanded part. The match list floats under the
 * field being typed in.
 */
export function GuestAutocompleteField({
    idPrefix,
    values,
    linked,
    errors,
    onChange,
    onPick,
    onUnlink,
    onLeave,
    existingOnly = false,
    labelClassName = {},
    nameBadges,
    children,
}: Props) {
    const [focused, setFocused] = useState<GuestField | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const [highlight, setHighlight] = useState(0);
    const nameInput = useRef<HTMLInputElement>(null);
    const text = focused && linked === null ? values[focused] : '';
    const { results, searching } = useGuestSearch(text);
    const open =
        !dismissed &&
        linked === null &&
        focused !== null &&
        text.trim().length >= GUEST_SEARCH_MIN_LENGTH &&
        (searching || results.length > 0);

    const pick = (guest: Guest) => {
        onPick(guest);
        setFocused(null);
        setHighlight(0);
    };

    const keys = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (!open || results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setHighlight((current) => (current + 1) % results.length);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setHighlight(
                (current) => (current - 1 + results.length) % results.length,
            );
        } else if (event.key === 'Enter') {
            event.preventDefault();
            pick(results[Math.min(highlight, results.length - 1)]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setDismissed(true);
        }
    };

    const focus = (field: GuestField) => () => {
        setFocused(field);
        setDismissed(false);
        setHighlight(0);
    };
    const leave = () => {
        setFocused(null);
        onLeave?.();
    };

    const listId = `${idPrefix}-guests`;
    const optionId = (guest: Guest) => `${idPrefix}-guest-${guest.id}`;
    const combobox = (field: GuestField) => {
        const expanded = open && focused === field;

        return {
            role: 'combobox' as const,
            'aria-autocomplete': 'list' as const,
            'aria-expanded': expanded,
            'aria-controls': expanded ? listId : undefined,
            'aria-activedescendant':
                expanded && results[highlight]
                    ? optionId(results[highlight])
                    : undefined,
        };
    };

    const list = (field: GuestField) =>
        open &&
        focused === field && (
            <>
                <span role="status" className="sr-only">
                    {searching && results.length === 0
                        ? 'Searching for guests'
                        : `${results.length} ${results.length === 1 ? 'guest' : 'guests'} found`}
                </span>
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="Existing guests"
                    className="absolute top-full left-0 z-50 mt-1 max-h-72 w-80 max-w-[calc(100vw-2rem)] overflow-y-auto rounded-lg border bg-popover p-1 text-popover-foreground shadow-md"
                    onMouseDown={(event) => event.preventDefault()}
                >
                    {searching && results.length === 0 && (
                        <li
                            aria-hidden
                            className="px-2 py-1.5 text-sm text-muted-foreground"
                        >
                            Searching…
                        </li>
                    )}
                    {results.map((guest, index) => (
                        <li
                            key={guest.id}
                            id={optionId(guest)}
                            role="option"
                            aria-selected={index === highlight}
                            className={cn(
                                'flex cursor-pointer flex-col rounded-md px-2 py-1.5 text-sm',
                                index === highlight &&
                                    'bg-accent text-accent-foreground',
                            )}
                            onMouseEnter={() => setHighlight(index)}
                            onClick={() => pick(guest)}
                        >
                            <span className="flex items-center gap-2">
                                {guest.fullName}
                                {guest.isVip && <Badge>VIP</Badge>}
                                {guest.isBlacklisted && (
                                    <Badge variant="destructive">
                                        Blacklisted
                                    </Badge>
                                )}
                            </span>
                            <span
                                className={cn(
                                    'text-xs',
                                    index === highlight
                                        ? 'text-accent-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {[guest.phone, guest.email]
                                    .filter(Boolean)
                                    .join(' · ') || 'No contact details'}
                            </span>
                        </li>
                    ))}
                    <li
                        aria-hidden
                        className="flex flex-wrap items-center gap-x-3 gap-y-1 px-2 py-1 text-xs text-muted-foreground"
                    >
                        <span className="flex items-center gap-1">
                            <KbdGroup>
                                <Kbd>
                                    <ArrowUp />
                                </Kbd>
                                <Kbd>
                                    <ArrowDown />
                                </Kbd>
                            </KbdGroup>
                            Move
                        </span>
                        <span className="flex items-center gap-1">
                            <Kbd>
                                <CornerDownLeft />
                            </Kbd>
                            Select
                        </span>
                        <span className="flex items-center gap-1">
                            <Kbd>Esc</Kbd>
                            Keep typing as a new guest
                        </span>
                    </li>
                </ul>
            </>
        );

    return children({
        name: (
            <Field data-invalid={!!errors.full_name} className="relative">
                <FieldLabel
                    htmlFor={`${idPrefix}-full_name`}
                    className={labelClassName.full_name}
                >
                    Name
                </FieldLabel>
                <InputGroup>
                    <InputGroupInput
                        ref={nameInput}
                        id={`${idPrefix}-full_name`}
                        value={values.full_name}
                        onChange={(event) =>
                            onChange('full_name', event.target.value)
                        }
                        onFocus={focus('full_name')}
                        onBlur={leave}
                        onKeyDown={keys}
                        maxLength={150}
                        placeholder="Aisyah binti Rahman"
                        autoComplete="off"
                        {...combobox('full_name')}
                        aria-invalid={!!errors.full_name}
                    />
                    {nameBadges && (
                        <InputGroupAddon align="inline-end">
                            {nameBadges}
                        </InputGroupAddon>
                    )}
                </InputGroup>
                <FieldError>{errors.full_name}</FieldError>
                {list('full_name')}
            </Field>
        ),
        phone: (
            <Field data-invalid={!!errors.phone} className="relative">
                <FieldLabel
                    htmlFor={`${idPrefix}-phone`}
                    className={labelClassName.phone}
                >
                    Phone
                </FieldLabel>
                <PhoneInput
                    id={`${idPrefix}-phone`}
                    value={values.phone}
                    onChange={(value) => onChange('phone', value)}
                    onFocus={focus('phone')}
                    onBlur={leave}
                    onKeyDown={keys}
                    invalid={!!errors.phone}
                    inputProps={combobox('phone')}
                />
                <FieldError>{errors.phone}</FieldError>
                {list('phone')}
            </Field>
        ),
        email: (
            <Field data-invalid={!!errors.email} className="relative">
                <FieldLabel
                    htmlFor={`${idPrefix}-email`}
                    className={labelClassName.email}
                >
                    Email
                </FieldLabel>
                <Input
                    id={`${idPrefix}-email`}
                    type="email"
                    value={values.email}
                    onChange={(event) => onChange('email', event.target.value)}
                    onFocus={focus('email')}
                    onBlur={leave}
                    onKeyDown={keys}
                    maxLength={255}
                    placeholder="guest@example.com"
                    autoComplete="off"
                    {...combobox('email')}
                    aria-invalid={!!errors.email}
                />
                <FieldError>{errors.email}</FieldError>
                {list('email')}
            </Field>
        ),
        linked: linked && (
            <div className="flex flex-col gap-2 rounded-lg border bg-muted/40 p-3">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                    <UserRound
                        className="size-4 text-muted-foreground"
                        aria-hidden
                    />
                    <span className="font-medium">{linked.fullName}</span>
                    {linked.phone && <span>· {linked.phone}</span>}
                    {linked.email && <span>· {linked.email}</span>}
                    {linked.isVip && <Badge>VIP</Badge>}
                    {linked.isBlacklisted && (
                        <Badge variant="destructive">Blacklisted</Badge>
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        size="xs"
                        className="ml-auto"
                        onClick={() => {
                            onUnlink();
                            nameInput.current?.focus();
                        }}
                    >
                        Change guest
                    </Button>
                </div>
                {linked.isBlacklisted && (
                    <Alert variant="destructive">
                        <TriangleAlert aria-hidden />
                        <AlertDescription>
                            This guest is blacklisted
                            {linked.profile.blacklistReason
                                ? `: "${linked.profile.blacklistReason}".`
                                : '.'}{' '}
                            You can still book them.
                        </AlertDescription>
                    </Alert>
                )}
            </div>
        ),
        notes: (
            <>
                {existingOnly && linked === null && (
                    <FieldDescription>
                        Search and pick an existing guest. You may not add new
                        guests.
                    </FieldDescription>
                )}
                {errors.guest_id && (
                    <p className="text-sm text-destructive">
                        {errors.guest_id}
                    </p>
                )}
            </>
        ),
    });
}
