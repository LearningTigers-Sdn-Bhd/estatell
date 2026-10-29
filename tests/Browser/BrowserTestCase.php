<?php

namespace Tests\Browser;

use App\Actions\Boats\CreateBoatDeparture;
use App\Actions\Boats\CreateBoatFare;
use App\Actions\Boats\SetBoatTrip;
use App\Actions\Boats\UpdateBoatDeparture;
use App\Actions\CityLedger\SetCompanyPortalLogin;
use App\Actions\Onboarding\RegisterPropertyOwner;
use App\Actions\Onboarding\SaveOnboardingBasicRate;
use App\Actions\Onboarding\SaveOnboardingRooms;
use App\Actions\Onboarding\SaveOnboardingRoomTypes;
use App\Actions\Onboarding\SaveOnboardingTaxes;
use App\Actions\Onboarding\SavePropertyBasics;
use App\Actions\Staff\AssignStaffRole;
use App\Data\Boats\BoatTripData;
use App\Data\Onboarding\NewPropertyOwnerData;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Enums\DefaultRoleEnum;
use App\Enums\PricingBasis;
use App\Enums\PropertyType;
use App\Enums\SellUnit;
use App\Enums\UnitStatus;
use App\Enums\WeekendNights;
use App\Features\Boats;
use App\Models\BoatDeparture;
use App\Models\ChargeItem;
use App\Models\Company;
use App\Models\Property;
use App\Models\PropertyAgeGroup;
use App\Models\Reservation;
use App\Models\ReservationUnit;
use App\Models\Role;
use App\Models\RoomType;
use App\Models\Tax;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Database\Seeders\BookingScenariosSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Imagick;
use Laravel\Pennant\Feature;
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
     * The pixels of the page kept around a sheet or dialog in a `frame` screenshot.
     */
    public const int FRAME_MARGIN = 64;

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
     * Sign in as a Finance user of the booking scenarios property, who has `reports.view` but neither `settings.manage`
     * nor `guests.view-documents`. Reports show ID numbers masked and have no Layout button for this user. The user is
     * made through the real `AssignStaffRole` action by the property Owner, inside the test transaction.
     */
    protected function signInAsScenariosFinance(): Property
    {
        $this->property = Property::query()->where('name', BookingScenariosSeeder::NAME)->firstOrFail();
        $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();

        $finance = new User(['name' => 'Siti Finance', 'email' => 'finance@kinabalu-hostel.test', 'password' => 'Kinabalu-Finance-2026']);
        $finance->property_id = $this->property->id;
        $finance->email_verified_at = now();
        $finance->save();

        $role = Role::query()->where('property_id', $this->property->id)->where('default_role', DefaultRoleEnum::Finance)->firstOrFail();
        app(AssignStaffRole::class)($owner, $finance, $role);
        $this->actingAs($finance);

        return $this->property;
    }

    /**
     * Sign in as the portal login of Borneo Ventures, the Corporate company of the booking scenarios property. The
     * login is made through the real `SetCompanyPortalLogin` action by the property Owner, then its temp password is
     * cleared so the portal opens without the set-password step. Keeps the property on `$this->property`.
     */
    protected function signInAsCompanyPortalUser(): Company
    {
        $this->property = Property::query()->where('name', BookingScenariosSeeder::NAME)->firstOrFail();
        $company = Company::query()->where('property_id', $this->property->id)->where('name', 'Borneo Ventures Sdn Bhd')->firstOrFail();
        $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();

        $link = app(SetCompanyPortalLogin::class)($owner, $company, 'booking@borneo-ventures.test')->link;
        $user = User::query()->findOrFail($link->user_id);
        $user->forceFill(['must_change_password' => false, 'temporary_password' => null, 'temporary_password_expires_at' => null])->save();
        $this->actingAs($user);

        return $company;
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
            ['id' => null, 'name' => 'Standard Double', 'sell_unit' => SellUnit::Room, 'max_adults' => 2, 'max_children' => 1],
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
            ['id' => null, 'name' => 'Standard Double', 'sell_unit' => SellUnit::Room, 'max_adults' => 2, 'max_children' => 1],
            ['id' => null, 'name' => 'Family Longhouse', 'sell_unit' => SellUnit::Room, 'max_adults' => 4, 'max_children' => 2],
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
        $groups = [
            ['key' => 'ground', 'id' => null, 'name' => 'Ground floor', 'numbers' => ['101', '102', '103']],
            ['key' => 'garden', 'id' => null, 'name' => 'Garden block', 'numbers' => ['G1', 'G2']],
        ];

        app(SaveOnboardingRooms::class)($this->property, auth()->user(), true, $groups, $this->typeByNumber($groups, fn (): int => $roomType->id), []);
    }

    /**
     * The room type id of every number in the groups, keyed by the number in lower case, as `SaveOnboardingRooms` takes it.
     *
     * @param  list<array{key: string, id: int|null, name: string, numbers: list<string>}>  $groups
     * @param  Closure(string): int  $typeOf
     * @return array<string, int>
     */
    protected function typeByNumber(array $groups, Closure $typeOf): array
    {
        $types = [];

        foreach ($groups as $group) {
            foreach ($group['numbers'] as $number) {
                $types[mb_strtolower($number)] = $typeOf($number);
            }
        }

        return $types;
    }

    /**
     * Finish every required section of the onboarding property: two room types with rooms and a basic rate.
     */
    protected function makeOnboardingReady(): void
    {
        [$standard, $longhouse] = $this->saveOnboardingPriceableRoomTypes();
        $groups = [['key' => 'all', 'id' => null, 'name' => '', 'numbers' => ['101', '102', '103', 'L1', 'L2']]];
        app(SaveOnboardingRooms::class)($this->property, auth()->user(), false, $groups, $this->typeByNumber(
            $groups,
            fn (string $number): int => str_starts_with($number, 'L') ? $longhouse->id : $standard->id,
        ), []);
        app(SaveOnboardingBasicRate::class)($this->property, auth()->user(), WeekendNights::FriSat, true, [
            ['room_type_id' => $standard->id, 'price_by' => PricingBasis::PerRoom, 'weekday' => '180', 'weekend' => null],
            ['room_type_id' => $longhouse->id, 'price_by' => PricingBasis::PerRoom, 'weekday' => '200', 'weekend' => null],
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
     * @param  string|null  $element  A CSS selector to screenshot only that element, for a part of a page with no overlay, such as one panel.
     * @param  string|null  $frame  A CSS selector of an open sheet or dialog. The whole screen is taken, then cropped to the element and a margin of the dimmed page around it.
     * @param  int  $badgesFromLeft  Badges match only elements at least this many pixels from the left edge, which skips the staff sidebar. Pass 0 on a page without one, like the onboarding wizard.
     */
    protected function shoot(AwaitableWebpage $page, string $name, array $badges = [], array $highlight = [], ?string $element = null, ?string $frame = null, bool $checkErrors = true, int $badgesFromLeft = 256): AwaitableWebpage
    {
        $page->script("document.querySelectorAll('[data-docs-mark]').forEach((mark) => mark.remove());");

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

        if ($frame !== null) {
            return $this->shootFrame($page, "{$folder}/{$name}", $frame);
        }

        return $element === null
            ? $page->screenshot(filename: "{$folder}/{$name}")
            : $page->screenshotElement($element, filename: "{$folder}/{$name}");
    }

    /**
     * Take the whole screen, then crop it to the element plus FRAME_MARGIN pixels on each side, kept inside the screen.
     * A sheet on the right keeps a strip of the dimmed page at its left, and a dialog keeps the page around it.
     */
    private function shootFrame(AwaitableWebpage $page, string $filename, string $selector): AwaitableWebpage
    {
        $box = $page->page()->locator("{$selector} >> visible=true >> nth=-1")->boundingBox();

        $this->assertIsArray($box, "No element matches the frame selector {$selector}.");

        $page->screenshot(fullPage: false, filename: $filename);

        $path = base_path("tests/Browser/Screenshots/{$filename}.png");
        $image = new Imagick($path);
        $x = (int) max(0, floor($box['x'] - self::FRAME_MARGIN));
        $y = (int) max(0, floor($box['y'] - self::FRAME_MARGIN));
        $cropWidth = (int) min($image->getImageWidth(), ceil($box['x'] + $box['width'] + self::FRAME_MARGIN)) - $x;
        $cropHeight = (int) min($image->getImageHeight(), ceil($box['y'] + $box['height'] + self::FRAME_MARGIN)) - $y;
        $image->cropImage($cropWidth, $cropHeight, $x, $y);
        $image->setImagePage(0, 0, 0, 0);
        $image->writeImage($path);

        return $page;
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
     * Turn the Boats feature on for the property and make its boat data through the real Actions, inside the test
     * transaction: two fares (Speedboat RM 35 with SST and a child price, Ferry RM 25) and five departures. The Boat
     * in departures leave the Kuala Besut jetty at 09:00, 13:00 and 16:00 (the last one turned off); the Boat out
     * departures leave the resort jetty at 08:00 and 12:00. Call it after signing in.
     *
     * @return array{speedboat: ChargeItem, ferry: ChargeItem, in0900: BoatDeparture, in1300: BoatDeparture, in1600: BoatDeparture, out0800: BoatDeparture, out1200: BoatDeparture}
     */
    protected function enableBoats(): array
    {
        $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();
        Feature::for($this->property)->activate(Boats::class);

        $ageGroups = PropertyAgeGroup::query()->where('property_id', $this->property->id)->orderBy('min_age')->get();
        $sst = Tax::query()->where('property_id', $this->property->id)->where('name', 'like', '%SST%')->firstOrFail();

        $speedboat = app(CreateBoatFare::class)($this->property, $owner, [
            'name' => 'Speedboat', 'code' => 'SPEEDBOAT', 'default_amount' => '35.00', 'allow_override' => false, 'tax_ids' => [$sst->id],
            'child_prices' => [
                ['property_age_group_id' => $ageGroups->first()->id, 'pricing' => 'free', 'value' => null],
                ['property_age_group_id' => $ageGroups->last()->id, 'pricing' => 'percent', 'value' => '50'],
            ],
        ]);
        $ferry = app(CreateBoatFare::class)($this->property, $owner, [
            'name' => 'Ferry', 'code' => 'FERRY', 'default_amount' => '25.00', 'allow_override' => false, 'tax_ids' => [], 'child_prices' => null,
        ]);

        $departure = fn (BoatDirection $direction, string $leaves, string $arrives, ?int $seats, ?ChargeItem $fare): BoatDeparture => app(CreateBoatDeparture::class)($this->property, $owner, [
            'direction' => $direction->value, 'leaves_at' => $leaves, 'arrives_at' => $arrives,
            'pickup_point' => $direction === BoatDirection::In ? 'Kuala Besut jetty' : 'Resort jetty',
            'seats' => $seats, 'charge_item_id' => $fare?->id,
        ]);

        $in0900 = $departure(BoatDirection::In, '09:00', '09:45', 20, $speedboat);
        $in1300 = $departure(BoatDirection::In, '13:00', '13:45', 20, $speedboat);
        $in1600 = $departure(BoatDirection::In, '16:00', '16:45', null, null);
        $out0800 = $departure(BoatDirection::Out, '08:00', '08:45', 20, $speedboat);
        $out1200 = $departure(BoatDirection::Out, '12:00', '12:45', null, $ferry);

        $result = app(UpdateBoatDeparture::class)($owner, $in1600, [
            'direction' => 'in', 'leaves_at' => '16:00', 'arrives_at' => '16:45', 'pickup_point' => 'Kuala Besut jetty',
            'seats' => null, 'charge_item_id' => null, 'active' => false,
        ]);
        $this->assertNull($result->refusal, "The 16:00 boat in was not turned off: {$result->refusal}.");

        return compact('speedboat', 'ferry', 'in0900', 'in1300', 'in1600', 'out0800', 'out1200');
    }

    /**
     * Set one direction of a unit's boat trip through the real `SetBoatTrip` action, as the property Owner. A scheduled
     * trip names its departure; a charter or own boat trip names its time.
     */
    protected function setBoatTrip(ReservationUnit $unit, BoatDirection $direction, BoatMode $mode, ?BoatDeparture $departure = null, ?string $time = null, ?string $note = null): void
    {
        $owner = User::query()->where('email', 'owner@kinabalu-hostel.test')->firstOrFail();
        $result = app(SetBoatTrip::class)($owner, $unit, new BoatTripData($direction, $mode, $departure?->id, $time, $note));

        $this->assertNull($result->refusal, "The boat trip was refused: {$result->refusal}.");
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
     * Draws a numbered red badge on the element in the main area, right of the sidebar, whose text is exactly the given
     * text. A match inside an open sheet or dialog wins over the page behind it, and a match inside a button, link, menu
     * item, tab or option on screen wins over plain text. A control gets the
     * badge on its top left corner; plain text, like a label or column header, gets it centred just left of the text,
     * or just right of it when the left side would cross the staff sidebar.
     * Returns the texts it could not find.
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
                const control = (el) => el.closest('button, a, [role="menuitem"], [role="tab"], [role="option"]');
                const onScreen = (el) => el.getBoundingClientRect().top >= 0 && el.getBoundingClientRect().bottom <= window.innerHeight;
                const matches = [...document.querySelectorAll('body *')].filter(
                    (el) => same(el)
                        && ![...el.children].some(same)
                        && el.getBoundingClientRect().left >= {$fromLeft}
                        && el.getBoundingClientRect().width > 0,
                );
                const dialog = [...document.querySelectorAll('[role="dialog"]')].pop();
                const inDialog = dialog ? matches.filter((el) => dialog.contains(el)) : [];
                const pool = inDialog.length > 0 ? inDialog : matches;
                const match = pool.find((el) => control(el) && onScreen(el)) ?? pool[0];
                if (!match) { missing.push(text); return; }
                const target = control(match) ?? match;
                const box = target.getBoundingClientRect();
                const textRight = (el) => { const range = document.createRange(); range.selectNodeContents(el); return range.getBoundingClientRect().right; };
                const besideLeft = box.left - 28;
                const plain = !control(match);
                const beside = plain && besideLeft >= {$fromLeft};
                const after = plain && !beside;
                const left = beside ? besideLeft : after ? textRight(match) + 6 : Math.max(0, box.left - 14);
                const top = beside || after ? box.top + box.height / 2 - 11 : Math.max(0, box.top - 8);
                const badge = document.createElement('div');
                badge.dataset.docsMark = '';
                badge.textContent = String(index + 1);
                badge.style.cssText = 'position:fixed;z-index:99999;width:22px;height:22px;border-radius:50%;background:#dc2626;color:#fff;font:600 13px/22px sans-serif;text-align:center;box-shadow:0 0 0 2px #fff;pointer-events:none;'
                    + 'left:' + left + 'px;top:' + top + 'px;';
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
                frame.dataset.docsMark = '';
                frame.style.cssText = 'position:fixed;z-index:99998;border:3px solid #dc2626;border-radius:8px;pointer-events:none;'
                    + 'left:' + (box.left - 6) + 'px;top:' + (box.top - 6) + 'px;width:' + (box.width + 12) + 'px;height:' + (box.height + 12) + 'px;';
                document.body.appendChild(frame);
            });
            return missing;
        })();
        JS;
    }
}
