<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Http\Requests\Concerns\ValidatesOtaListRows;
use Illuminate\Validation\Validator;

/**
 * The rows of the onboarding OTA lists table. The rules are shared with Settings → OTA lists (see ValidatesOtaListRows).
 */
class SaveOtaCredentialsRequest extends OnboardingStepRequest
{
    use ValidatesOtaListRows;

    protected function step(): OnboardingStep
    {
        return OnboardingStep::ChannelManager;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);

        $this->checkOtaListRows($validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->otaListRules();
    }
}
