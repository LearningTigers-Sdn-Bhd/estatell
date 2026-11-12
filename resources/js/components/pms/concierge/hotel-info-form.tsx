import { Form } from '@inertiajs/react';
import { useState } from 'react';
import { TextField } from '@/components/pms/concierge/content-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { update } from '@/routes/settings/concierge/hotel-info';

/** What the property tells guests about itself and how to get there. Everything is optional; an empty part is not shown. */
export function HotelInfoForm({
    info,
}: {
    info: App.Data.Concierge.ConciergeHotelInfoSettingsData;
}) {
    const [transfer, setTransfer] = useState(info.airportTransferOffered);

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
                                name="airport_transfer_offered"
                                value={transfer ? '1' : '0'}
                            />
                            <FieldGroup>
                                <TextField
                                    name="about"
                                    label="About the property"
                                    defaultValue={info.about}
                                    error={errors.about}
                                    rows={5}
                                    maxLength={3000}
                                />
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
                                <TextField
                                    name="directions"
                                    label="Directions"
                                    defaultValue={info.directions}
                                    error={errors.directions}
                                    rows={3}
                                    maxLength={2000}
                                />
                                <TextField
                                    name="airport_distance_km"
                                    label="Airport distance (km)"
                                    type="number"
                                    defaultValue={info.airportDistanceKm}
                                    error={errors.airport_distance_km}
                                />
                                <TextField
                                    name="airport_travel_minutes"
                                    label="Airport travel (minutes)"
                                    type="number"
                                    defaultValue={info.airportTravelMinutes}
                                    error={errors.airport_travel_minutes}
                                />
                                <TextField
                                    name="station_distance_km"
                                    label="Train station distance (km)"
                                    type="number"
                                    defaultValue={info.stationDistanceKm}
                                    error={errors.station_distance_km}
                                />
                                <TextField
                                    name="station_travel_minutes"
                                    label="Train station travel (minutes)"
                                    type="number"
                                    defaultValue={info.stationTravelMinutes}
                                    error={errors.station_travel_minutes}
                                />
                                <TextField
                                    name="city_centre_distance_km"
                                    label="City centre distance (km)"
                                    type="number"
                                    defaultValue={info.cityCentreDistanceKm}
                                    error={errors.city_centre_distance_km}
                                />
                                <TextField
                                    name="city_centre_travel_minutes"
                                    label="City centre travel (minutes)"
                                    type="number"
                                    defaultValue={info.cityCentreTravelMinutes}
                                    error={errors.city_centre_travel_minutes}
                                />
                                <TextField
                                    name="nearest_transit_stop"
                                    label="Nearest bus or train stop"
                                    defaultValue={info.nearestTransitStop}
                                    error={errors.nearest_transit_stop}
                                    maxLength={200}
                                />
                                <div className="flex items-center justify-between gap-4">
                                    <Label htmlFor="airport_transfer_switch">
                                        Airport transfer available
                                    </Label>
                                    <Switch
                                        id="airport_transfer_switch"
                                        checked={transfer}
                                        onCheckedChange={setTransfer}
                                    />
                                </div>
                                {transfer && (
                                    <>
                                        <TextField
                                            name="airport_transfer_price"
                                            label="Transfer price"
                                            type="number"
                                            defaultValue={
                                                info.airportTransferPrice
                                            }
                                            error={
                                                errors.airport_transfer_price
                                            }
                                        />
                                        <TextField
                                            name="airport_transfer_lead_hours"
                                            label="Book how many hours ahead"
                                            type="number"
                                            defaultValue={
                                                info.airportTransferLeadHours
                                            }
                                            error={
                                                errors.airport_transfer_lead_hours
                                            }
                                        />
                                        <TextField
                                            name="pickup_point"
                                            label="Pickup point"
                                            defaultValue={info.pickupPoint}
                                            error={errors.pickup_point}
                                            maxLength={200}
                                        />
                                    </>
                                )}
                                <TextField
                                    name="shuttle_schedule"
                                    label="Shuttle schedule"
                                    defaultValue={info.shuttleSchedule}
                                    error={errors.shuttle_schedule}
                                    maxLength={200}
                                />
                                <TextField
                                    name="parking_notes"
                                    label="Parking"
                                    defaultValue={info.parkingNotes}
                                    error={errors.parking_notes}
                                    rows={2}
                                    maxLength={500}
                                />
                                <TextField
                                    name="transport_notes"
                                    label="Other travel notes"
                                    defaultValue={info.transportNotes}
                                    error={errors.transport_notes}
                                    rows={2}
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
