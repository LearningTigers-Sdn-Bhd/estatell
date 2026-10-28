<?php

namespace Tests\Browser;

use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Actions\Onboarding\SaveOnboardingRooms;
use App\Actions\Onboarding\SaveOnboardingRoomTypes;
use App\Actions\Onboarding\SaveOnboardingTaxes;
use App\Actions\Onboarding\SavePropertyBasics;
use App\Data\Onboarding\NewPropertyOwnerData;
use App\Enums\DefaultRoleEnum;
use App\Enums\PropertyType;
use App\Enums\UnitStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BookingScenariosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Pest\Browser\Api\AwaitableWebpage;
use Tests\TestCase;

/**
 * Browser tests that take the screenshots for the user docs. The demo properties are seeded once per process, at
 * the frozen date, and every test runs in a transaction on top of them.
 *
 * Test files mirror the docs tree: `Docs/{Module}/{Topic}Test.php`, or `Docs/{Module}/{Topic}/{SubTopic}Test.php`.
 * Screenshots go to the folder named after the test file's folder, so `Docs/Reservations/AddOnsTest.php` saves to
 * `Screenshots/reservations/`.
 */
abstract class BrowserTestCase extends TestCase
{
    /**
     * The viewport every docs screenshot uses, unless a test asks for a taller page.
     */
    public const int WIDTH = 1440;

    public const int HEIGHT = 900;

    /**
     * Seeded by RefreshDatabase on its one `migrate:fresh` per process.
     *
     * @var class-string
     */
    protected $seeder = DocsSeeder::class;

    protected Property $property;

    /**
     * Freeze the clock before RefreshDatabase seeds, so the demo's business date and bookings match the frozen day.
     */
    protected function setUp(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00', 'UTC'));

        parent::setUp();
    }

    /**
     * Sign in as the Owner of a demo property and keep the property on `$this->property`.
     *
     * @param  string  $slug  The demo login name, for example `pearlside`.
     */
    protected function signInAsDemoOwner(string $slug, string $propertyName): Property
    {
        $this->property = Property::query()->where('name', $propertyName)->firstOrFail();
        $this->actingAs(User::query()->where('email', DemoSeeder::loginEmail($slug, DefaultRoleEnum::Owner))->firstOrFail());

        return $this->property;
    }

    /**
     * Sign in as the Owner of the booking scenarios property, for the stays the demo properties do not have.
     */
    protected function signInAsScenariosOwner(): Property
    {
        $this->property = Property::query()->where('name', BookingScenariosSeeder::NAME)->firstOrFail();
        $this->actingAs(User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail());

        return $this->property;
    }

    /**
     * Register the empty "Seaview Lodge" through the real sign-up action and sign in as its verified Owner. The
     * property starts in Onboarding with the default roles and nothing else, so an onboarding test walks the wizard
     * from the first section. Each test makes its own, inside the test transaction.
     */
    protected function startOnboarding(): Property
    {
        $owner = app(RegisterPropertyOwner::class)(new NewPropertyOwnerData(
            name: 'Aminah Yusof',
            email: 'aminah@seaviewlodge.my',
            password: 'Seaview-Lodge-2026',
            propertyName: 'Seaview Lodge',
        ));
        $owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($owner);

        return $this->property = $owner->property;
    }

    /**
     * Save the Basics section of the onboarding property, the way the form does, so the sections after it unlock.
     */
    protected function saveOnboardingBasics(): void
    {
        app(SavePropertyBasics::class)(auth()->user(), $this->property, [
            'name' => 'Seaview Lodge',
            'type' => PropertyType::Hotel->value,
            'address_line1' => '12 Jalan Pantai',
            'city' => 'Kota Kinabalu',
            'country_code' => 'MY',
        ], ['legal_name' => null, 'registration_number' => null, 'tin' => null], []);
    }

    /**
     * Save Basics and one room type, "Standard Double", so the Rooms section unlocks.
     */
    protected function saveOnboardingRoomType(): RoomType
    {
        $this->saveOnboardingBasics();

        app(SaveOnboardingRoomTypes::class)($this->property, auth()->user(), [
            ['id' => null, 'name' => 'Standard Double', 'max_adults' => 2, 'max_children' => 1],
        ]);

        return $this->property->roomTypes()->firstOrFail();
    }

