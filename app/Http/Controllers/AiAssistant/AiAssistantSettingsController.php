<?php

namespace App\Http\Controllers\AiAssistant;

use App\Actions\AiAssistant\RemoveAiKey;
use App\Actions\AiAssistant\SaveAiHandoverMessage;
use App\Actions\AiAssistant\SaveAiKey;
use App\Actions\AiAssistant\SetAiMonthlyCap;
use App\Actions\AiAssistant\SetAiScopesEnabled;
use App\Actions\AiAssistant\TestAiKey;
use App\Data\AiAssistant\AiAssistantSettingsData;
use App\Data\AiAssistant\AiAssistantStatusData;
use App\Data\AiAssistant\AiGeneralSettingsData;
use App\Enums\AiKeyStatus;
use App\Enums\AiScopeKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiAssistant\UpdateAiHandoverMessageRequest;
use App\Http\Requests\AiAssistant\UpdateAiKeyRequest;
use App\Http\Requests\AiAssistant\UpdateAiMonthlyCapRequest;
use App\Http\Requests\AiAssistant\UpdateAiScopesRequest;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings › AI assistant: the Places, Capabilities and General tabs. Each tab is its own URL and sends only its own
 * data, plus the status rail every tab shows. See docs/34-ai-assistant/01-overview.md#settings.
 */
class AiAssistantSettingsController extends Controller
{
    /**
     * Places tab: the switch for each place the admin allows.
     */
    public function index(Property $property): Response
    {
        return $this->renderTab('settings/ai-assistant/index', $property, AiAssistantSettingsData::forProperty($property, AiScopeKind::Place));
    }

    /**
     * Capabilities tab: the switch for each capability the admin allows.
     */
    public function capabilities(Property $property): Response
    {
        return $this->renderTab('settings/ai-assistant/capabilities', $property, AiAssistantSettingsData::forProperty($property, AiScopeKind::Capability));
    }

    /**
     * General tab: the hotel's own OpenAI key, the monthly cap and the handover message.
     */
    public function general(Property $property): Response
    {
        return $this->renderTab('settings/ai-assistant/general', $property, AiGeneralSettingsData::forProperty($property));
    }

    public function saveKey(UpdateAiKeyRequest $request, Property $property, SaveAiKey $saveAiKey): RedirectResponse
    {
        Inertia::flash('toast', $this->keyToast($saveAiKey($request->user(), $property, $request->string('key')->toString())));

        return back();
    }

    public function testKey(Property $property, TestAiKey $testAiKey): RedirectResponse
    {
        Inertia::flash('toast', $this->keyToast($testAiKey($property)));

        return back();
    }

    public function removeKey(Request $request, Property $property, RemoveAiKey $removeAiKey): RedirectResponse
    {
        if ($removeAiKey($request->user(), $property)) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('OpenAI key removed. The assistant is off.')]);
        }

        return back();
    }

    public function monthlyCap(UpdateAiMonthlyCapRequest $request, Property $property, SetAiMonthlyCap $setAiMonthlyCap): RedirectResponse
    {
        if ($setAiMonthlyCap($request->user(), $property, $request->capMicros())) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Monthly cap saved.')]);
        }

        return back();
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

    /**
     * The toast after a key test: it works, OpenAI refused it, or OpenAI could not be reached.
     *
     * @return array{type: string, message: string}
     */
    private function keyToast(?AiKeyStatus $status): array
    {
        return match ($status) {
            AiKeyStatus::Working => ['type' => 'success', 'message' => __('Your OpenAI key works.')],
            AiKeyStatus::Failing => ['type' => 'error', 'message' => __('OpenAI refused this key. Check the key and your OpenAI credit.')],
            null => ['type' => 'error', 'message' => __('We could not reach OpenAI. Try again in a minute.')],
        };
    }

    /**
     * One tab of Settings › AI assistant: its own data and the status rail.
     */
    private function renderTab(string $component, Property $property, AiAssistantSettingsData|AiGeneralSettingsData $settings): Response
    {
        return Inertia::render($component, [
            'settings' => $settings,
            'status' => AiAssistantStatusData::forProperty($property),
        ]);
    }
}
