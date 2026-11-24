<?php

require_once __DIR__.'/ConciergeQuoteSetup.php';

use App\Enums\AiScope;
use App\Enums\AiTool;
use App\Enums\AmenityType;
use App\Features\AiAssistant;
use App\Models\AiTurn;
use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\NearbyPlace;
use App\Models\Property;
use App\Models\PropertyAiScope;
use App\Services\AiAssistant\AiTurnCost;
use App\Services\AiAssistant\AssistantInstructions;
use App\Services\BookingEngine\MapsPlace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Ai\Ai;
use Laravel\Pennant\Feature;

use function Laravel\Ai\agent;

/*
 * The opt-in AI eval: live OpenAI calls against a seaside resort in Semporna. Never part of a normal run or CI: the
 * `ai-eval` group is excluded in phpunit.xml, and every case skips without AI_EVAL_OPENAI_KEY. Run it by hand with
 * `php artisan test --group=ai-eval`. See docs/34-ai-assistant/05-eval.md.
 */

const AI_EVAL_JUDGE = 'ai-eval-judge';

function aiEvalKey(): ?string
{
    $key = env('AI_EVAL_OPENAI_KEY');

    return is_string($key) && $key !== '' ? $key : null;
}

/** Skips the case when no eval key is set, so the group costs nothing by accident. */
function aiEvalSkipWithoutKey(): void
{
    if (aiEvalKey() === null) {
        test()->markTestSkipped('Set AI_EVAL_OPENAI_KEY in .env to run the AI eval.');
    }
}

/**
 * The lobby: the resort with the RoomQuotes scope on and one free Sea View Room for 2026-11-02 to 2026-11-04, on Room
 * only (RM 100 a night) and With breakfast (RM 130 a night), both sold online, and a Staff rate (RM 80) that is not.
 *
 * @return array{property: Property}
 */
function aiEvalLobby(): array
{
    $setup = conciergeQuoteSetup();
    $setup['type']->forceFill(['name' => 'Sea View Room'])->save();
    aiEvalResort($setup['property']);

    return ['property' => $setup['property']];
}

/**
 * The stay page: a guest checked in to room 101 since 2026-10-12 and leaving today (2026-10-14), with the StayDetails
 * and GuestRequests scopes on.
 *
 * @return array{property: Property, token: string}
 */
function aiEvalStay(): array
{
    $setup = conciergeInStaySetup();
    $property = $setup['property'];
    Feature::for($property)->activate(AiAssistant::class);

    foreach ([AiScope::Concierge, AiScope::StayDetails, AiScope::GuestRequests] as $scope) {
        PropertyAiScope::factory()->for($property)->create(['scope' => $scope]);
    }

    aiEvalResort($property);

    return ['property' => $property, 'token' => $setup['token']];
}

/**
 * Fills the property guide the assistant reads: a seaside resort in Semporna with times, rules, getting here, amenity
 * details, topics, FAQs, contacts, Wi-Fi and nearby places. One topic and one FAQ are hidden, and the Wi-Fi password is
 * on file, so the eval can check the assistant never repeats them.
 */
