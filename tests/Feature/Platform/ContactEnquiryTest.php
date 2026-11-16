<?php

use App\Actions\Platform\UpdateContactEnquiryStatus;
use App\Enums\ContactEnquiryStatus;
use App\Models\ContactEnquiry;
use App\Models\User;
use App\Notifications\ContactEnquiryReceived;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

function validEnquiry(array $overrides = []): array
{
    return [
        'hotel_name' => 'Sandbay Resort',
        'location' => 'Kota Kinabalu, Sabah',
        'name' => 'Aisyah Rahman',
        'email' => 'aisyah@sandbay.test',
        'phone' => '+6012 345 6789',
        'room_count' => 42,
        'message' => 'We want a PMS and channel manager.',
        ...$overrides,
    ];
}

test('anyone can open the contact page', function () {
    $this->get(route('contact'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('contact')->where('sent', false));
});

test('a visitor sends an enquiry, it is saved and the sales inbox is told', function () {
    Notification::fake();
    config(['estatell.contact_notify_email' => 'sales@estatell.test']);

    $this->post(route('contact.store'), validEnquiry())
        ->assertRedirect(route('contact'))
        ->assertSessionHas('contact_sent', true);

    $enquiry = ContactEnquiry::query()->sole();
    expect($enquiry->hotel_name)->toBe('Sandbay Resort')
        ->and($enquiry->room_count)->toBe(42)
        ->and($enquiry->status)->toBe(ContactEnquiryStatus::New);

    Notification::assertSentOnDemand(
        ContactEnquiryReceived::class,
        fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'sales@estatell.test'
            && $notification->enquiry->is($enquiry),
    );
});

test('the page shows the thank you state once after sending', function () {
    Notification::fake();

    $this->post(route('contact.store'), validEnquiry());

    $this->get(route('contact'))->assertInertia(fn (Assert $page) => $page->where('sent', true));
    $this->get(route('contact'))->assertInertia(fn (Assert $page) => $page->where('sent', false));
});

test('every field is required and checked', function (string $field, mixed $value) {
    Notification::fake();

    $this->post(route('contact.store'), validEnquiry([$field => $value]))
        ->assertSessionHasErrors($field);

    expect(ContactEnquiry::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'hotel name' => ['hotel_name', ''],
    'location' => ['location', ''],
    'name' => ['name', ''],
    'email' => ['email', 'not-an-email'],
    'phone' => ['phone', 'call me'],
    'rooms missing' => ['room_count', ''],
    'rooms zero' => ['room_count', 0],
    'enquiry' => ['message', ''],
]);

test('a filled honeypot is refused and saves nothing', function () {
    Notification::fake();

    $this->post(route('contact.store'), validEnquiry(['website' => 'https://spam.test']))
        ->assertSessionHasErrors('website');

    expect(ContactEnquiry::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('the form is throttled', function () {
    Notification::fake();

    foreach (range(1, 5) as $ignored) {
        $this->post(route('contact.store'), validEnquiry())->assertRedirect(route('contact'));
    }

    $this->post(route('contact.store'), validEnquiry())->assertTooManyRequests();
});

test('the contact page is in the sitemap', function () {
    $this->get(route('sitemap'))->assertSee('/contact', false);
});

test('admins list enquiries, newest first, with search and a status filter', function () {
    $old = ContactEnquiry::factory()->create(['hotel_name' => 'Old Inn', 'created_at' => now()->subDay()]);
    $new = ContactEnquiry::factory()->create(['hotel_name' => 'Fresh Lodge']);
    $done = ContactEnquiry::factory()->closed()->create(['hotel_name' => 'Done Hostel', 'created_at' => now()->subDays(2)]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.enquiries.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/enquiries/index')
            ->has('enquiries.data', 3)
            ->where('enquiries.data.0.id', $new->id)
            ->where('enquiries.data.1.id', $old->id)
            ->where('counts', ['new' => 2, 'contacted' => 0, 'closed' => 1])
        );

    $this->actingAs($admin)
        ->get(route('admin.enquiries.index', ['filter' => ['search' => 'fresh']]))
        ->assertInertia(fn (Assert $page) => $page->has('enquiries.data', 1)->where('enquiries.data.0.id', $new->id));

    $this->actingAs($admin)
        ->get(route('admin.enquiries.index', ['filter' => ['status' => 'closed']]))
        ->assertInertia(fn (Assert $page) => $page->has('enquiries.data', 1)->where('enquiries.data.0.id', $done->id));
});

test('admins open one enquiry', function () {
    $enquiry = ContactEnquiry::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.enquiries.show', $enquiry))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/enquiries/show')
            ->where('enquiry.email', $enquiry->email)
            ->where('enquiry.status.value', 'new')
        );
});

test('admin navigation badges the new enquiries', function () {
    ContactEnquiry::factory()->count(2)->create();
    ContactEnquiry::factory()->closed()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('adminNavigation.10.title', 'Enquiries')
            ->where('adminNavigation.10.badge', 2)
        );
});

test('an admin changes the status and it is logged', function () {
    $enquiry = ContactEnquiry::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->put(route('admin.enquiries.status.update', $enquiry), ['status' => 'contacted'])
        ->assertRedirect();

    expect($enquiry->fresh()->status)->toBe(ContactEnquiryStatus::Contacted);
    $this->assertDatabaseHas('activity_log', [
        'event' => 'contact-enquiry.status-changed',
        'subject_id' => $enquiry->id,
        'causer_id' => $admin->id,
    ]);
});

test('moving an enquiry to the status it already has changes nothing', function () {
    $enquiry = ContactEnquiry::factory()->contacted()->create();

    expect(app(UpdateContactEnquiryStatus::class)(User::factory()->admin()->create(), $enquiry, ContactEnquiryStatus::Contacted))->toBeFalse();
    $this->assertDatabaseMissing('activity_log', ['event' => 'contact-enquiry.status-changed']);
});

test('an unknown status is refused', function () {
    $enquiry = ContactEnquiry::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->put(route('admin.enquiries.status.update', $enquiry), ['status' => 'archived'])
        ->assertSessionHasErrors('status');
});

test('staff and guests cannot reach the enquiries', function () {
    $enquiry = ContactEnquiry::factory()->create();

    $this->get(route('admin.enquiries.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());
    $this->get(route('admin.enquiries.index'))->assertNotFound();
    $this->get(route('admin.enquiries.show', $enquiry))->assertNotFound();
    $this->put(route('admin.enquiries.status.update', $enquiry), ['status' => 'closed'])->assertNotFound();
});

test('an admin permanently deletes an enquiry and the deletion is logged', function () {
    $enquiry = ContactEnquiry::factory()->create();
    $other = ContactEnquiry::factory()->create();
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->delete(route('admin.enquiries.destroy', $enquiry))
        ->assertRedirect(route('admin.enquiries.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Enquiry deleted.']);

    $this->assertModelMissing($enquiry);
    $this->assertModelExists($other);
    $this->assertDatabaseHas('activity_log', [
        'event' => 'contact-enquiry.deleted',
        'subject_id' => $enquiry->id,
        'causer_id' => $admin->id,
    ]);
    $this->get(route('admin.enquiries.show', $enquiry))->assertNotFound();
});

test('deleting an enquiry requires support two factor when enabled', function () {
    config(['estatell.support_require_two_factor' => true]);
    $enquiry = ContactEnquiry::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.enquiries.index'))
        ->delete(route('admin.enquiries.destroy', $enquiry))
        ->assertRedirect(route('admin.enquiries.index'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Two-factor required for this action.']);

    $this->assertModelExists($enquiry);
    $this->assertDatabaseMissing('activity_log', ['event' => 'contact-enquiry.deleted']);
});

test('guests and staff cannot delete enquiries', function () {
    $enquiry = ContactEnquiry::factory()->create();

    $this->delete(route('admin.enquiries.destroy', $enquiry))->assertRedirect(route('login'));
    $this->actingAs(User::factory()->create())
        ->delete(route('admin.enquiries.destroy', $enquiry))->assertNotFound();

    $this->assertModelExists($enquiry);
    $this->assertDatabaseMissing('activity_log', ['event' => 'contact-enquiry.deleted']);
});
