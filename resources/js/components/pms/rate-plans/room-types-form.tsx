import { useForm } from '@inertiajs/react';
import { RoomTypeCheckboxItem } from '@/components/pms/rate-plans/room-type-checkbox-item';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { FieldError, FieldGroup } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/rate-plans/room-types';

type RatePlan = App.Data.Rates.RatePlanData;
type Choice = App.Data.Rates.RatePlanRoomTypeChoiceData;
type SellUnitOption = { value: string; label: string };

/**
 * One checkbox per room type. Room types of the other family are shown disabled, with the reason.
 */
export function RoomTypesForm({
    ratePlan,
    roomTypes,
    sellUnits,
}: {
    ratePlan: RatePlan;
    roomTypes: Choice[];
    sellUnits: SellUnitOption[];
}) {
    const form = useForm<{ room_type_ids: number[] }>({
        room_type_ids: ratePlan.roomTypeIds,
    });
    const guard = useUnsavedChanges(form.isDirty);
    const sellUnitLabel = (value: string) =>
        sellUnits.find((option) => option.value === value)?.label ?? value;

    const toggle = (id: number, checked: boolean) => {
        form.setData(
            'room_type_ids',
            checked
                ? [...form.data.room_type_ids, id]
                : form.data.room_type_ids.filter((current) => current !== id),
        );
    };

    const save = (onSaved?: () => void) => {
        form.put(update.url({ ratePlan: ratePlan.id }), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.setDefaults();
                onSaved?.();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-5">
            <FieldGroup data-slot="checkbox-group">
                {roomTypes.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        There are no room types yet. Add room types first.
                    </p>
                )}
                {roomTypes.map((roomType) => (
                    <RoomTypeCheckboxItem
                        key={roomType.id}
                        id={`rate-plan-room-type-${roomType.id}`}
                        name={roomType.name}
                        sellUnitLabel={sellUnitLabel(roomType.sellUnit)}
                        fits={roomType.fits}
                        pricingLabel={ratePlan.pricingLabel}
                        checked={form.data.room_type_ids.includes(roomType.id)}
                        onCheckedChange={(checked) =>
                            toggle(roomType.id, checked)
                        }
                    />
                ))}
                <FieldError>{form.errors.room_type_ids}</FieldError>
            </FieldGroup>
            <footer className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner data-icon="inline-start" />}
                    Save room types
                </Button>
            </footer>
            <UnsavedChangesDialog
                guard={guard}
                name={ratePlan.name}
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </form>
    );
}