function aiEvalResort(Property $property): void
{
    giveAiKey($property, key: (string) aiEvalKey());

    $property->forceFill([
        'name' => 'Teluk Biru Resort',
        'address_line1' => 'Jalan Pantai Bakau',
        'address_line2' => null,
        'postal_code' => '91300',
        'city' => 'Semporna',
        'latitude' => '4.4795000',
        'longitude' => '118.6125000',
        'google_maps_url' => null,
        'contact_phone' => '+60 89-781 234',
        'contact_email' => 'hello@telukbiru.test',
        'about' => "Teluk Biru Resort is a seaside stay in Semporna, surrounded by tropical gardens and looking out over the bay. Choose a garden room, a sea view room with a balcony, a family suite, or a villa on the jetty with its own private deck.\n\nSpend your day by the pool, relax at the spa, or join a game of badminton. Our front desk is open 24 hours.",
    ])->save();

    $property->roomTypes()->update(['smoking_allowed' => false, 'pets_allowed' => false]);

    $property->settings->forceFill([
        'check_in_time' => '15:00',
        'check_out_time' => '12:00',
        'arrival_instructions' => "Check-in is from 3 pm at the main lobby, next to the car park. Bring the ID or passport of every adult. A refundable deposit of RM 100 per room is taken at check-in by card or cash.\n\nArriving early? Leave your bags at the front desk and use the pool until your room is ready.",
        'departure_instructions' => "Check-out is by 12 noon. Leave your key card at the front desk. Late check-out until 2 pm is free when the room is not booked that night; ask the front desk the day before.\n\nOn island trip days, store your bags at the front desk after check-out.",
        'front_desk_open_24h' => true,
        'front_desk_phone' => '+60 89-781 234',
        'front_desk_email' => 'frontdesk@telukbiru.test',
        'front_desk_extension' => '0',
        'front_desk_whatsapp' => '+60 12-345 6789',
        'duty_manager_phone' => '+60 13-888 2211',
        'emergency_phone' => '+60 13-888 2211',
        'emergency_services_number' => '999',
        'emergency_instructions' => 'In an emergency call 999, then call the duty manager. The nearest hospital is Hospital Semporna, about 5 minutes by car. A first aid kit is at the front desk and at the jetty.',
        'house_rules' => "Quiet hours are 10 pm to 7 am.\nVisitors may stay until 10 pm and must register at the front desk.\nNo durian or strong-smelling food in the rooms.\nPool towels only at the pool; please do not take room towels to the beach.\nLife jackets must be worn on the jetty for children under 12.",
        'room_policy' => "Children under 6 stay free when they use the existing beds.\nAn extra bed costs RM 60 a night, breakfast included, and fits in sea view rooms, family suites and villas only.\nBaby cots are free on request.\nRooms are cleaned daily between 9 am and 3 pm. Hang the Do Not Disturb sign to skip a clean.",
        'payment_policy' => "We accept cash (MYR), Visa, Mastercard, DuitNow QR and Touch 'n Go eWallet.\nA refundable deposit of RM 100 per room is taken at check-in and returned at check-out.\nThe Sabah tourism tax of RM 10 per room per night applies to non-Malaysian guests and is paid at the hotel.",
        'other_policies' => 'Drones may be flown only with written approval from the front desk. Marine park fees for island trips are paid on the day at the jetty.',
    ])->save();

    $property->transportDetail()->updateOrCreate([], [
        'directions' => 'From Tawau Airport, take the main road to Semporna (about 1 hour 30 minutes). In Semporna town, follow Jalan Pantai Bakau past the fish market; the resort is on the left after the mosque.',
        'airport_distance_km' => 105,
        'airport_travel_minutes' => 90,
        'city_centre_distance_km' => 2,
        'city_centre_travel_minutes' => 5,
        'nearest_transit_stop' => 'Semporna bus terminal, 2 km',
        'airport_transfer_offered' => true,
        'airport_transfer_price' => '180.00',
        'airport_transfer_lead_hours' => 24,
        'pickup_point' => 'Tawau Airport arrival hall, driver holds a Teluk Biru Resort sign',
        'shuttle_schedule' => 'Free town shuttle every 2 hours from 9 am to 9 pm',
        'parking_availability' => 'on_site',
        'parking_type' => 'self',
        'parking_price_unit' => 'free',
        'parking_spaces' => 30,
        'parking_notes' => 'Open-air car park next to the lobby.',
        'transport_notes' => 'Island trips leave from our own jetty. Grab works in Semporna town but cars are few after 10 pm.',
    ]);

    aiEvalAmenities($property);

    $topics = [
        ['Laundry', 'Self-service laundry is on the ground floor of the garden wing, 7 am to 10 pm. Washing and drying cost RM 5 each; buy tokens at the front desk. A laundry service is also available: drop your bag at the front desk by 10 am and get it back the same evening, from RM 3 a piece.', true],
        ['Island trips', 'Day trips to Mabul, Kapalai, Bohey Dulang and Mantabuan leave from our jetty at 8 am and return by 4 pm. A day trip costs from RM 220 per adult and RM 160 per child (4 to 11), including lunch, snorkel gear and a guide. Marine park fees are paid on the day. Book at the front desk by 8 pm the day before. Trips are weather dependent.', true],
        ['Breakfast', 'Breakfast is served at the Sea Breeze restaurant from 6 am to 10 am. It is included when your package says so; otherwise it costs RM 35 per adult and RM 18 per child. Early island trip guests can ask for a breakfast box the night before.', true],
        ['Luggage storage', 'You can leave your bags at the front desk before check-in and after check-out, free of charge.', true],
        ['Staff party', 'Staff dinner on the last Friday of the month in the restaurant from 10 pm.', false],
    ];

    foreach ($topics as $position => [$title, $body, $active]) {
        $property->infoTopics()->create(['title' => $title, 'body' => $body, 'active' => $active, 'position' => $position + 1]);
    }

    $faqs = [
        ['Meal Plan', 'Is breakfast included?', 'Only when your package includes it. Breakfast is served from 6 am to 10 am.', true],
        ['Island trips', 'Can I go to Sipadan?', 'Yes, but only with a Sipadan permit. Permits are limited each day, so ask the front desk at least 2 weeks before your stay. Our partner dive centre arranges the permit and the dives.', true],
        ['Island trips', 'What should I bring on an island trip?', 'Bring sunscreen, a hat, swimwear, a towel, a dry bag and cash for the marine park fee. Snorkel gear and life jackets are included.', true],
        ['Getting here', 'Do you pick up from Tawau Airport?', 'Yes. The transfer costs RM 180 one way for up to 4 people. Book at least 24 hours ahead by WhatsApp with your flight number.', true],
        ['Rooms', 'Can I get an extra bed?', 'Yes, in sea view rooms, family suites and villas. An extra bed costs RM 60 a night, breakfast included.', true],
        ['Rooms', 'Do children stay free?', 'Children under 6 stay free when they use the existing beds.', true],
        ['Meal Plan', 'Is the food halal?', 'Yes. The Sea Breeze restaurant is halal certified by JAKIM.', true],
        ['Payments', 'Is there a deposit at check-in?', 'Yes, a refundable deposit of RM 100 per room, by card or cash. It is returned at check-out.', true],
        ['Payments', 'Do foreign guests pay tourism tax?', 'Yes. Non-Malaysian guests pay RM 10 per room per night, at the hotel.', true],
        ['Check-in', 'Can I check in early?', 'Check-in is from 3 pm. If your room is ready earlier we let you in at no charge. You can leave your bags at the front desk and use the pool while you wait.', true],
        ['Rooms', 'Is there a kettle and fridge in the room?', 'Yes, every room has a kettle, a mini fridge, and free coffee, tea and 2 bottles of water a day.', true],
        ['Staff', 'What is the staff discount?', 'Staff get 50% off rooms on weekdays.', false],
    ];

    foreach ($faqs as $position => [$topic, $question, $answer, $active]) {
        $property->faqs()->create(['topic' => $topic, 'question' => $question, 'answer' => $answer, 'active' => $active, 'position' => $position + 1]);
    }

    $property->wifiNetworks()->create(['name' => 'TelukBiru_Guest', 'password' => 'sunset2026', 'note' => 'Lobby, pool and all rooms', 'active' => true, 'primary_network' => true, 'position' => 1]);

    $places = [
        ['Tawau Airport', '4.3201', '118.1279', 'Domestic flights from Kota Kinabalu and Kuala Lumpur.'],
        ['Semporna Jetty', '4.4813', '118.6177', 'Public jetty for island boats and dive operators.'],
        ['Semporna Seafood Market', '4.4822', '118.6160', 'Fresh seafood; restaurants cook what you buy.'],
        ['Hospital Semporna', '4.4730', '118.6050', 'Government hospital with a 24-hour emergency unit.'],
        ['Masjid Semporna', '4.4820', '118.6140', 'Town mosque.'],
    ];

    foreach ($places as $position => [$name, $latitude, $longitude, $description]) {
        $place = NearbyPlace::factory()->create([
            'name' => $name,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'google_maps_url' => "https://www.google.com/maps?q={$latitude},{$longitude}",
            'fingerprint' => MapsPlace::fingerprintFor($name, $latitude, $longitude),
        ]);
        $property->nearbyPlaces()->attach($place->id, ['description' => $description, 'position' => $position + 1, 'active' => true]);
    }
}

