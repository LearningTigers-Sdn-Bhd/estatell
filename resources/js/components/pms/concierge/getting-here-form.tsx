import { CalendarCheck, Plane, PlugZap } from 'lucide-react';
import { useState } from 'react';
import {
    SelectField,
    SwitchRow,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { FormBlock } from '@/components/pms/concierge/guide-layout';
import { ConciergeFormSection } from '@/components/pms/concierge/settings-form';
import { update } from '@/routes/settings/concierge/getting-here';

type Info = App.Data.Concierge.ConciergeGettingHereData;
type Errors = Record<string, string>;
export type ParkingOptions = {
    availability: { value: string; label: string }[];
    types: { value: string; label: string }[];
    priceUnits: { value: string; label: string }[];
};

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

/** How guests reach the property and where they park. Everything is optional; an empty part is not shown to guests. */
export function GettingHereForm({
    info,
    parkingOptions,
}: {
    info: Info;
    parkingOptions: ParkingOptions;
}) {
    const [transfer, setTransfer] = useState(info.airportTransferOffered);

    return (
        <ConciergeFormSection
            form={update.form()}
            contentClassName="flex flex-col gap-8"
            title="Getting here"
            description="How guests reach the property and where they park. All fields are optional."
        >
            {(errors) => (
                <>
                    <input
                        type="hidden"
                        name="airport_transfer_offered"
                        value={transfer ? '1' : '0'}
                    />
                    <DirectionsSection info={info} errors={errors} />
                    <TransportSection
                        info={info}
                        errors={errors}
                        transfer={transfer}
                        onTransferChange={setTransfer}
                    />
                    <ParkingSection
                        info={info}
                        errors={errors}
                        options={parkingOptions}
                    />
                </>
            )}
        </ConciergeFormSection>
    );
}

function DirectionsSection({ info, errors }: { info: Info; errors: Errors }) {
    return (
        <FormBlock
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
        </FormBlock>
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
        <FormBlock
            title="Transfers and local transport"
            description="Add airport pickup, shuttle, and public transport details."
        >
            <SwitchRow
                id="airport_transfer_switch"
                icon={Plane}
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
            </div>
            <TextField
                name="transport_notes"
                placeholder="e.g. Grab pickup is easiest at the side entrance."
                label="Other travel notes"
                defaultValue={info.transportNotes}
                error={errors.transport_notes}
                rows={2}
                maxLength={1000}
            />
        </FormBlock>
    );
}

function ParkingSection({
    info,
    errors,
    options,
}: {
    info: Info;
    errors: Errors;
    options: ParkingOptions;
}) {
    const [evCharging, setEvCharging] = useState(info.parkingEvCharging);
    const [bookingRequired, setBookingRequired] = useState(
        info.parkingBookingRequired,
    );

    return (
        <FormBlock
            title="Parking"
            description="Tell guests where to park, what it costs, and what fits."
        >
            <div className="grid gap-4 md:grid-cols-2">
                <SelectField
                    name="parking_availability"
                    label="Parking"
                    options={options.availability}
                    defaultValue={info.parkingAvailability}
                    error={errors.parking_availability}
                />
                <SelectField
                    name="parking_type"
                    label="Parking type"
                    options={options.types}
                    defaultValue={info.parkingType}
                    error={errors.parking_type}
                />
                <TextField
                    name="parking_price"
                    placeholder="e.g. 10"
                    label="Parking price"
                    type="number"
                    defaultValue={info.parkingPrice}
                    error={errors.parking_price}
                />
                <SelectField
                    name="parking_price_unit"
                    label="Price is"
                    options={options.priceUnits}
                    defaultValue={info.parkingPriceUnit}
                    error={errors.parking_price_unit}
                />
                <TextField
                    name="parking_spaces"
                    placeholder="e.g. 20"
                    label="Number of spaces"
                    type="number"
                    defaultValue={info.parkingSpaces}
                    error={errors.parking_spaces}
                />
                <TextField
                    name="parking_height_limit_m"
                    placeholder="e.g. 2.1"
                    label="Height limit (m)"
                    type="number"
                    defaultValue={info.parkingHeightLimitM}
                    error={errors.parking_height_limit_m}
                />
            </div>
            <TextField
                name="parking_notes"
                placeholder="e.g. Free for guests in the basement. First come, first served."
                label="Parking notes"
                defaultValue={info.parkingNotes}
                error={errors.parking_notes}
                rows={2}
                maxLength={500}
            />
            <input
                type="hidden"
                name="parking_ev_charging"
                value={evCharging ? '1' : '0'}
            />
            <input
                type="hidden"
                name="parking_booking_required"
                value={bookingRequired ? '1' : '0'}
            />
            <SwitchRow
                id="parking_ev_charging_switch"
                icon={PlugZap}
                title="EV charging available"
                body="Guests with electric cars will see this."
                checked={evCharging}
                onChange={setEvCharging}
                className="items-center rounded-lg border p-4"
            />
            <SwitchRow
                id="parking_booking_required_switch"
                icon={CalendarCheck}
                title="Parking must be booked"
                body="Guests are told to book a space before they arrive."
                checked={bookingRequired}
                onChange={setBookingRequired}
                className="items-center rounded-lg border p-4"
            />
        </FormBlock>
    );
}
