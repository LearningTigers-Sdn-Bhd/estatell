import { TextField } from '@/components/pms/concierge/content-fields';
import { FormBlock } from '@/components/pms/concierge/guide-layout';
import { ConciergeFormSection } from '@/components/pms/concierge/settings-form';
import { update } from '@/routes/settings/concierge/guest-guidelines';

/** The steps guests follow on arrival and when they leave. Both are optional; an empty one is not shown to guests. */
export function GuestGuidelinesForm({
    guidelines,
}: {
    guidelines: App.Data.Concierge.ConciergeGuestGuidelinesData;
}) {
    return (
        <ConciergeFormSection
            form={update.form()}
            contentClassName="flex flex-col gap-8"
            title="Guest guidelines"
            description="What guests need to know on arrival, on departure and during their stay."
        >
            {(errors) => (
                <FormBlock
                    title="Arrival and departure"
                    description="Give guests only the steps they need on arrival and when they leave."
                >
                    <div className="grid gap-4 md:grid-cols-2">
                        <TextField
                            name="arrival_instructions"
                            label="Arrival instructions"
                            defaultValue={guidelines.arrivalInstructions}
                            error={errors.arrival_instructions}
                            rows={3}
                            maxLength={1000}
                            placeholder="e.g. Take the lift to level 3. The front desk is on your left."
                        />
                        <TextField
                            name="departure_instructions"
                            label="Departure instructions"
                            defaultValue={guidelines.departureInstructions}
                            error={errors.departure_instructions}
                            rows={3}
                            maxLength={1000}
                            placeholder="e.g. Leave the key card at the front desk."
                        />
                    </div>
                </FormBlock>
            )}
        </ConciergeFormSection>
    );
}
