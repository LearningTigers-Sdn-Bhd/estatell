<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Channels\CreateOtaChannel;
use App\Actions\Channels\ReorderOtaChannels;
use App\Actions\Channels\SetOtaChannelActive;
use App\Actions\Channels\UpdateOtaChannel;
use App\Data\Channels\OtaChannelData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OtaChannelRequest;
use App\Http\Requests\Admin\ReorderOtaChannelsRequest;
use App\Http\Requests\Admin\SetOtaChannelActiveRequest;
use App\Models\OtaChannel;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OtaChannelController extends Controller
{
    /**
     * List every channel, active or not. The list is bounded, so it loads once.
     */
    public function index(): Response
    {
        return Inertia::render('admin/channels/index', [
            'channels' => OtaChannel::query()->ordered()->get()
                ->map(fn (OtaChannel $channel): OtaChannelData => OtaChannelData::fromModel($channel)),
        ]);
    }

    public function store(OtaChannelRequest $request, CreateOtaChannel $createOtaChannel): RedirectResponse
    {
        $channel = $createOtaChannel($request->user(), $request->string('name')->toString());

        if ($channel === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('A channel with this name already exists.')]);

            return to_route('admin.channels.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $channel->name])]);

        return to_route('admin.channels.index');
    }

    public function update(OtaChannelRequest $request, OtaChannel $channel, UpdateOtaChannel $updateOtaChannel): RedirectResponse
    {
        if (! $updateOtaChannel($request->user(), $channel, $request->string('name')->toString())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This channel cannot be renamed.')]);

            return to_route('admin.channels.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel renamed.')]);

        return to_route('admin.channels.index');
    }

    public function active(SetOtaChannelActiveRequest $request, OtaChannel $channel, SetOtaChannelActive $setOtaChannelActive): RedirectResponse
    {
        if (! $setOtaChannelActive($request->user(), $channel, $request->boolean('active'))) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('That change did not apply. Reload the page and try again.')]);

            return to_route('admin.channels.index');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel saved.')]);

        return to_route('admin.channels.index');
    }

    public function reorder(ReorderOtaChannelsRequest $request, ReorderOtaChannels $reorderOtaChannels): RedirectResponse
    {
        if (! $reorderOtaChannels($request->user(), $request->orderedIds())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The channels changed. Reload the page and try again.')]);

            return to_route('admin.channels.index');
        }

        return to_route('admin.channels.index');
    }
}
