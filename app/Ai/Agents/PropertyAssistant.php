<?php

namespace App\Ai\Agents;

use App\Ai\Tools\CheckRoomsAndPrices;
use App\Ai\Tools\HandOver;
use App\Ai\Tools\MakeGuestRequest;
use App\Ai\Tools\PropertyFacts;
use App\Ai\Tools\ReadMyStay;
use App\Ai\Tools\SendQuoteLink;
use App\Enums\AiTool;
use App\Models\AiThread;
use App\Services\AiAssistant\AiKeyProvider;
use App\Services\AiAssistant\AiScopes;
use App\Services\AiAssistant\AssistantInstructions;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Provider;
use Stringable;

/**
 * The AI assistant of one property, for one thread. The thread's place decides the tools, and a tool whose capability
 * scope is off is left out. At most four tool calls and a reply per turn. The model comes from `config/ai.php`, and the
 * provider is OpenAI on the hotel's own key. See docs/35-ai-assistant/02-agent-and-tools.md.
 */
#[MaxSteps(5)]
#[Timeout(60)]
class PropertyAssistant implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        public readonly AiThread $thread,
        private readonly AssistantInstructions $instructions,
        private readonly AiScopes $scopes,
    ) {}

    /**
     * OpenAI on the hotel's own key. See docs/35-ai-assistant/01-overview.md#api-key.
     */
    public function provider(): Provider
    {
        return app(AiKeyProvider::class)->for($this->thread->property);
    }

    public function instructions(): Stringable|string
    {
        return $this->instructions->for($this->thread);
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        $property = $this->thread->property;
        $tools = [];

        foreach ($this->thread->place->tools() as $tool) {
            $scope = $tool->scope();

            if ($scope !== null && ! $this->scopes->isOn($property, $scope)) {
                continue;
            }

            $instance = match ($tool) {
                AiTool::PropertyFacts => app(PropertyFacts::class, ['property' => $property]),
                AiTool::CheckRoomsAndPrices => app(CheckRoomsAndPrices::class, ['property' => $property]),
                AiTool::SendQuoteLink => app(SendQuoteLink::class, ['property' => $property]),
                AiTool::ReadMyStay => app(ReadMyStay::class, ['thread' => $this->thread]),
                AiTool::MakeGuestRequest => app(MakeGuestRequest::class, ['thread' => $this->thread]),
                AiTool::HandOver => app(HandOver::class, ['thread' => $this->thread]),
                default => null,
            };

            if ($instance !== null) {
                $tools[] = $instance;
            }
        }

        return $tools;
    }
}
