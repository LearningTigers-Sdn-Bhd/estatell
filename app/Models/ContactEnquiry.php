<?php

namespace App\Models;

use App\Enums\ContactEnquiryStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ContactEnquiryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One enquiry from the public Contact Us form. See docs/01-platform/08-contact-enquiries.md.
 *
 * @property int $id
 * @property string $hotel_name
 * @property string $location
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property int $room_count
 * @property string $message
 * @property ContactEnquiryStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['hotel_name', 'location', 'name', 'email', 'phone', 'room_count', 'message', 'status'])]
class ContactEnquiry extends Model
{
    /** @use HasFactory<ContactEnquiryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContactEnquiryStatus::class,
            'room_count' => 'integer',
        ];
    }
}
