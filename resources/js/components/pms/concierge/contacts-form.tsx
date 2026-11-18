import { Clock } from 'lucide-react';
import { useState } from 'react';
import {
    SwitchRow,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { FormBlock } from '@/components/pms/concierge/guide-layout';
import { ConciergeFormSection } from '@/components/pms/concierge/settings-form';
import { update } from '@/routes/settings/concierge/contacts';

/** Front desk hours, out-of-hours contacts and emergency numbers. The property phone and email live on the property profile. */
export function ContactsForm({
    contacts,
}: {
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
}) {
    const [open24h, setOpen24h] = useState(contacts.frontDeskOpen24h);

    return (
        <ConciergeFormSection
            form={update.form()}
            contentClassName="flex flex-col gap-8"
            title="Front desk and emergency contacts"
            description="Set desk hours and the numbers guests should use when they need help."
        >
            {(errors) => (
                <>
                    <input
                        type="hidden"
                        name="front_desk_open_24h"
                        value={open24h ? '1' : '0'}
                    />
                    <FormBlock
                        title="Front desk hours"
                        description="When guests can reach someone at the desk."
                    >
                        <SwitchRow
                            id="front_desk_24h_switch"
                            icon={Clock}
                            title="Front desk open 24 hours"
                            body="Turn off to set opening hours and who to call while the desk is closed."
                            checked={open24h}
                            onChange={setOpen24h}
                            className="items-center rounded-lg border p-4"
                        />
                        {!open24h && (
                            <>
                                <div className="grid gap-4 md:grid-cols-2">
                                    <TextField
                                        name="front_desk_opens_at"
                                        label="Opens at"
                                        type="time"
                                        defaultValue={contacts.frontDeskOpensAt}
                                        error={errors.front_desk_opens_at}
                                    />
                                    <TextField
                                        name="front_desk_closes_at"
                                        label="Closes at"
                                        type="time"
                                        defaultValue={
                                            contacts.frontDeskClosesAt
                                        }
                                        error={errors.front_desk_closes_at}
                                    />
                                </div>
                                <TextField
                                    name="after_hours_message"
                                    placeholder="e.g. The desk is closed. Call the duty manager for urgent help."
                                    label="Message when the desk is closed"
                                    defaultValue={contacts.afterHoursMessage}
                                    error={errors.after_hours_message}
                                    rows={2}
                                    maxLength={500}
                                />
                                <TextField
                                    name="duty_manager_phone"
                                    placeholder="e.g. +60 12 345 6789"
                                    label="Duty manager phone (urgent only, shown while closed)"
                                    type="tel"
                                    defaultValue={contacts.dutyManagerPhone}
                                    error={errors.duty_manager_phone}
                                    maxLength={30}
                                />
                            </>
                        )}
                    </FormBlock>
                    <FormBlock
                        title="Front desk contacts"
                        description="How guests reach the desk during their stay."
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <TextField
                                name="front_desk_whatsapp"
                                label="WhatsApp number"
                                type="tel"
                                defaultValue={contacts.frontDeskWhatsapp}
                                error={errors.front_desk_whatsapp}
                                maxLength={30}
                                placeholder="e.g. +60 12 345 6789"
                            />
                            <TextField
                                name="front_desk_phone"
                                label="Front desk phone"
                                type="tel"
                                defaultValue={contacts.frontDeskPhone}
                                error={errors.front_desk_phone}
                                maxLength={30}
                                placeholder="e.g. +60 3 1234 5678"
                            />
                            <TextField
                                name="front_desk_extension"
                                label="Extension from a room phone"
                                defaultValue={contacts.frontDeskExtension}
                                error={errors.front_desk_extension}
                                maxLength={10}
                                placeholder="e.g. 0"
                            />
                            <TextField
                                name="front_desk_email"
                                label="Front desk email"
                                defaultValue={contacts.frontDeskEmail}
                                error={errors.front_desk_email}
                                maxLength={120}
                                placeholder="e.g. frontdesk@hotel.com"
                            />
                        </div>
                    </FormBlock>
                    <FormBlock
                        title="Emergencies"
                        description="Who guests call and what they do in an emergency."
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <TextField
                                name="emergency_phone"
                                placeholder="e.g. +60 3 1234 5678"
                                label="Property security phone"
                                type="tel"
                                defaultValue={contacts.emergencyPhone}
                                error={errors.emergency_phone}
                                maxLength={30}
                            />
                            <TextField
                                name="emergency_services_number"
                                label="Police, fire, ambulance number"
                                type="tel"
                                defaultValue={contacts.emergencyServicesNumber}
                                error={errors.emergency_services_number}
                                maxLength={30}
                                placeholder="999"
                            />
                        </div>
                        <TextField
                            name="emergency_instructions"
                            placeholder="e.g. Call 999, then the security phone. The assembly point is the car park."
                            label="Emergency instructions"
                            defaultValue={contacts.emergencyInstructions}
                            error={errors.emergency_instructions}
                            rows={3}
                            maxLength={1000}
                        />
                    </FormBlock>
                </>
            )}
        </ConciergeFormSection>
    );
}
