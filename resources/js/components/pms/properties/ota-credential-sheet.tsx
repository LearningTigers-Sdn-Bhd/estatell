import { useForm } from '@inertiajs/react';
import PropertyOtaListController from '@/actions/App/Http/Controllers/Admin/PropertyOtaListController';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { useRetainedValue } from '@/components/pms/use-retained-value';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Credential = App.Data.Platform.AdminOtaListRowData;
type Channel = App.Data.Onboarding.OnboardingOtaChannelData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    code: string;
    /** The row to edit, or null for a new one. A saved row keeps its channel. */
    credential: Credential | null;
    channels: Channel[];
};

function OtaCredentialForm({
    open,
    onOpenChange,
    code,
    credential,
    channels,
}: Props) {
    const form = useForm({
        ota_channel_id: '',
        channel_name: '',
        property_code: credential?.propertyCode ?? '',
        username: credential?.username ?? '',
        password: '',
        market_manager_name: credential?.marketManagerName ?? '',
        market_manager_phone: credential?.marketManagerPhone ?? '',
        market_manager_email: credential?.marketManagerEmail ?? '',
    });
    const items = channels.map((channel) => ({
        value: String(channel.id),
        label: channel.name,
    }));
    const channel = channels.find(
        (option) => String(option.id) === form.data.ota_channel_id,
    );

    const submit = () => {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        };

        if (credential) {
            form.put(
                PropertyOtaListController.update.url({
                    property: code,
                    credential: credential.id,
                }),
                options,
            );
        } else {
            form.post(
                PropertyOtaListController.store.url({ property: code }),
                options,
            );
        }
    };

    return (
        <FormOverlay
            variant="sheet"
            open={open}
            onOpenChange={onOpenChange}
            title={credential ? `Edit ${credential.channel}` : 'Add channel'}
            description="Only the Estatell team can see this login."
            errors={form.errors}
            dirty={form.isDirty}
            discardTitle="Discard this login?"
            processing={form.processing}
            submitLabel={credential ? 'Save changes' : 'Save login'}
            onSubmit={submit}
        >
            {!credential && (
                <Field data-invalid={!!form.errors.ota_channel_id}>
                    <FieldLabel htmlFor="ota-channel">Channel</FieldLabel>
                    <Select
                        items={items}
                        name="ota_channel_id"
                        value={form.data.ota_channel_id}
                        onValueChange={(value) =>
                            form.setData('ota_channel_id', value as string)
                        }
                    >
                        <SelectTrigger
                            id="ota-channel"
                            className="w-full"
                            aria-invalid={!!form.errors.ota_channel_id}
                        >
                            <SelectValue placeholder="Choose a channel" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectGroup>
                                {items.map((item) => (
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
                    <FieldError>{form.errors.ota_channel_id}</FieldError>
                </Field>
            )}

            {!credential && channel?.isOther && (
                <Field data-invalid={!!form.errors.channel_name}>
                    <FieldLabel htmlFor="ota-channel-name">
                        Channel name
                    </FieldLabel>
                    <Input
                        id="ota-channel-name"
                        placeholder="e.g. Traveloka"
                        maxLength={80}
                        value={form.data.channel_name}
                        onChange={(event) =>
                            form.setData('channel_name', event.target.value)
                        }
                        aria-invalid={!!form.errors.channel_name}
                    />
                    <FieldError>{form.errors.channel_name}</FieldError>
                </Field>
            )}

            <Field data-invalid={!!form.errors.property_code}>
                <FieldLabel htmlFor="ota-property-code">Property ID</FieldLabel>
                <Input
                    id="ota-property-code"
                    placeholder="e.g. 123456"
                    maxLength={80}
                    value={form.data.property_code}
                    onChange={(event) =>
                        form.setData('property_code', event.target.value)
                    }
                    aria-invalid={!!form.errors.property_code}
                />
                <FieldError>{form.errors.property_code}</FieldError>
            </Field>

            <Field data-invalid={!!form.errors.username}>
                <FieldLabel htmlFor="ota-username">Username</FieldLabel>
                <Input
                    id="ota-username"
                    placeholder="e.g. frontdesk@seaview.my"
                    maxLength={255}
                    autoComplete="off"
                    value={form.data.username}
                    onChange={(event) =>
                        form.setData('username', event.target.value)
                    }
                    aria-invalid={!!form.errors.username}
                />
                <FieldError>{form.errors.username}</FieldError>
            </Field>

            <Field data-invalid={!!form.errors.password}>
                <FieldLabel htmlFor="ota-password">Password</FieldLabel>
                <Input
                    id="ota-password"
                    type="password"
                    placeholder="e.g. the extranet password"
                    maxLength={255}
                    autoComplete="new-password"
                    value={form.data.password}
                    onChange={(event) =>
                        form.setData('password', event.target.value)
                    }
                    aria-invalid={!!form.errors.password}
                />
                {credential && (
                    <FieldDescription>
                        Leave blank to keep the saved password. A new password
                        sets this channel back to Pending.
                    </FieldDescription>
                )}
                <FieldError>{form.errors.password}</FieldError>
            </Field>

            <Field data-invalid={!!form.errors.market_manager_name}>
                <FieldLabel htmlFor="ota-manager-name">
                    Market manager (optional)
                </FieldLabel>
                <Input
                    id="ota-manager-name"
                    placeholder="e.g. Aisha Rahman"
                    maxLength={255}
                    value={form.data.market_manager_name}
                    onChange={(event) =>
                        form.setData('market_manager_name', event.target.value)
                    }
                    aria-invalid={!!form.errors.market_manager_name}
                />
                <FieldError>{form.errors.market_manager_name}</FieldError>
            </Field>

            <Field data-invalid={!!form.errors.market_manager_phone}>
                <FieldLabel htmlFor="ota-manager-phone">
                    Phone (optional)
                </FieldLabel>
                <Input
                    id="ota-manager-phone"
                    placeholder="e.g. +60 12 345 6789"
                    maxLength={40}
                    value={form.data.market_manager_phone}
                    onChange={(event) =>
                        form.setData('market_manager_phone', event.target.value)
                    }
                    aria-invalid={!!form.errors.market_manager_phone}
                />
                <FieldError>{form.errors.market_manager_phone}</FieldError>
            </Field>

            <Field data-invalid={!!form.errors.market_manager_email}>
                <FieldLabel htmlFor="ota-manager-email">
                    Email (optional)
                </FieldLabel>
                <Input
                    id="ota-manager-email"
                    type="email"
                    placeholder="e.g. aisha@agoda-partner.com"
                    maxLength={255}
                    value={form.data.market_manager_email}
                    onChange={(event) =>
                        form.setData('market_manager_email', event.target.value)
                    }
                    aria-invalid={!!form.errors.market_manager_email}
                />
                <FieldError>{form.errors.market_manager_email}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export function OtaCredentialSheet(props: Props) {
    const key = useFreshOnOpen(props.open);
    const credential = useRetainedValue(props.credential);

    return (
        <OtaCredentialForm
            key={`${key}-${credential?.id ?? 'new'}`}
            {...props}
            credential={
                props.open || props.credential !== null
                    ? props.credential
                    : credential
            }
        />
    );
}
