<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Enums\EventStatus;
use App\Enums\FolioStatus;
use App\Enums\FolioType;
use App\Models\Company;
use App\Models\Event;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Property;
use App\Services\NightAudit\NextDocumentNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * A Confirmed one-day event on the frozen test day, paid by a guest. It has no folio: use `withFolio()`, or
     * `CreateEvent` as production does.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'number' => fn (array $attributes): string => app(NextDocumentNumber::class)(
                Property::query()->findOrFail((int) $attributes['property_id']),
                DocumentType::Event,
            )->reference,
            'name' => 'Lim & Tan wedding dinner',
            'payer_guest_id' => fn (array $attributes): int => Guest::factory()->create(['property_id' => $attributes['property_id']])->id,
            'payer_company_id' => null,
            'starts_on' => '2026-10-20',
            'ends_on' => '2026-10-20',
            'status' => EventStatus::Confirmed,
        ];
    }

    public function tentative(): static
    {
        return $this->state(fn (array $attributes) => ['status' => EventStatus::Tentative]);
    }

    public function paidByCompany(?Company $company = null): static
    {
        return $this->state(fn (array $attributes) => [
            'payer_guest_id' => null,
            'payer_company_id' => $company->id ?? fn (array $attributes): int => Company::factory()->create(['property_id' => $attributes['property_id']])->id,
        ]);
    }

    /**
     * Give the event its Open Event folio, paid by the event's payer.
     */
    public function withFolio(): static
    {
        return $this->afterCreating(function (Event $event): void {
            $folio = new Folio([
                'event_id' => $event->id,
                'number' => app(NextDocumentNumber::class)($event->property, DocumentType::Folio)->reference,
                'type' => FolioType::Event,
                'status' => FolioStatus::Open,
                'payer_guest_id' => $event->payer_guest_id,
                'payer_company_id' => $event->payer_company_id,
            ]);
            $folio->property_id = $event->property_id;
            $folio->save();
        });
    }
}
