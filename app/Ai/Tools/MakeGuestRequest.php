<?php

namespace App\Ai\Tools;

use App\Actions\Concierge\SubmitConciergeRequest;
use App\Enums\GuestRequestType;
use App\Models\AiThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Send a housekeeping, maintenance or complaint request for the guest's own stay, through `SubmitConciergeRequest`, so
 * the limits of the stay page's request form apply. A refusal returns its own sentence for the guest. The chat draws a
 * sent request as a card from this result. See docs/35-ai-assistant/02-agent-and-tools.md#tools.
 */
class MakeGuestRequest implements Tool
{
    /** The types a guest may ask for in the chat. A refund goes through the refund request page or a handover. */
    private const array TYPES = [GuestRequestType::Housekeeping, GuestRequestType::Maintenance, GuestRequestType::Complaint];

    public function __construct(
        private readonly AiThread $thread,
        private readonly SubmitConciergeRequest $submit,
    ) {}

    public function description(): Stringable|string
    {
        return 'Send a request to the team for the guest\'s own stay: housekeeping (towels, cleaning), maintenance (something broken) or a complaint. First repeat the request to the guest in one short sentence and send it only after they say yes. Never for a refund, a cancel or a room move: hand over instead. The guest sees a sent request as a card.';
    }

    public function handle(Request $request): Stringable|string
    {
        $input = $request->validate([
            'type' => ['required', Rule::in(array_map(fn (GuestRequestType $type): string => $type->value, self::TYPES))],
            'details' => ['required', 'string', 'max:1000', 'regex:/\S/'],
        ]);
        $stay = $this->thread->reservationUnit;

        if ($stay === null) {
            return $this->json(['sent' => false, 'note' => 'This chat has no stay, so no request can be sent. Hand over.']);
        }

        $type = GuestRequestType::from($input['type']);
        $result = ($this->submit)($stay, $type, trim($input['details']));

        if ($result->isRefused()) {
            return $this->json(['sent' => false, 'note' => 'Not sent. Tell the guest: '.$result->refusal->label()]);
        }

        return $this->json([
            'sent' => true,
            'type' => $type->label(),
            'details' => $result->request->details,
            'note' => 'Sent to the team. The guest sees it as a card, so confirm it in a few words.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()->enum(array_map(fn (GuestRequestType $type): string => $type->value, self::TYPES))->description('The kind of request.')->required(),
            'details' => $schema->string()->description('What the guest needs, in one or two short sentences, as the guest agreed.')->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
