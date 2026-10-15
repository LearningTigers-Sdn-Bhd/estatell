<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The item key of a Recommended next row. DismissRecommendedItem decides whether the key may be dismissed.
 */
class DismissRecommendedItemRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'item' => ['required', 'string', 'max:60'],
        ];
    }
}
