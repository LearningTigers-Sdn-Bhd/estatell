import { EnumSelect } from '@/components/pms/enum-select';
import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { PosStaff } from './pos-types';

export type ApprovalData = { approver_id: string; approver_pin: string };

export const noApproval: ApprovalData = { approver_id: '', approver_pin: '' };

/**
 * A manager approves inline with their own PIN. Nothing is stored: the PIN goes with this one request only.
 */
export function PosApprovalFields({
    staff,
    data,
    onChange,
    required = false,
}: {
    staff: PosStaff[];
    data: ApprovalData;
    onChange: (data: ApprovalData) => void;
    required?: boolean;
}) {
    return (
        <div className="flex flex-col gap-3 rounded-lg border p-3">
            <FieldDescription>
                {required
                    ? 'A manager must approve this. They enter their own PIN.'
                    : 'Needed only when you are not allowed to do this yourself. A manager enters their own PIN.'}
            </FieldDescription>
            <Field>
                <FieldLabel htmlFor="approver-id">Manager</FieldLabel>
                <EnumSelect
                    id="approver-id"
                    name="approver_id"
                    options={staff.map((person) => ({
                        value: String(person.id),
                        label: person.name,
                    }))}
                    value={data.approver_id}
                    onValueChange={(value) =>
                        onChange({ ...data, approver_id: value })
                    }
                    placeholder="Pick a manager"
                />
            </Field>
            <Field>
                <FieldLabel htmlFor="approver-pin">Manager PIN</FieldLabel>
                <Input
                    id="approver-pin"
                    type="password"
                    inputMode="numeric"
                    autoComplete="off"
                    maxLength={12}
                    value={data.approver_pin}
                    onChange={(event) =>
                        onChange({
                            ...data,
                            approver_pin: event.target.value.replace(/\D/g, ''),
                        })
                    }
                />
                <FieldDescription>
                    {staff.length === 0
                        ? 'No manager has a POS PIN yet. A manager sets it under Account, POS PIN.'
                        : 'A PIN approval only works on a registered till (Settings, POS, Staff and terminals). A manager without a PIN is not listed.'}
                </FieldDescription>
            </Field>
        </div>
    );
}