    /**
     * Save Basics and the Taxes section with Service Tax (SST) turned on and applied to rooms, so the Prices section
     * unlocks once there is a room type.
     */
    protected function saveOnboardingTaxes(): void
    {
        $this->saveOnboardingBasics();

        $rows = $this->property->taxes()->get()->map(fn ($tax): array => [
            'id' => $tax->id,
            'name' => $tax->name,
            'rate_type' => $tax->rate_type,
            'rate' => $tax->rate,
            'is_inclusive' => $tax->is_inclusive,
            'foreign_guests_only' => $tax->foreign_guests_only,
            'collect_at_check_in' => $tax->collect_at_check_in,
            'registration_number' => $tax->registration_number,
            'enabled' => $tax->name === 'Service Tax (SST)',
            'applies_to_rooms' => $tax->name === 'Service Tax (SST)',
        ])->all();

        app(SaveOnboardingTaxes::class)($this->property, auth()->user(), $rows, false);
    }

    /**
     * Save Basics, Taxes and two room types, "Standard Double" and "Family Longhouse", so Basic rate opens.
     *
     * @return array{RoomType, RoomType}
     */
    protected function saveOnboardingPriceableRoomTypes(): array
    {
        $this->saveOnboardingTaxes();

        app(SaveOnboardingRoomTypes::class)($this->property, auth()->user(), [
            ['id' => null, 'name' => 'Standard Double', 'max_adults' => 2, 'max_children' => 1],
            ['id' => null, 'name' => 'Family Longhouse', 'max_adults' => 4, 'max_children' => 2],
        ]);

        return [
            $this->property->roomTypes()->where('name', 'Standard Double')->firstOrFail(),
            $this->property->roomTypes()->where('name', 'Family Longhouse')->firstOrFail(),
        ];
    }

    /**
     * Save the rooms of the onboarding docs: 101 to 103 on the Ground floor and G1 to G2 in the Garden block.
     */
    protected function saveOnboardingRooms(RoomType $roomType): void
    {
        app(SaveOnboardingRooms::class)($this->property, auth()->user(), [
            ['key' => 'ground', 'id' => null, 'name' => 'Ground floor'],
            ['key' => 'garden', 'id' => null, 'name' => 'Garden block'],
        ], [
            ['room_type_id' => $roomType->id, 'group_key' => 'ground', 'prefix' => '', 'from' => '101', 'to' => '103'],
            ['room_type_id' => $roomType->id, 'group_key' => 'garden', 'prefix' => 'G', 'from' => '1', 'to' => '2'],
        ]);
    }

    /**
     * Visit the URL at the fixed docs viewport.
     */
    protected function open(string $url, int $height = self::HEIGHT): AwaitableWebpage
    {
        return visit($url)->resize(self::WIDTH, $height);
    }

    /**
     * Mark up the page, check it has no JavaScript errors and save the screenshot to this test file's folder.
     *
     * @param  list<string>  $badges  The exact text of the element for badge 1, 2, 3 and so on. The docs page names each number in a table.
     * @param  list<string>  $highlight  CSS selectors of the sections to draw a red box around.
     * @param  string|null  $element  A CSS selector to screenshot only that element, for example `[role="dialog"]`.
     * @param  int  $badgesFromLeft  Badges match only elements at least this many pixels from the left edge, which skips the staff sidebar. Pass 0 on a page without one, like the onboarding wizard.
     */
    protected function shoot(AwaitableWebpage $page, string $name, array $badges = [], array $highlight = [], ?string $element = null, bool $checkErrors = true, int $badgesFromLeft = 256): AwaitableWebpage
    {
        if ($highlight !== []) {
            $this->assertSame([], $page->script($this->highlightScript($highlight)), 'No element matches these highlight selectors.');
        }

        if ($badges !== []) {
            $this->assertSame([], $page->script($this->badgesScript($badges, $badgesFromLeft)), 'No element has exactly this badge text.');
        }

        if ($checkErrors) {
            $page->assertNoJavaScriptErrors();
        }

        $folder = $this->screenshotFolder();
        File::ensureDirectoryExists(base_path("tests/Browser/Screenshots/{$folder}"));

        return $element === null
            ? $page->screenshot(filename: "{$folder}/{$name}")
            : $page->screenshotElement($element, filename: "{$folder}/{$name}");
    }

