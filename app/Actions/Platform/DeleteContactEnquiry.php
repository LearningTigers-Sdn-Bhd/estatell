<?php

namespace App\Actions\Platform;

use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteContactEnquiry
{
    public function __invoke(User $admin, ContactEnquiry $enquiry): void
    {
        DB::transaction(function () use ($admin, $enquiry): void {
            activity()
                ->performedOn($enquiry)
                ->causedBy($admin)
                ->event('contact-enquiry.deleted')
                ->log('Contact enquiry deleted');

            $enquiry->delete();
        });
    }
}
