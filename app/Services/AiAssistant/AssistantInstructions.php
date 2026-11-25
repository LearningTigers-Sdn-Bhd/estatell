<?php

namespace App\Services\AiAssistant;

use App\Enums\AiPlace;
use App\Models\AiThread;
use App\Models\Property;
use App\Services\Concierge\ConciergeContent;
use App\Services\Concierge\ConciergeGuide;
use App\Services\Property\PropertyToday;

/**
 * The instructions of one AI assistant turn: the rules, the place, what a guest may know about the property, the
 * language rule and the handover message. Only what a guest may see goes in: never Wi-Fi, hidden FAQs or hidden topics.
 * See docs/34-ai-assistant/02-agent-and-tools.md#instructions.
 */
class AssistantInstructions
{
    public function __construct(
        private readonly ConciergeGuide $guide,
        private readonly ConciergeContent $content,
        private readonly AiHandoverMessage $handover,
        private readonly PropertyToday $today,
    ) {}

    public function for(AiThread $thread): string
    {
        $property = $thread->property;

        return implode("\n\n", [
            $this->rules($property),
            $this->place($thread->place),
            "# The property\n\n".$this->json($this->knowledge($property)),
            $this->language($property),
            "# Handover\n\nWhen you hand over, call the HandOver tool and then tell the guest exactly this:\n".$this->handover->forProperty($property),
            // Last, so the parts before it stay the same all day for the provider's prompt cache.
            "# Today\n\nToday at the property is ".$this->today->date($property)->format('l j F Y (Y-m-d)').'. Work out dates such as "next weekend" from it.',
        ]);
    }

    /**
     * What the agent knows about the property, keyed by part. The same content the Concierge guide shows a guest.
     *
     * @return array<string, mixed>
     */
    public function knowledge(Property $property): array
    {
        return [
            'property' => $this->guide->property($property)->toArray(),
            'amenities' => $this->content->amenities($property)->map->toArray()->all(),
            'policies' => $this->guide->policies($property)->toArray(),
            'faqs' => array_map(fn ($faq): array => $faq->toArray(), $this->guide->faqs($property)),
            'contact' => $this->guide->contacts($property)->toArray(),
        ];
    }

    private function rules(Property $property): string
    {
        return <<<TEXT
            You are the assistant of {$property->name}, a place to stay. You help guests with questions about this property only.

            # Rules

            - Answer only about this property and the guest's stay. Politely decline anything else.
            - Use only the facts below and what your tools return. If the answer is not there, say you do not know and hand over.
            - For a broad question, such as what to know before booking, sum up the key facts (times, deposit, house rules) instead of handing over.
            - Never invent or work out a price, a free room, a time or a phone number.
            - Never promise a cancellation, a refund, a discount or a room change. Hand over instead.
            - Never share a Wi-Fi password. A checked-in guest finds it on their stay page.
            - Keep replies short and friendly. Use plain text with short lists when they help.
            - When you list rooms and prices, number them, one room and plan per line (1. Garden Room, room only: RM 170). A guest who replies with a number picks that line.
            - When you list rooms and prices, end with one short line on why the stay is worth it, using only the facts below (for example the pool, the beach or what breakfast offers). Never push.
            - When the guest gives a date without a year, use the next time that date comes from today, and write the year in your reply (28 August 2027). Do not ask the guest to confirm the year.
            TEXT;
    }

    private function place(AiPlace $place): string
    {
        $who = match ($place) {
            AiPlace::ConciergeLobby => 'You are on the property\'s guest page. You do not know who is asking or whether they have a booking.',
            AiPlace::ConciergeStay => 'You are on the stay page of a guest with a booking. You cannot see or change their booking yet; for questions about their own bill or dates, hand over.',
            default => 'You are answering on behalf of the property.',
        };

        return "# Where you are\n\n".$who;
    }

    private function language(Property $property): string
    {
        $default = $property->settings->default_locale->label();

        return "# Language\n\nReply in the language the guest writes in. When you cannot tell, reply in {$default}.";
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
