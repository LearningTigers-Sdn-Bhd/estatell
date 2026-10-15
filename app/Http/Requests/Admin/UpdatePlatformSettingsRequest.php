<?php

namespace App\Http\Requests\Admin;

use App\Data\Platform\PlatformSettingsInputData;
use App\Enums\DormStrategy;
use App\Enums\MediaDisk;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlatformSettingsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('admin.settings.channels.update') => [
                'default_dorm_strategy' => ['required', Rule::enum(DormStrategy::class)->only(DormStrategy::available())],
            ],
            $this->routeIs('admin.settings.storage.update') => [
                'media_disk' => ['required', Rule::enum(MediaDisk::class)->only(MediaDisk::configured())],
            ],
            default => ['require_go_live_approval' => ['required', 'boolean']],
        };
    }

    public function toPlatformSettingsData(): PlatformSettingsInputData
    {
        return match (true) {
            $this->routeIs('admin.settings.channels.update') => new PlatformSettingsInputData(
                defaultDormStrategy: DormStrategy::from($this->validated('default_dorm_strategy')),
            ),
            $this->routeIs('admin.settings.storage.update') => new PlatformSettingsInputData(
                mediaDisk: MediaDisk::from($this->validated('media_disk')),
            ),
            default => new PlatformSettingsInputData(requireGoLiveApproval: $this->boolean('require_go_live_approval')),
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_dorm_strategy.enum' => __('This dorm strategy is not available yet. Native dorm is pending verification.'),
            'media_disk.enum' => __('This storage is not set up yet.'),
        ];
    }
}