    /**
     * Open a policy tab of Settings › Billing setup › Room revenue with a Percentage charge filled in but not saved, so
     * the screenshot shows the charge fields and the worked example. The demo properties keep every policy Free.
     */
    protected function openPolicy(string $tab, string $percent, ?string $freeUntilDays = null): AwaitableWebpage
    {
        $page = $this->open(route('settings.room-revenue.policies.show', [$this->property, $tab]), height: 1200)
            ->click('Percentage')
            ->type('#percent', $percent);

        return $freeUntilDays === null ? $page->wait(1) : $page->type('#free_until_days', $freeUntilDays)->wait(1);
    }

    /**
     * The demo reservation with the most units, used as the group booking in the docs.
     */
    protected function largestGroup(): Reservation
    {
        return Reservation::query()
            ->where('property_id', $this->property->id)
            ->withCount('units')
            ->orderByDesc('units_count')
            ->firstOrFail();
    }

    /**
     * The single-unit reservation that arrives today with no company, used for the check-in docs.
     */
    protected function singleArrivalToday(): Reservation
    {
        return Reservation::query()
            ->where('property_id', $this->property->id)
            ->whereNull('company_id')
            ->whereHas('units', fn ($units) => $units->where('status', UnitStatus::Confirmed)->whereDate('arrival_date', now()->toDateString()))
            ->has('units', '=', 1)
            ->firstOrFail();
    }

    /**
     * A booking scenarios reservation by its code, for example `S11`. Each one's notes start with the code.
     */
    protected function scenario(string $code): Reservation
    {
        return Reservation::query()
            ->where('property_id', $this->property->id)
            ->where('notes', 'like', "[{$code}]%")
            ->firstOrFail();
    }

    protected function reservationByReference(string $reference): Reservation
    {
        return Reservation::query()
            ->where('property_id', $this->property->id)
            ->where('reference', $reference)
            ->firstOrFail();
    }

    /**
     * The test file's folder under `Docs/`, in kebab case: `Reservations/CreateBooking` becomes `reservations/create-booking`.
     */
    private function screenshotFolder(): string
    {
        $relative = Str::after(dirname(static::$__filename), 'tests/Browser/Docs/');

        return collect(explode('/', $relative))->map(fn (string $part): string => Str::kebab($part))->implode('/');
    }

    /**
     * Draws a numbered red badge at the top left of the first element in the main area, right of the sidebar, whose
     * text is exactly the given text. Returns the texts it could not find.
     *
     * @param  list<string>  $texts
     */
    private function badgesScript(array $texts, int $fromLeft): string
    {
        $json = json_encode($texts, JSON_THROW_ON_ERROR);

        return <<<JS
        (() => {
            const texts = {$json};
            const missing = [];
            texts.forEach((text, index) => {
                const same = (el) => el.textContent.trim() === text;
                const match = [...document.querySelectorAll('body *')].find(
                    (el) => same(el)
                        && ![...el.children].some(same)
                        && el.getBoundingClientRect().left >= {$fromLeft}
                        && el.getBoundingClientRect().width > 0,
                );
                if (!match) { missing.push(text); return; }
                const box = match.getBoundingClientRect();
                const badge = document.createElement('div');
                badge.textContent = String(index + 1);
                badge.style.cssText = 'position:fixed;z-index:99999;width:22px;height:22px;border-radius:50%;background:#dc2626;color:#fff;font:600 13px/22px sans-serif;text-align:center;box-shadow:0 0 0 2px #fff;pointer-events:none;'
                    + 'left:' + Math.max(0, box.left - 14) + 'px;top:' + Math.max(0, box.top - 8) + 'px;';
                document.body.appendChild(badge);
            });
            return missing;
        })();
        JS;
    }

    /**
     * Draws a red box over the first element that matches each selector. The box is an overlay, so a parent with
     * `overflow: hidden` cannot clip it. Returns the selectors it could not find.
     *
     * @param  list<string>  $selectors
     */
    private function highlightScript(array $selectors): string
    {
        $json = json_encode($selectors, JSON_THROW_ON_ERROR);

        return <<<JS
        (() => {
            const selectors = {$json};
            const missing = [];
            selectors.forEach((selector) => {
                const match = document.querySelector(selector);
                if (!match) { missing.push(selector); return; }
                const box = match.getBoundingClientRect();
                const frame = document.createElement('div');
                frame.style.cssText = 'position:fixed;z-index:99998;border:3px solid #dc2626;border-radius:8px;pointer-events:none;'
                    + 'left:' + (box.left - 6) + 'px;top:' + (box.top - 6) + 'px;width:' + (box.width + 12) + 'px;height:' + (box.height + 12) + 'px;';
                document.body.appendChild(frame);
            });
            return missing;
        })();
        JS;
    }
}
