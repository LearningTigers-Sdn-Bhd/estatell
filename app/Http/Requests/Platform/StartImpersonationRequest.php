<?php

namespace App\Http\Requests\Platform;

use App\Enums\ImpersonationReason;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StartImpersonationRequest extends FormRequest
{
    /**
     * The support user may view this property, or the property's code gives a 404.
     */
    public function authorize(): bool
    {
        Gate::authorize('viewAs', $this->property());

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'user' => ['required', 'integer', Rule::exists('users', 'id')
                ->where('property_id', $this->property()->id)
                ->whereNull('deactivated_at')],
            'reason' => ['required', Rule::enum(ImpersonationReason::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'to' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function property(): Property
    {
        /** @var Property */
        return $this->route('property');
    }
}
