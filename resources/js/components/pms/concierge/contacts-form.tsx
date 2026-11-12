import { Form } from '@inertiajs/react';
import { useState } from 'react';
import {
    keepPage,
    SubmitButton,
    SwitchRow,
    TextField,
} from '@/components/pms/concierge/content-fields';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { update } from '@/routes/settings/concierge/contacts';

/** Front desk hours, out-of-hours contacts and emergency numbers. The property phone and email live on the property profile. */
export function ContactsForm({
    contacts,
}: {
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
}) {
    const [open24h, setOpen24h] = useState(contacts.frontDeskOpen24h);

    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle>Front desk and emergency contacts</CardTitle>
                <CardDescription>
                    Set desk hours and the numbers guests should use when they
                    need help.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    action={update.url()}
                    method="put"
                    options={keepPage}
                    className="flex flex-col gap-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="front_desk_open_24h"
                                value={open24h ? '1' : '0'}
                            />
                            <FieldGroup>
                                <SwitchRow
                                    id="front_desk_24h_switch"
                                    title="Front desk open 24 hours"
                                    checked={open24h}
                                    onChange={setOpen24h}
                                    className="items-center"
                                />
                                {!open24h && (
                                    <>
                                        <TextField
                                            name="front_desk_opens_at"
                                            label="Opens at"
                                            type="time"
                                            defaultValue={
                                                contacts.frontDeskOpensAt
                                            }
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
                                        <TextField
                                            name="after_hours_message"
                                            placeholder="e.g. The desk is closed. Call the duty manager for urgent help."
                                            label="Message when the desk is closed"
                                            defaultValue={
                                                contacts.afterHoursMessage
                                            }
                                            error={errors.after_hours_message}
                                            rows={2}
                                            maxLength={500}
                                        />
                                        <TextField
                                            name="duty_manager_phone"
                                            placeholder="e.g. +60 12 345 6789"
                                            label="Duty manager phone (urgent only, shown while closed)"
                                            type="tel"
                                            defaultValue={
                                                contacts.dutyManagerPhone
                                            }
                                            error={errors.duty_manager_phone}
                                            maxLength={30}
                                        />
                                    </>
                                )}
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
                                    defaultValue={
                                        contacts.emergencyServicesNumber
                                    }
                                    error={errors.emergency_services_number}
                                    maxLength={30}
                                    placeholder="999"
                                />
                                <TextField
                                    name="emergency_instructions"
                                    placeholder="e.g. Call 999, then the security phone. The assembly point is the car park."
                                    label="Emergency instructions"
                                    defaultValue={
                                        contacts.emergencyInstructions
                                    }
                                    error={errors.emergency_instructions}
                                    rows={3}
                                    maxLength={1000}
                                />
                            </FieldGroup>
                            <SubmitButton processing={processing}>
                                Save
                            </SubmitButton>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
