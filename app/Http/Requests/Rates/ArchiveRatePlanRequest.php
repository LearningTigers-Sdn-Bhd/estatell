<?php

namespace App\Http\Requests\Rates;

use App\Models\RatePlan;
use App\Services\Channels\ChannelArchiveGuard;
use App\Services\Rates\DerivedRatePlans;
use App\Services\Rates\RatePlanCoverage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ArchiveRatePlanRequest extends FormRequest
{
    /**
     * The `can:settings.manage` route middleware authorizes this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * A plan cannot be archived while an active room type would be left with no active plan, or while active plans follow it.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var RatePlan $ratePlan */
                $ratePlan = $this->route('ratePlan');
                $message = app(DerivedRatePlans::class)->followersMessage($ratePlan) ?? app(RatePlanCoverage::class)->message($ratePlan) ?? app(ChannelArchiveGuard::class)->ratePlanMessage($ratePlan);

                if ($message !== null) {
                    $validator->errors()->add('rate_plan', $message);
                }
            },
        ];
    }
}