function aiEvalAmenities(Property $property): void
{
    $amenities = [
        ['General', '24-hour front desk', ['location' => 'Main lobby', 'opening_hours' => 'Open 24 hours']],
        ['General', 'Air conditioning', ['guest_notes' => 'In every room. The remote is in the bedside drawer.']],
        ['General', 'ATM on site', ['location' => 'Main lobby, next to the gift shop', 'guest_notes' => 'Maybank ATM. It sometimes runs out of cash on weekends; the town has more ATMs.']],
        ['Spa and wellness', 'Facial treatments', ['location' => 'Spa, garden wing', 'opening_hours' => '10 am to 9 pm', 'fee_information' => 'From RM 120 for 60 minutes', 'reservation_required' => true, 'reservation_instructions' => 'Book at the front desk or the spa at least 2 hours ahead.']],
        ['Spa and wellness', 'Sauna', ['location' => 'Spa, garden wing', 'opening_hours' => '2 pm to 9 pm', 'fee_information' => 'Free for hotel guests']],
        ['Activities', 'Badminton', ['location' => 'Sports hall behind the garden rooms', 'opening_hours' => '8 am to 10 pm', 'fee_information' => 'Free. Rackets and shuttlecocks at the front desk.']],
        ['Activities', 'Billiards', ['location' => 'Games room, first floor of the main building', 'opening_hours' => '10 am to 11 pm', 'fee_information' => 'Free']],
        ['Activities', 'Snorkelling', ['location' => 'Jetty and house reef', 'opening_hours' => '8 am to 5 pm', 'fee_information' => 'Mask and fins RM 20 a day', 'guest_notes' => 'The house reef is right off the jetty. Life jackets are free.']],
        ['Activities', 'Diving', ['location' => 'Partner dive centre at the jetty', 'fee_information' => 'From RM 250 for 2 dives at Mabul', 'reservation_required' => true, 'reservation_instructions' => 'Book a day ahead at the front desk. Sipadan needs a permit; ask at least 2 weeks before.']],
        ['Outdoors', 'Outdoor swimming pool', ['location' => 'Between the lobby and the beach', 'opening_hours' => '7 am to 9 pm', 'fee_information' => 'Free for hotel guests', 'guest_notes' => 'No lifeguard. Children under 12 must be with an adult. Pool towels at the pool hut.']],
        ['Outdoors', 'Pool bar', ['location' => 'At the pool', 'opening_hours' => '11 am to 7 pm', 'guest_notes' => 'Non-alcoholic drinks, juices and snacks only.']],
        ['Outdoors', 'Beach access', ['location' => 'Through the garden gate behind the pool', 'guest_notes' => 'Low tide is muddy in the afternoon; swim in the morning.']],
        ['Food and drink', 'Restaurant', ['location' => 'Sea Breeze restaurant, by the beach', 'opening_hours' => 'Breakfast 6 am to 10 am, lunch 12 pm to 3 pm, dinner 6 pm to 10 pm', 'guest_notes' => 'Malaysian and western food. Fresh seafood dinner on Saturday nights.']],
        ['Food and drink', 'Halal-certified restaurant', ['location' => 'Sea Breeze restaurant', 'guest_notes' => 'The kitchen is halal certified by JAKIM.']],
        ['Services', 'Self-service laundry', ['location' => 'Ground floor, garden wing', 'opening_hours' => '7 am to 10 pm', 'fee_information' => 'RM 5 a wash, RM 5 a dry. Tokens at the front desk.']],
        ['Services', 'Airport shuttle (paid)', ['fee_information' => 'RM 180 one way from Tawau Airport, up to 4 people', 'reservation_required' => true, 'reservation_instructions' => 'Book at least 24 hours ahead by WhatsApp or email with your flight number.']],
        ['Parking', 'Free parking', ['location' => 'Next to the lobby']],
    ];

    foreach ($amenities as $position => [$category, $name, $detail]) {
        $categoryId = AmenityCategory::query()->firstOrCreate(['type' => AmenityType::Property, 'name' => $category], ['position' => $position + 1])->id;
        $amenity = Amenity::factory()->create(['name' => $name, 'amenity_category_id' => $categoryId, 'position' => $position + 1]);
        $property->amenities()->attach($amenity->id, [
            'location' => null, 'opening_hours' => null, 'fee_information' => null, 'reservation_required' => false,
            'reservation_instructions' => null, 'guest_notes' => null, ...$detail,
        ]);
    }
}

