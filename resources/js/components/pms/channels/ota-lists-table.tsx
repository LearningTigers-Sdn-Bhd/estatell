import { Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

export type OtaListChannel = App.Data.Onboarding.OnboardingOtaChannelData;
export type OtaListSavedRow =
    App.Data.Onboarding.OnboardingOtaCredentialRowData;

/** One new row of the table. Every field after `key` is sent to the server. */
export type OtaListRow = {
    key: string;
    ota_channel_id: string;
    channel_name: string;
    property_code: string;
    username: string;
    password: string;
    market_manager_name: string;
    market_manager_phone: string;
    market_manager_email: string;
};

let counter = 0;

export function blankOtaListRow(): OtaListRow {
    return {
        key: `new-${++counter}`,
        ota_channel_id: '',
        channel_name: '',
        property_code: '',
        username: '',
        password: '',
        market_manager_name: '',
        market_manager_phone: '',
        market_manager_email: '',
    };
}

/** The rows as the server takes them: no UI-only fields. */
export function otaListSubmitRows(rows: OtaListRow[]) {
    return rows.map(({ key: _key, ...row }) => row);
}

function otaListChannelLabel(
    row: OtaListRow,
    channels: OtaListChannel[],
): string | null {
    return (
        channels.find((channel) => String(channel.id) === row.ota_channel_id)
            ?.name ?? null
    );
}

/**
 * The onboarding OTA lists table. A saved row shows only its channel, because saved logins are for the Estatell team. New
 * rows have every field. The parent keeps the rows.
 */
export function OtaListsTable({
    savedRows,
    rows,
    channels,
    maxRows,
    errors,
    onRowsChange,
    onRemoveSavedRow,
    onRemoveRow,
}: {
    savedRows: OtaListSavedRow[];
    rows: OtaListRow[];
    channels: OtaListChannel[];
    maxRows: number;
    errors: Record<string, string | undefined>;
    onRowsChange: (rows: OtaListRow[]) => void;
    onRemoveSavedRow: (id: number) => void;
    onRemoveRow: (index: number) => void;
}) {
    const channelItems = channels.map((channel) => ({
        value: String(channel.id),
        label: channel.name,
    }));
    const total = savedRows.length + rows.length;

    const setRow = (index: number, values: Partial<OtaListRow>) =>
        onRowsChange(
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const isOther = (row: OtaListRow) =>
        channels.some(
            (channel) =>
                String(channel.id) === row.ota_channel_id && channel.isOther,
        );

    return (
        <div className="overflow-hidden rounded-lg border">
            <Table>
                <TableHeader className="max-md:hidden">
                    <TableRow>
                        <TableHead>Channel</TableHead>
                        <TableHead>Property ID</TableHead>
                        <TableHead>Username</TableHead>
                        <TableHead>Password</TableHead>
                        <TableHead>Market manager</TableHead>
                        <TableHead>Phone</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {total === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={8}
                                className="py-6 text-center text-muted-foreground"
                            >
                                No channels yet. Leave this empty and continue
                                if you don't sell through any OTA.
                            </TableCell>
                        </TableRow>
                    )}
                    {savedRows.map((saved) => (
                        <TableRow
                            key={`saved-${saved.id}`}
                            className="max-md:grid max-md:gap-3 max-md:p-3"
                        >
                            <TableCell className="align-top max-md:p-0">
                                <span className="block pt-1.5 max-md:pt-0">
                                    {saved.channelLabel}
                                </span>
                            </TableCell>
                            <TableCell
                                colSpan={6}
                                className="align-top text-muted-foreground max-md:p-0"
                            >
                                <span className="block pt-1.5 whitespace-normal max-md:pt-0">
                                    Saved. Only the Estatell team can see this
                                    login.
                                </span>
                            </TableCell>
                            <TableCell className="align-top max-md:p-0">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove ${saved.channelLabel}`}
                                    onClick={() => onRemoveSavedRow(saved.id)}
                                >
                                    <Trash2 />
                                </Button>
                            </TableCell>
                        </TableRow>
                    ))}
                    {rows.map((row, index) => {
                        const fieldError = (name: string) =>
                            errors[`rows.${index}.${name}`];
                        const label = otaListChannelLabel(row, channels);

                        return (
                            <TableRow
                                key={row.key}
                                className="max-md:grid max-md:gap-3 max-md:p-3"
                            >
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Channel
                                    </span>
                                    <Select
                                        items={channelItems}
                                        value={row.ota_channel_id}
                                        onValueChange={(value) =>
                                            setRow(index, {
                                                ota_channel_id: value as string,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            aria-label={`Channel of row ${index + 1}`}
                                            className="w-full"
                                            aria-invalid={
                                                !!fieldError('ota_channel_id')
                                            }
                                        >
                                            <SelectValue>
                                                {label ?? (
                                                    <span className="text-muted-foreground">
                                                        Choose a channel
                                                    </span>
                                                )}
                                            </SelectValue>
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {channelItems.map((item) => (
                                                    <SelectItem
                                                        key={item.value}
                                                        value={item.value}
                                                    >
                                                        {item.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectGroup>
                                        </SelectContent>
                                    </Select>
                                    <FieldError>
                                        {fieldError('ota_channel_id')}
                                    </FieldError>
                                    {isOther(row) && (
                                        <div className="mt-2">
                                            <span className="mb-1 block text-xs text-muted-foreground">
                                                Channel name
                                            </span>
                                            <Input
                                                aria-label={`Channel name of row ${index + 1}`}
                                                placeholder="e.g. Traveloka"
                                                maxLength={80}
                                                value={row.channel_name}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        channel_name:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError('channel_name')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('channel_name')}
                                            </FieldError>
                                        </div>
                                    )}
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Property ID
                                    </span>
                                    <Input
                                        aria-label={`Property ID of row ${index + 1}`}
                                        placeholder="e.g. 123456"
                                        maxLength={80}
                                        value={row.property_code}
                                        onChange={(event) =>
                                            setRow(index, {
                                                property_code:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError('property_code')
                                        }
                                    />
                                    <FieldError>
                                        {fieldError('property_code')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Username
                                    </span>
                                    <Input
                                        aria-label={`Username of row ${index + 1}`}
                                        placeholder="e.g. frontdesk@seaview.my"
                                        maxLength={255}
                                        autoComplete="off"
                                        value={row.username}
                                        onChange={(event) =>
                                            setRow(index, {
                                                username: event.target.value,
                                            })
                                        }
                                        aria-invalid={!!fieldError('username')}
                                    />
                                    <FieldError>
                                        {fieldError('username')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Password
                                    </span>
                                    <Input
                                        type="password"
                                        aria-label={`Password of row ${index + 1}`}
                                        placeholder="e.g. the extranet password"
                                        maxLength={255}
                                        autoComplete="new-password"
                                        value={row.password}
                                        onChange={(event) =>
                                            setRow(index, {
                                                password: event.target.value,
                                            })
                                        }
                                        aria-invalid={!!fieldError('password')}
                                    />
                                    <FieldError>
                                        {fieldError('password')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Market manager
                                    </span>
                                    <Input
                                        aria-label={`Market manager of row ${index + 1}`}
                                        placeholder="e.g. Aisha Rahman"
                                        maxLength={255}
                                        value={row.market_manager_name}
                                        onChange={(event) =>
                                            setRow(index, {
                                                market_manager_name:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError('market_manager_name')
                                        }
                                    />
                                    <FieldError>
                                        {fieldError('market_manager_name')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Phone
                                    </span>
                                    <Input
                                        aria-label={`Market manager phone of row ${index + 1}`}
                                        placeholder="e.g. +60 12 345 6789"
                                        maxLength={40}
                                        value={row.market_manager_phone}
                                        onChange={(event) =>
                                            setRow(index, {
                                                market_manager_phone:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError('market_manager_phone')
                                        }
                                    />
                                    <FieldError>
                                        {fieldError('market_manager_phone')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                        Email
                                    </span>
                                    <Input
                                        aria-label={`Market manager email of row ${index + 1}`}
                                        type="email"
                                        placeholder="e.g. aisha@agoda-partner.com"
                                        maxLength={255}
                                        value={row.market_manager_email}
                                        onChange={(event) =>
                                            setRow(index, {
                                                market_manager_email:
                                                    event.target.value,
                                            })
                                        }
                                        aria-invalid={
                                            !!fieldError('market_manager_email')
                                        }
                                    />
                                    <FieldError>
                                        {fieldError('market_manager_email')}
                                    </FieldError>
                                </TableCell>
                                <TableCell className="align-top max-md:p-0">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={`Remove row ${index + 1}`}
                                        onClick={() => onRemoveRow(index)}
                                    >
                                        <Trash2 />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
                <TableFooter>
                    <TableRow>
                        <TableCell colSpan={8}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-sm text-muted-foreground">
                                    Total: {total}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={total >= maxRows}
                                    focusableWhenDisabled
                                    onClick={() =>
                                        onRowsChange([
                                            ...rows,
                                            blankOtaListRow(),
                                        ])
                                    }
                                >
                                    <Plus data-icon="inline-start" />
                                    Add channel
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableFooter>
            </Table>
        </div>
    );
}
