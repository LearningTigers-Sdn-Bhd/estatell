import { Head, setLayoutProps, useForm } from '@inertiajs/react';
import { Info, Plus, Trash2 } from 'lucide-react';
import ChannelManagerController from '@/actions/App/Http/Controllers/Onboarding/ChannelManagerController';
import Heading from '@/components/heading';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type SavedRow = App.Data.Onboarding.OnboardingOtaCredentialRowData;
type Channel = App.Data.Onboarding.OnboardingOtaChannelData;
type Row = {
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
};

const FORM_ID = 'channel-manager-form';
let counter = 0;

export default function ChannelManager({
    savedRows,
    channels,
    maxRows,
    locked,
    blockedBy,
}: {
    savedRows: SavedRow[];
    channels: Channel[];
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const blankRow = (): Row => ({
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
    });

    const fromSaved = (saved: SavedRow): Row => ({
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
    });

    const form = useForm<{ rows: Row[] }>({
        rows: savedRows.map(fromSaved),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;
    const channelItems = channels.map((channel) => ({
        value: String(channel.id),
        label: channel.name,
    }));

    setLayoutProps<OnboardingLayoutProps>({
        step: 'channel_manager',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '7xl',
    });

    const setRow = (index: number, values: Partial<Row>) =>
        form.setData(
            'rows',
            rows.map((row, position) =>
                position === index ? { ...row, ...values } : row,
            ),
        );

    const removeRow = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const isOther = (row: Row) =>
        channels.some(
            (channel) =>
                String(channel.id) === row.ota_channel_id && channel.isOther,
        );

    const channelLabel = (row: Row) => {
        if (row.ota_channel_id === '') {
            return null;
        }

        return (
            channels.find(
                (channel) => String(channel.id) === row.ota_channel_id,
            )?.name ?? row.channel_label
        );
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            rows: data.rows.map(
                ({
                    key: _key,
                    channel_label: _label,
                    on_file: _onFile,
                    changing_password: _changing,
                    ...row
                }) => row,
            ),
        }));
        form.put(ChannelManagerController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    return (
        <>
            <Head title="OTA lists" />
            <Heading
                title="OTA lists"
                description="Which online travel agencies (OTAs) do you sell through? Add the login for each one. The estatell team connects them after you go live. Skip this if you don't sell through an OTA."
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <Alert>
                    <Info />
                    <AlertDescription>
                        Only the estatell team reads these. Passwords are stored
                        encrypted and are never shown again here. The team
                        connects your channels through our channel manager after
                        approval. Nothing is connected from this page.
                    </AlertDescription>
                </Alert>

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
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        No channels yet. Leave this empty and
                                        continue if you don't sell through any
                                        OTA.
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((row, index) => {
                                const fieldError = (name: string) =>
                                    errors[`rows.${index}.${name}`];
                                const label = channelLabel(row);

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
                                                                    key={
                                                                        item.value
                                                                    }
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
                                                        onChange={(event) =>
                                                            setRow(index, {
                                                                channel_name:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!fieldError(
                                                                'channel_name',
                                                            )
                                                        }
                                                    />
                                                    <FieldError>
                                                        {fieldError(
                                                            'channel_name',
                                                        )}
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
                                                    !!fieldError(
                                                        'property_code',
                                                    )
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
                                                        username:
                                                            event.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    !!fieldError('username')
                                                }
                                            />
                                            <FieldError>
                                                {fieldError('username')}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <span className="mb-1 block text-xs text-muted-foreground md:hidden">
                                                Password
                                            </span>
                                            {row.on_file &&
                                            !row.changing_password ? (
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
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                        aria-invalid={
                                                            !!fieldError(
                                                                'password',
                                                            )
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
                                                                    password:
                                                                        '',
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
                                                    !!fieldError(
                                                        'market_manager_name',
                                                    )
                                                }
                                            />
                                            <FieldError>
                                                {fieldError(
                                                    'market_manager_name',
                                                )}
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
                                                    !!fieldError(
                                                        'market_manager_phone',
                                                    )
                                                }
                                            />
                                            <FieldError>
                                                {fieldError(
                                                    'market_manager_phone',
                                                )}
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
                                                    !!fieldError(
                                                        'market_manager_email',
                                                    )
                                                }
                                            />
                                            <FieldError>
                                                {fieldError(
                                                    'market_manager_email',
                                                )}
                                            </FieldError>
                                        </TableCell>
                                        <TableCell className="align-top max-md:p-0">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove row ${index + 1}`}
                                                onClick={() => removeRow(index)}
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
                                            Total: {rows.length}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={rows.length >= maxRows}
                                            focusableWhenDisabled
                                            onClick={() =>
                                                form.setData('rows', [
                                                    ...rows,
                                                    blankRow(),
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
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="OTA lists"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