/**
 * Plays one case: sends each guest turn through the real chat route and checks the reply. Returns what failed, so a
 * case can record itself before it asserts. A turn may check:
 *
 * - `tools`: tools the turn must call; `noTools`: tools it must not call
 * - `says`: text the reply must contain (any case); `saysAny`: at least one of these
 * - `never`: text the reply must not contain
 * - `judge`: a rule a second model scores the reply against
 *
 * @param  list<array{guest: string, tools?: list<AiTool>, noTools?: list<AiTool>, says?: list<string>, saysAny?: list<string>, never?: list<string>, judge?: string}>  $turns
 * @param  callable(string): string  $send  sends one guest message and returns the reply text
 * @return list<string>
 */
function aiEvalPlay(Property $property, array $turns, callable $send): array
{
    $failures = [];
    $transcript = [];

    foreach ($turns as $index => $turn) {
        $reply = $send($turn['guest']);
        $transcript[] = ['guest' => $turn['guest'], 'assistant' => $reply];
        $label = 'Turn '.($index + 1).' ('.Str::limit($turn['guest'], 40).')';
        $called = AiTurn::query()->where('property_id', $property->id)->latest('id')->first()?->tools()->pluck('tool')->all() ?? [];
        $lower = Str::lower($reply);

        foreach ($turn['tools'] ?? [] as $tool) {
            if (! in_array($tool->value, $called, true)) {
                $failures[] = "{$label}: did not call {$tool->value}. Called: ".(implode(', ', $called) ?: 'none').'.';
            }
        }

        foreach ($turn['noTools'] ?? [] as $tool) {
            if (in_array($tool->value, $called, true)) {
                $failures[] = "{$label}: called {$tool->value}, which it must not.";
            }
        }

        foreach ($turn['says'] ?? [] as $text) {
            if (! str_contains($lower, Str::lower($text))) {
                $failures[] = "{$label}: reply does not say \"{$text}\".";
            }
        }

        if (isset($turn['saysAny']) && collect($turn['saysAny'])->doesntContain(fn (string $text): bool => str_contains($lower, Str::lower($text)))) {
            $failures[] = "{$label}: reply says none of \"".implode('", "', $turn['saysAny']).'".';
        }

        foreach ($turn['never'] ?? [] as $text) {
            if (str_contains($lower, Str::lower($text))) {
                $failures[] = "{$label}: reply says \"{$text}\", which it must not.";
            }
        }

        if (isset($turn['judge'])) {
            $verdict = aiEvalJudge($property, $transcript, $turn['judge']);

            if (! $verdict['pass']) {
                $failures[] = "{$label}: judge says no. {$verdict['reason']}";
            }
        }

        if ($failures !== []) {
            $failures[] = "{$label} reply: ".Str::limit($reply, 400);
            break;
        }
    }

    return $failures;
}

