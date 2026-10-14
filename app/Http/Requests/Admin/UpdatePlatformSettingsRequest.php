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
     * A missing checkbox means false.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'require_go_live_approval' => $this->boolean('require_go_live_approval'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'require_go_live_approval' => ['required', 'boolean'],
            'default_dorm_strategy' => ['required', Rule::enum(DormStrategy::class)],
            'media_disk' => ['required', Rule::enum(MediaDisk::class)->only(MediaDisk::configured())],
        ];
    }

    public function toPlatformSettingsData(): PlatformSettingsInputData
    {
        return new PlatformSettingsInputData(
            requireGoLiveApproval: $this->boolean('require_go_live_approval'),
            defaultDormStrategy: DormStrategy::from($this->string('default_dorm_strategy')->toString()),
            mediaDisk: MediaDisk::from($this->string('media_disk')->toString()),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'media_disk.enum' => __('This storage is not set up yet.'),
        ];
    }
}
