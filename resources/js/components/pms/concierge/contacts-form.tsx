import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { TextField } from '@/components/pms/concierge/content-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update } from '@/routes/settings/concierge/contacts';

/** Front desk hours, out-of-hours contacts and emergency numbers. The property phone and email live on the property profile. */
export function ContactsForm({
    contacts,
}: {
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
}) {
    const [open24h, setOpen24h] = useState(contacts.frontDeskOpen24h);

    return (
        <Card className="max-w-md print:hidden">
            <CardContent>
                <Form
                    action={update.url()}
                    method="put"
                    options={{ preserveScroll: true, preserveState: true }}
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
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="front_desk_24h_switch">
                                        Front desk open 24 hours
                                    </Label>
                                    <Switch
                                        id="front_desk_24h_switch"
                                        checked={open24h}
                                        onCheckedChange={setOpen24h}
                                    />
                                </div>
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
                                    label="Emergency instructions"
                                    defaultValue={
                                        contacts.emergencyInstructions
                                    }
                                    error={errors.emergency_instructions}
                                    rows={3}
                                    maxLength={1000}
                                />
                            </FieldGroup>
                            <Button
                                type="submit"
                                className="self-end"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Save
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
