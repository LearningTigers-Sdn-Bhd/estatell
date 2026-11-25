<?php

namespace App\Http\Controllers\AiAssistant;

use App\Actions\AiAssistant\SaveAiHandoverMessage;
use App\Actions\AiAssistant\SetAiScopesEnabled;
use App\Data\AiAssistant\AiAssistantSettingsData;
use App\Data\AiAssistant\AiHandoverSettingsData;
use App\Enums\AiScopeKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAssistant\UpdateAiHandoverMessageRequest;
use App\Http\Requests\AiAssistant\UpdateAiScopesRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › AI assistant: the Places, Capabilities and Handover tabs. Each tab is its own URL and sends only its own
 * data. See docs/34-ai-assistant/01-overview.md#settings.
 */
class AiAssistantSettingsController extends Controller
{
    /**
     * Places tab: the switch for each place the admin allows.
     */
    public function index(Property $property): Response
    {
        return Inertia::render('settings/ai-assistant/index', [
            'settings' => AiAssistantSettingsData::forProperty($property, AiScopeKind::Place),
        ]);
    }

    /**
     * Capabilities tab: the switch for each capability the admin allows.
     */
    public function capabilities(Property $property): Response
    {
        return Inertia::render('settings/ai-assistant/capabilities', [
            'settings' => AiAssistantSettingsData::forProperty($property, AiScopeKind::Capability),
        ]);
    }

    /**
     * Handover tab: what guests see when the assistant passes the chat to staff.
     */
    public function handover(Property $property): Response
    {
        return Inertia::render('settings/ai-assistant/handover', [
            'settings' => AiHandoverSettingsData::forProperty($property->loadMissing('settings')),
        ]);
    }

    public function scopes(UpdateAiScopesRequest $request, Property $property, SetAiScopesEnabled $setAiScopesEnabled): RedirectResponse
    {
        Inertia::flash('toast', $setAiScopesEnabled($request->user(), $property, $request->scopes())
            ? ['type' => 'success', 'message' => __('AI assistant switches saved.')]
            : ['type' => 'error', 'message' => __('Estatell no longer allows one of these. Reload the page.')]);

        return back();
    }

    public function handoverMessage(UpdateAiHandoverMessageRequest $request, Property $property, SaveAiHandoverMessage $saveAiHandoverMessage): RedirectResponse
    {
        $saveAiHandoverMessage($request->user(), $property, $request->input('handover_message'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Handover message saved.')]);

        return back();
    }
}
