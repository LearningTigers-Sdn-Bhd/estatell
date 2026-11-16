<?php

namespace App\Data\Platform;

use App\Data\Shared\BadgeData;
use App\Models\ContactEnquiry;
use Spatie\LaravelData\Data;

class AdminContactEnquiryRowData extends Data
{
    public function __construct(
        public int $id,
        public string $receivedAt,
        public string $hotelName,
        public string $location,
        public string $name,
        public string $email,
        public string $phone,
        public int $roomCount,
        public string $message,
        public BadgeData $status,
    ) {}

    public static function fromModel(ContactEnquiry $enquiry): self
    {
        return new self(
            id: $enquiry->id,
            receivedAt: $enquiry->created_at?->format('Y-m-d H:i') ?? '',
            hotelName: $enquiry->hotel_name,
            location: $enquiry->location,
            name: $enquiry->name,
            email: $enquiry->email,
            phone: $enquiry->phone,
            roomCount: $enquiry->room_count,
            message: $enquiry->message,
            status: BadgeData::fromContactEnquiryStatus($enquiry->status),
        );
    }
}