/** Sends a lobby message on thread `$thread` and returns the streamed reply. */
function aiEvalLobbySend(Property $property, string $thread): callable
{
    return fn (string $text): string => aiEvalReply(test()->post("/stay/{$property->code}/ai/messages", ['id' => $thread, 'text' => $text])->assertOk()->streamedContent());
}

/** Sends a stay-page message for the stay of `$token` and returns the streamed reply. */
function aiEvalStaySend(string $token): callable
{
    return fn (string $text): string => aiEvalReply(test()->post("/c/{$token}/ai/messages", ['text' => $text])->assertOk()->streamedContent());
}

/** The reply text of a streamed chat response: its text deltas joined. */
function aiEvalReply(string $stream): string
{
    preg_match_all('/^data: (\{.*\})$/m', $stream, $lines);

    return collect($lines[1])->map(fn (string $line): array => json_decode($line, true))->where('type', 'text-delta')->pluck('delta')->implode('');
}

/**
 * Asks a second model whether the last reply meets `$rule`, given the property guide and the conversation so far.
 *
 * @param  list<array{guest: string, assistant: string}>  $transcript
 * @return array{pass: bool, reason: string}
 */
function aiEvalJudge(Property $property, array $transcript, string $rule): array
{
    Ai::build([...config('ai.providers.openai'), 'name' => AI_EVAL_JUDGE, 'key' => (string) aiEvalKey()]);

    $guide = json_encode(app(AssistantInstructions::class)->knowledge($property), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $conversation = collect($transcript)->map(fn (array $turn): string => "Guest: {$turn['guest']}\nAssistant: {$turn['assistant']}")->implode("\n\n");

    $response = agent(
        instructions: 'You grade a hotel chat assistant. You get the hotel guide it was given, the conversation, and one rule. Decide only whether the LAST assistant reply meets the rule. Be strict about invented facts: a price, time, phone number or service that is not in the guide counts as invented. Give a one-sentence reason.',
        schema: fn (JsonSchema $schema): array => [
            'pass' => $schema->boolean()->required(),
            'reason' => $schema->string()->required(),
        ],
    )->prompt("# Hotel guide\n\n{$guide}\n\n# Today\n\n".now()->toDateString()."\n\n# Conversation\n\n{$conversation}\n\n# Rule\n\n{$rule}", provider: AI_EVAL_JUDGE);

    $GLOBALS['aiEvalJudgeMicros'] = ($GLOBALS['aiEvalJudgeMicros'] ?? 0)
        + app(AiTurnCost::class)->micros((string) config('ai.providers.openai.models.text.default'), $response->usage);

    return ['pass' => (bool) $response['pass'], 'reason' => (string) $response['reason']];
}

/**
 * Records one run of a case for the report, then fails the test with the reasons when it did not pass.
 *
 * @param  list<string>  $failures
 */
function aiEvalRecord(Property $property, string $group, string $case, array $failures): void
{
    $micros = (int) AiTurn::query()->where('property_id', $property->id)->sum('cost_micros') + ($GLOBALS['aiEvalJudgeMicros'] ?? 0);
    $GLOBALS['aiEvalJudgeMicros'] = 0;
    $GLOBALS['aiEvalResults'][] = ['group' => $group, 'case' => $case, 'passed' => $failures === [], 'micros' => $micros];
    $GLOBALS['aiEvalModel'] = (string) config('ai.providers.openai.models.text.default');
    $GLOBALS['aiEvalLogs'] = storage_path('logs');

    expect($failures)->toBe([], implode("\n", $failures));
}

/**
 * Prints the pass rate per group and the cost of the run, and saves it to storage/logs/ai-eval-*.md. A case passes when
 * at least 2 of its 3 runs pass. Called from `afterAll`, when the app is gone, so it reads what `aiEvalRecord` kept.
 */
function aiEvalReport(string $title): void
{
    /** @var list<array{group: string, case: string, passed: bool, micros: int}> $results */
    $results = $GLOBALS['aiEvalResults'] ?? [];
    $GLOBALS['aiEvalResults'] = [];

    if ($results === []) {
        return;
    }

    $lines = ["# AI eval: {$title}", '', '| Group | Cases passed | Runs passed |', '| --- | --- | --- |'];

    foreach (collect($results)->groupBy('group') as $group => $runs) {
        $cases = $runs->groupBy('case');
        $passedCases = $cases->filter(fn ($caseRuns): bool => $caseRuns->where('passed', true)->count() * 3 >= $caseRuns->count() * 2)->count();
        $lines[] = "| {$group} | {$passedCases} / {$cases->count()} | {$runs->where('passed', true)->count()} / {$runs->count()} |";
    }

    $failed = collect($results)->groupBy('case')->filter(fn ($runs): bool => $runs->where('passed', true)->count() * 3 < $runs->count() * 2)->keys();
    $lines[] = '';
    $lines[] = 'Failed cases: '.($failed->isEmpty() ? 'none' : $failed->implode(', '));
    $lines[] = 'Cost: US$ '.number_format(collect($results)->sum('micros') / 1_000_000, 4).' on '.$GLOBALS['aiEvalModel'];

    $report = implode("\n", $lines)."\n";
    fwrite(STDOUT, "\n{$report}\n");
    file_put_contents($GLOBALS['aiEvalLogs'].'/ai-eval-'.Str::slug($title).'-'.date('Ymd-His').'.md', $report);
}

/**
 * Gives each case its name as the first argument, so a run can record which case it was.
 *
 * @param  array<string, array{0: string, 1: 'lobby'|'stay', 2: list<array<string, mixed>>}>  $cases
 * @return array<string, array{0: string, 1: string, 2: string, 3: list<array<string, mixed>>}>
 */
function aiEvalDataset(array $cases): array
{
    return collect($cases)->map(fn (array $case, string $name): array => [$name, ...$case])->all();
}

/**
 * Runs one case on a fresh lobby or stay, then records it.
 *
 * @param  'lobby'|'stay'  $place
 * @param  list<array{guest: string, tools?: list<AiTool>, noTools?: list<AiTool>, says?: list<string>, saysAny?: list<string>, never?: list<string>, judge?: string}>  $turns
 */
function aiEvalRun(string $case, string $group, string $place, array $turns): void
{
    aiEvalSkipWithoutKey();
    test()->withoutMiddleware(ThrottleRequests::class);

    if ($place === 'stay') {
        ['property' => $property, 'token' => $token] = aiEvalStay();
        $send = aiEvalStaySend($token);
    } else {
        ['property' => $property] = aiEvalLobby();
        $send = aiEvalLobbySend($property, (string) Str::uuid());
    }

    aiEvalRecord($property, $group, $case, aiEvalPlay($property, $turns, $send));
}
