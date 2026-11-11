import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    ID_GROUP,
    ID_TYPE_SELECT,
} from '@/components/pms/guest-identity-fields';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';

/**
 * The IC / Passport field of a portal guest: the ID type select inside the number's input group, like the staff
 * guest form. "No ID" leaves both empty. `name` sets the input names for a native form (`id_type`, `id_number`).
 */
export function PortalIdField({
    idPrefix,
    idType,
    idNumber,
    idTypes,
    error,
    placeholder,
    named = false,
    onChange,
}: {
    idPrefix: string;
    idType: string;
    idNumber: string;
    idTypes: EnumOption[];
    error?: string;
    /** Replaces the example number, for example when an ID is already on file. */
    placeholder?: string;
    named?: boolean;
    onChange: (patch: { id_type?: string; id_number?: string }) => void;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={`${idPrefix}-id-number`}>
                IC / Passport number
            </FieldLabel>
            <InputGroup className={ID_GROUP}>
                <InputGroupAddon className="border-r p-0">
                    <EnumSelect
                        id={`${idPrefix}-id-type`}
                        name={named ? 'id_type' : undefined}
                        aria-label="Identity document type"
                        options={idTypes}
                        allLabel="No ID"
                        value={idType}
                        onValueChange={(value) => onChange({ id_type: value })}
                        className={ID_TYPE_SELECT}
                    />
                </InputGroupAddon>
                <InputGroupInput
                    id={`${idPrefix}-id-number`}
                    name={named ? 'id_number' : undefined}
                    placeholder={
                        placeholder ??
                        (idType === 'passport'
                            ? 'e.g. A12345678'
                            : 'e.g. 900101-14-1234')
                    }
                    maxLength={40}
                    autoComplete="off"
                    value={idNumber}
                    onChange={(event) =>
                        onChange({ id_number: event.target.value })
                    }
                    aria-invalid={!!error}
                />
            </InputGroup>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
