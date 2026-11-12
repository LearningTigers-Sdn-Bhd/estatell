import { Form } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
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
import { update } from '@/routes/settings/concierge/hotel-info';

type Info = App.Data.Concierge.ConciergeHotelInfoSettingsData;
type Errors = Record<string, string>;

/** The places guests ask the distance to. `field` is the form name, `prop` the key on the data. */
const DISTANCES = [
    {
        field: 'airport',
        prop: 'airport',
        label: 'Airport',
        km: '18',
        min: '25',
    },
    {
        field: 'station',
        prop: 'station',
        label: 'Train station',
        km: '1.5',
        min: '5',
    },
    {
        field: 'city_centre',
        prop: 'cityCentre',
        label: 'City centre',
        km: '3',
        min: '10',
    },
] as const;

/** A titled block of the form, divided from the one above. */
function Section({
    title,
    description,
    children,
}: {
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-4 border-t pt-6">
            <div className="flex flex-col gap-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            {children}
        </section>
    );
}

/** What the property tells guests about itself and how to get there. Everything is optional; an empty part is not shown. */
export function HotelInfoForm({ info }: { info: Info }) {
    const [transfer, setTransfer] = useState(info.airportTransferOffered);

    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle>Property and travel information</CardTitle>
                <CardDescription>
                    All fields are optional. Share only the details guests need
                    for arrival, departure, and getting around.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Form
                    action={update.url()}
                    method="put"
                    options={keepPage}
                    className="flex flex-col gap-8"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="airport_transfer_offered"
                                value={transfer ? '1' : '0'}
                            />
                            <TextField
                                name="about"
                                placeholder="e.g. A 40-room boutique hotel in the old town, five minutes from the night market."
                                label="About the property"
                                defaultValue={info.about}
                                error={errors.about}
                                rows={4}
                                maxLength={3000}
                            />

                            <ArrivalSection info={info} errors={errors} />
                            <DirectionsSection info={info} errors={errors} />
                            <TransportSection
                                info={info}
                                errors={errors}
                                transfer={transfer}
                                onTransferChange={setTransfer}
                            />

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

function ArrivalSection({ info, errors }: { info: Info; errors: Errors }) {
    return (
        <Section
            title="Arrival and departure"
            description="Give guests only the steps they need on arrival and when they leave."
        >
            <div className="grid gap-4 md:grid-cols-2">
                <TextField
                    name="arrival_instructions"
                    label="Arrival instructions"
                    defaultValue={info.arrivalInstructions}
                    error={errors.arrival_instructions}
                    rows={3}
                    maxLength={1000}
                    placeholder="e.g. Take the lift to level 3. The front desk is on your left."
                />
                <TextField
                    name="departure_instructions"
                    label="Departure instructions"
                    defaultValue={info.departureInstructions}
                    error={errors.departure_instructions}
                    rows={3}
                    maxLength={1000}
                    placeholder="e.g. Leave the key card at the front desk."
                />
            </div>
        </Section>
    );
}

function DirectionsSection({ info, errors }: { info: Info; errors: Errors }) {
    return (
        <Section
            title="Directions and travel times"
            description="Add typical distances and journey times if you know them."
        >
            <TextField
                name="directions"
                placeholder="e.g. From KLIA, take the ERL to KL Sentral, then a taxi (15 min). Turn left at the petrol station."
                label="Directions to the property"
                defaultValue={info.directions}
                error={errors.directions}
                rows={2}
                maxLength={2000}
            />
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                {DISTANCES.map(({ field, prop, label, km, min }) => (
                    <fieldset
                        key={field}
                        className="flex min-w-0 flex-col gap-3 rounded-lg border p-4"
                    >
                        <legend className="px-1 text-sm font-medium">
                            {label}
                        </legend>
                        <div className="grid grid-cols-2 gap-3">
                            <TextField
                                name={`${field}_distance_km`}
                                label="Distance (km)"
                                placeholder={`e.g. ${km}`}
                                type="number"
                                defaultValue={info[`${prop}DistanceKm`]}
                                error={errors[`${field}_distance_km`]}
                            />
                            <TextField
                                name={`${field}_travel_minutes`}
                                label="Travel (min)"
                                placeholder={`e.g. ${min}`}
                                type="number"
                                defaultValue={info[`${prop}TravelMinutes`]}
                                error={errors[`${field}_travel_minutes`]}
                            />
                        </div>
                    </fieldset>
                ))}
            </div>
        </Section>
    );
}

function TransportSection({
    info,
    errors,
    transfer,
    onTransferChange,
}: {
    info: Info;
    errors: Errors;
    transfer: boolean;
    onTransferChange: (offered: boolean) => void;
}) {
    return (
        <Section
            title="Transfers and local transport"
            description="Add pickup, parking, and public transport details in one place."
        >
            <SwitchRow
                id="airport_transfer_switch"
                title="Airport transfer available"
                body="Include the price, booking notice, and pickup point."
                checked={transfer}
                onChange={onTransferChange}
                className="items-center rounded-lg border p-4"
            />
            {transfer && (
                <div className="grid gap-4 md:grid-cols-2">
                    <TextField
                        name="airport_transfer_price"
                        placeholder="e.g. 80"
                        label="Transfer price"
                        type="number"
                        defaultValue={info.airportTransferPrice}
                        error={errors.airport_transfer_price}
                    />
                    <TextField
                        name="airport_transfer_lead_hours"
                        placeholder="e.g. 24"
                        label="Book how many hours ahead"
                        type="number"
                        defaultValue={info.airportTransferLeadHours}
                        error={errors.airport_transfer_lead_hours}
                    />
                    <div className="md:col-span-2">
                        <TextField
                            name="pickup_point"
                            placeholder="e.g. Arrival hall, Gate 3"
                            label="Pickup point"
                            defaultValue={info.pickupPoint}
                            error={errors.pickup_point}
                            maxLength={200}
                        />
                    </div>
                </div>
            )}
            <div className="grid gap-4 md:grid-cols-2">
                <TextField
                    name="nearest_transit_stop"
                    placeholder="e.g. Pasar Seni LRT, 400 m walk"
                    label="Nearest bus or train stop"
                    defaultValue={info.nearestTransitStop}
                    error={errors.nearest_transit_stop}
                    maxLength={200}
                />
                <TextField
                    name="shuttle_schedule"
                    placeholder="e.g. Hourly, 8 am to 10 pm, from the lobby"
                    label="Shuttle schedule"
                    defaultValue={info.shuttleSchedule}
                    error={errors.shuttle_schedule}
                    maxLength={200}
                />
                <TextField
                    name="parking_notes"
                    placeholder="e.g. Free for guests in the basement. First come, first served."
                    label="Parking"
                    defaultValue={info.parkingNotes}
                    error={errors.parking_notes}
                    rows={2}
                    maxLength={500}
                />
                <TextField
                    name="transport_notes"
                    placeholder="e.g. Grab pickup is easiest at the side entrance."
                    label="Other travel notes"
                    defaultValue={info.transportNotes}
                    error={errors.transport_notes}
                    rows={2}
                    maxLength={1000}
                />
            </div>
        </Section>
    );
}
