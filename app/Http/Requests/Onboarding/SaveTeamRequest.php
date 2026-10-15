<?php

namespace App\Http\Requests\Onboarding;

use App\Enums\OnboardingStep;
use App\Models\Property;
use App\Services\Onboarding\TeamRoles;
use App\Services\Staff\StaffImportReader;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * The new rows of the Team table. Every row follows the Bulk add rules and takes one of the roles `TeamRoles` offers.
 * A row with no name, email or role is dropped. No rows is a valid save.
 */
class SaveTeamRequest extends OnboardingStepRequest
{
    protected function step(): OnboardingStep
    {
        return OnboardingStep::Team;
    }

    /**
     * The `onboarding.only` middleware limits who can reach this route.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('rows');

        if (! is_array($rows)) {
            return;
        }

        $rows = array_map(
            fn (mixed $row): mixed => is_array($row)
                ? [...$row, 'name' => trim((string) ($row['name'] ?? '')), 'email' => Str::lower(trim((string) ($row['email'] ?? '')))]
                : $row,
            $rows,
        );

        $this->merge(['rows' => array_values(array_filter(
            $rows,
            fn (mixed $row): bool => ! is_array($row) || $row['name'] !== '' || $row['email'] !== '' || filled($row['role_id'] ?? null),
        ))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        if (! $property instanceof Property) {
            throw new LogicException('The property route binding is missing.');
        }

        return [
            'rows' => ['present', 'array', 'max:'.StaffImportReader::MAX_ROWS],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.email' => [
                'required', 'string', 'email', 'max:255', 'distinct',
                Rule::unique('users', 'email'),
                Rule::unique('staff_invitations', 'email')
                    ->where('property_id', $property->id)
                    ->whereNull('accepted_at')
                    ->whereNull('revoked_at'),
            ],
            'rows.*.role_id' => ['required', 'integer', Rule::in(app(TeamRoles::class)->for($property)->modelKeys())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.*.name.required' => __('Enter a name.'),
            'rows.*.email.required' => __('Enter an email.'),
            'rows.*.email.email' => __('Enter a valid email.'),
            'rows.*.email.distinct' => __('This email is in the list twice.'),
            'rows.*.email.unique' => __('This email already has an account or an open invitation.'),
            'rows.*.role_id.required' => __('Choose a role.'),
            'rows.*.role_id.in' => __('Choose one of the listed roles.'),
        ];
    }

    /**
     * @return list<array{name: string, email: string, role_id: int}>
     */
    public function rows(): array
    {
        return array_map(
            fn (array $row): array => ['name' => $row['name'], 'email' => $row['email'], 'role_id' => (int) $row['role_id']],
            array_values($this->validated('rows')),
        );
    }
}
