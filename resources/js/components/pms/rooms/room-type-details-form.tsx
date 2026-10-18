import { Info } from 'lucide-react';
import { EnumSelect } from '@/components/pms/enum-select';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    SettingsInput,
    SettingsSelect,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { update } from '@/routes/settings/room-types';

type RoomType = App.Data.Rooms.RoomTypeData;
type Option = { value: string; label: string };

/**
 * Explains "Guests included" wherever the label appears.
 */
export function GuestsIncludedLabel() {
    return (
        <span className="inline-flex items-center gap-1">
            Guests included
            <Tooltip>
                <TooltipTrigger
                    render={
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground"
                            aria-label="What are guests included?"
                        />
                    }
                >
                    <Info className="size-3.5" />
                </TooltipTrigger>
                <TooltipContent>
                    How many guests a per-room price covers.
                </TooltipContent>
            </Tooltip>
        </span>
    );
}

/**
 * The Details tab: name, what the room type sells and how many guests it fits.
 */
export function RoomTypeDetailsForm({
    roomType,
    sellUnits,
    termsDocuments,
    readOnly,
}: {
    roomType: RoomType;
    sellUnits: Option[];
    termsDocuments: { value: number; label: string }[];
    readOnly: boolean;
}) {
    return (
        <SettingsSection
            key={roomType.id}
            form={update.form({ roomType: roomType.id })}
            title="Details"
            description="Set what you sell and how many guests it fits."
            submitLabel="Save details"
            readOnly={readOnly}
        >
            {(errors) => (
                <>
                    <SettingsInput
                        name="name"
                        label="Name"
                        value={roomType.name}
                        error={errors.name}
                        placeholder="e.g. Deluxe King"
                        disabled={readOnly}
                    />
                    <SettingsSelect
                        name="sell_unit"
                        label="Sold by"
                        value={roomType.sellUnit}
                        options={sellUnits}
                        error={errors.sell_unit}
                        description="A Bed room type sells one bed at a time, for dorms."
                        disabled={readOnly}
                    />
                    <Field className="md:col-span-2">
                        <FieldLabel htmlFor="description">
                            Description
                        </FieldLabel>
                        <Textarea
                            id="description"
                            name="description"
                            maxLength={2000}
                            defaultValue={roomType.description ?? ''}
                            placeholder="e.g. Sea view with a king bed and a balcony"
                            disabled={readOnly}
                            aria-invalid={!!errors.description}
                        />
                        <FieldError>{errors.description}</FieldError>
                    </Field>
                    <SettingsInput
                        name="max_adults"
                        label="Max adults"
                        type="number"
                        min={1}
                        max={50}
                        value={roomType.maxAdults}
                        error={errors.max_adults}
                        placeholder="e.g. 2"
                        disabled={readOnly}
                    />
                    <SettingsInput
                        name="max_children"
                        label="Max children"
                        type="number"
                        min={0}
                        max={50}
                        value={roomType.maxChildren}
                        error={errors.max_children}
                        placeholder="e.g. 1"
                        disabled={readOnly}
                    />
                    <SettingsInput
                        name="base_occupancy"
                        label={<GuestsIncludedLabel />}
                        type="number"
                        min={1}
                        value={roomType.baseOccupancy}
                        error={errors.base_occupancy}
                        placeholder="e.g. 2"
                        disabled={readOnly}
                    />
                    {termsDocuments.length > 0 && (
                        <Field>
                            <FieldLabel htmlFor="terms_document_id">
                                Terms document
                            </FieldLabel>
                            <EnumSelect
                                id="terms_document_id"
                                name="terms_document_id"
                                options={termsDocuments.map((document) => ({
                                    value: String(document.value),
                                    label: document.label,
                                }))}
                                allLabel="Default"
                                defaultValue={
                                    roomType.termsDocumentId
                                        ? String(roomType.termsDocumentId)
                                        : ''
                                }
                                disabled={readOnly}
                                aria-invalid={!!errors.terms_document_id}
                            />
                            <FieldDescription>
                                The registration terms guests in this room type
                                sign.
                            </FieldDescription>
                            <FieldError>{errors.terms_document_id}</FieldError>
                        </Field>
                    )}
                    <SettingsSwitch
                        name="smoking_allowed"
                        label="Smoking allowed"
                        checked={roomType.smokingAllowed}
                        description="On: guests may smoke in rooms of this type. Off: rooms of this type are non-smoking."
                        error={errors.smoking_allowed}
                        disabled={readOnly}
                    />
                    <SettingsSwitch
                        name="pets_allowed"
                        label="Pets allowed"
                        checked={roomType.petsAllowed}
                        description="On: guests may bring pets to rooms of this type. Off: no pets."
                        error={errors.pets_allowed}
                        disabled={readOnly}
                    />
                </>
            )}
        </SettingsSection>
    );
}
