<?php

namespace App\Http\Controllers\AiAssistant;

use App\Actions\AiAssistant\ResolveAiThread;
use App\Data\AiAssistant\AiThreadData;
use App\Data\AiAssistant\AiThreadRowData;
use App\Enums\AiPlace;
use App\Enums\AiThreadStatus;
use App\Http\Controllers\Controller;
use App\Models\AiThread;
use App\Models\Property;
use App\Services\AiAssistant\AiThreadList;
use App\Services\AiAssistant\AiThreads;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\LaravelData\PaginatedDataCollection;

/**
 * The AI chats inbox: the Handed over and All tabs, the chat Sheet (`?thread={id}`) and Resolve. Behind `ai-assistant`
 * and `front-desk.view`. See docs/35-ai-assistant/03-places-and-routes.md#ai-chats-inbox.
 */
class AiChatsController extends Controller
{
    public function index(Request $request, Property $property, AiThreadList $list, AiThreads $threads): Response
    {
        return $this->page($request, $property, 'handed-over', $list->handedOver($property, $this->filters($request)), $list, $threads);
    }

    public function all(Request $request, Property $property, AiThreadList $list, AiThreads $threads): Response
    {
        return $this->page($request, $property, 'all', $list->all($property, $this->filters($request)), $list, $threads);
    }

    public function resolve(Request $request, Property $property, string $thread, AiThreadList $list, ResolveAiThread $resolve): RedirectResponse
    {
        $found = $list->find($property, $thread);
        abort_if($found === null, 404);

        Inertia::flash('toast', $resolve($request->user(), $found)
            ? ['type' => 'success', 'message' => __('Handover resolved.')]
            : ['type' => 'error', 'message' => __('This chat is no longer handed over.')]);

        return back();
    }

    /**
     * @param  LengthAwarePaginator<int, AiThread>  $threads
     */
    private function page(Request $request, Property $property, string $tab, LengthAwarePaginator $threads, AiThreadList $list, AiThreads $messages): Response
    {
        return Inertia::render('ai-chats/index', [
            'tab' => $tab,
            'threads' => AiThreadRowData::collect($threads, PaginatedDataCollection::class),
            'filters' => $this->filters($request),
            'places' => AiPlace::guestOptions(),
            'handedOverCount' => $list->handedOverCount($property),
            'selectedThread' => $this->selectedThread($request, $property, $list, $messages),
        ]);
    }

    /**
     * @return array{place?: string, from?: string, to?: string}
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'place' => $request->string('filter.place')->toString(),
            'from' => $request->string('filter.from')->toString(),
            'to' => $request->string('filter.to')->toString(),
        ]);
    }

    /**
     * The thread whose Sheet is open, from `?thread=`, only this property's.
     */
    private function selectedThread(Request $request, Property $property, AiThreadList $list, AiThreads $messages): ?AiThreadData
    {
        $threadId = $request->string('thread')->toString();
        $thread = $threadId === '' ? null : $list->find($property, $threadId);

        return $thread === null ? null : AiThreadData::fromModel($thread, $messages->uiMessages($thread), $thread->status === AiThreadStatus::Escalated);
    }
}
