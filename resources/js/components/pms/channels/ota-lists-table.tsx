import { Plus, Trash2 } from 'lucide-react';
import { StatusBadge } from '@/components/pms/status-badge';
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

/**
 * One row of the table. The fields after `key` are sent to the server, except the UI-only ones: `key`, `channel_label`,
 * `on_file` and `changing_password`, and `status`, which the server sends back.
 */
export type OtaListRow = {
    key: string;
    id: number | null;
    ota_channel_id: string;
    channel_name: string;
    property_code: string;
    username: string;
    password: string;
    market_manager_name: string;
    market_manager_phone: string;
    market_manager_email: string;
    /** UI only: the label of a saved channel that is no longer offered. */
    channel_label: string;
    /** UI only: a saved row, shown as "On file" until Change is pressed. */
    on_file: boolean;
    changing_password: boolean;
    /** UI only: the saved status. Null for a row that is not saved yet, which is always Pending once saved. */
    status: App.Data.Shared.BadgeData | null;
};

let counter = 0;

export function blankOtaListRow(): OtaListRow {
    return {
        key: `new-${++counter}`,
        id: null,
        ota_channel_id: '',
        channel_name: '',
        property_code: '',
        username: '',
        password: '',
        market_manager_name: '',
        market_manager_phone: '',
        market_manager_email: '',
        channel_label: '',
        on_file: false,
        changing_password: false,
        status: null,
    };
}

export function otaListRowFromSaved(saved: OtaListSavedRow): OtaListRow {
    return {
        key: `saved-${saved.id}`,
        id: saved.id,
        ota_channel_id: String(saved.otaChannelId),
        channel_name: saved.channelName ?? '',
        property_code: saved.propertyCode,
        username: saved.username,
        password: '',
        market_manager_name: saved.marketManagerName ?? '',
        market_manager_phone: saved.marketManagerPhone ?? '',
        market_manager_email: saved.marketManagerEmail ?? '',
        channel_label: saved.channelLabel,
        on_file: true,
        changing_password: false,
        status: saved.status,
    };
}

/** The rows as the server takes them: no UI-only fields. */
export function otaListSubmitRows(rows: OtaListRow[]) {
    return rows.map(
        ({
            key: _key,
            channel_label: _label,
            on_file: _onFile,
            changing_password: _changing,
            status: _status,
            ...row
        }) => row,
    );
}

/** The channel's name, or the label of a saved channel that is no longer offered. */
export function otaListChannelLabel(
    row: OtaListRow,
    channels: OtaListChannel[],
): string | null {
    if (row.ota_channel_id === '') {
        return null;
    }

    return (
        channels.find((channel) => String(channel.id) === row.ota_channel_id)
            ?.name ?? row.channel_label
    );
}

/**
 * The OTA lists table: one row per channel, the write-only password and the add button. The owner's page and the
 * onboarding page both use it. The parent keeps the rows and decides what removing a row does.
 */
export function OtaListsTable({
    rows,
    channels,
    maxRows,
    errors,
    showStatus = false,
    lockSavedChannels = false,
    onRowsChange,
    onRemoveRow,
}: {
    rows: OtaListRow[];
    channels: OtaListChannel[];
    maxRows: number;
    errors: Record<string, string | undefined>;
    /** Settings shows the saved status. Onboarding does not. */
    showStatus?: boolean;
    /** Settings locks the channel of a saved row. Remove the row and add a new one to change it. */
    lockSavedChannels?: boolean;
    onRowsChange: (rows: OtaListRow[]) => void;
    onRemoveRow: (index: number) => void;
}) {
    const channelItems = channels.map((channel) => ({
        value: String(channel.id),
        label: channel.name,
    }));
    const columnCount = showStatus ? 9 : 8;

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
                        {showStatus && <TableHead>Status</TableHead>}
                        <TableHead>
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.length === 0 && (
                        <TableRow>
                            <TableCell
                                colSpan={columnCount}
                                className="py-6 text-center text-muted-foreground"
                            >
                                No channels yet. Leave this empty and continue
                                if you don't sell through any OTA.
                            </TableCell>
                        </TableRow>
                    )}
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
                                    {lockSavedChannels && row.on_file ? (
                                        <span className="block pt-1.5 max-md:pt-0">
                                            {label}
                                        </span>
                                    ) : (
                                        <Select
                                            items={channelItems}
                                            value={row.ota_channel_id}
                                            onValueChange={(value) =>
                                                setRow(index, {
                                                    ota_channel_id:
                                                        value as string,
                                                })
                                            }
                                        >
                                            <SelectTrigger
                                                aria-label={`Channel of row ${index + 1}`}
                                                className="w-full"
                                                aria-invalid={
                                                    !!fieldError(
                                                        'ota_channel_id',
                                                    ) || !!fieldError('id')
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
                                                    {channelItems.map(
                                                        (item) => (
                                                            <SelectItem
                                                                key={item.value}
                                                                value={
                                                                    item.value
                                                                }
                                                            >
                                                                {item.label}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectGroup>
                                            </SelectContent>
                                        </Select>
                                    )}
                                    <FieldError>
                                        {fieldError('ota_channel_id') ??
                                            fieldError('id')}
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
                                                readOnly={
                                                    lockSavedChannels &&
                                                    row.on_file
                                                }
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
                                    {row.on_file && !row.changing_password ? (
                                        <div className="flex items-center gap-2 pt-1.5 max-md:pt-0">
                                            <span className="text-muted-foreground">
                                                On file
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    setRow(index, {
                                                        changing_password: true,
                                                    })
                                                }
                                            >
                                                Change
                                            </Button>
                                        </div>
                                    ) : (
                                        <>
                                            <Input
                                                type="password"
                                                aria-label={`Password of row ${index + 1}`}
                                                placeholder="e.g. the extranet password"
                                                maxLength={255}
                                                autoComplete="new-password"
                                                value={row.password}
                                                onChange={(event) =>
                                                    setRow(index, {
                                                        password:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError('password')
                                                }
                                            />
                                            {row.on_file && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="mt-1"
                                                    onClick={() =>
                                                        setRow(index, {
                                                            password: '',
                                                            changing_password: false,
                                                        })
                                                    }
                                                >
                                                    Keep saved
                                                </Button>
                                            )}
                                        </>
                                    )}
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
                                {showStatus && (
                                    <TableCell className="align-top max-md:p-0">
                                        <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                            Status
                                        </span>
                                        {row.status === null ? (
                                            <span className="text-sm text-muted-foreground">
                                                Pending after save
                                            </span>
                                        ) : (
                                            <div className="flex flex-col items-start gap-1">
                                                <StatusBadge
                                                    status={row.status}
                                                />
                                                {row.on_file &&
                                                    row.password !== '' && (
                                                        <span className="text-xs text-muted-foreground">
                                                            Pending after save
                                                        </span>
                                                    )}
                                            </div>
                                        )}
                                    </TableCell>
                                )}
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
                        <TableCell colSpan={columnCount}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-sm text-muted-foreground">
                                    Total: {rows.length}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={rows.length >= maxRows}
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
