import { Head } from '@inertiajs/react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { AmenityPicker } from '@/components/pms/amenities/amenity-picker';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';

export default function BookingWebsiteAmenities({
    amenities,
    page,
    listUrl,
}: {
    amenities: App.Data.BookingEngine.AmenityPickerData;
    page: number;
    listUrl: string;
}) {
    return (
        <>
            <Head title="Booking website amenities" />
            <BookingWebsiteShell activeTab="amenities">
                <AmenityPicker
                    headerClassName="ps-3 pt-2"
                    picker={amenities}
                    saveUrl={BookingWebsiteController.updateAmenities.url()}
                    description="Turn on what your property offers. Each switch saves at once. Room amenities are set on each room type."
                    readOnly={false}
                    page={page}
                    listUrl={listUrl}
                />
            </BookingWebsiteShell>
        </>
    );
}
