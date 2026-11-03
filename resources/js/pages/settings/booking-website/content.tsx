import { Head } from '@inertiajs/react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { AmenityPicker } from '@/components/pms/amenities/amenity-picker';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';

export default function BookingWebsiteContent({
    amenities,
}: {
    amenities: App.Data.BookingEngine.AmenityPickerData;
}) {
    return (
        <>
            <Head title="Booking website content" />
            <BookingWebsiteShell
                description="What guests read about your property."
                activeTab="content"
            >
                <AmenityPicker
                    picker={amenities}
                    form={BookingWebsiteController.updateContent.form()}
                    title="Amenities"
                    description="Tick what your property offers. Room amenities are set on each room type."
                    readOnly={false}
                />
            </BookingWebsiteShell>
        </>
    );
}
