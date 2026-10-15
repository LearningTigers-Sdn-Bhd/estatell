<?php

use App\Enums\OnboardingStep;
use App\Http\Requests\Onboarding\OnboardingStepRequest;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/OnboardingSetup.php';

/**
 * A probe for the lock rule: a Discounts save, which depends on Basics.
 */
class DiscountsLockProbeRequest extends OnboardingStepRequest
{
    protected function step(): OnboardingStep
    {
        return OnboardingStep::Discounts;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['name' => ['required']];
    }
}

/**
 * Builds the probe for a property, as the route would bind it.
 */
function lockProbeFor(Property $property): FormRequest
{
    $request = DiscountsLockProbeRequest::create('/p/ES00000', 'PUT', ['name' => 'Discounts']);

    $route = new Route(['PUT'], 'p/{property}', []);
    $route->bind($request);
    $route->setParameter('property', $property);

    $request->setRouteResolver(fn () => $route);
    $request->setContainer(app())->setRedirector(app('redirect'));

    return $request;
}

test('a step save is refused with a validation error while a dependency is not done', function () {
    $property = Property::factory()->onboarding()->create();

    expect(fn () => lockProbeFor($property)->validateResolved())
        ->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toBe(['onboarding' => ['Finish Basics first.']]);
        });
});

test('the same save passes once the dependency is done', function () {
    $property = Property::factory()->onboarding()->create();
    completeOnboardingBasics($property);

    expect(lockProbeFor($property)->validateResolved())->toBeNull();
});

test('a save is refused on the lock even when its own fields are invalid', function () {
    $property = Property::factory()->onboarding()->create();
    $request = lockProbeFor($property);
    $request->merge(['name' => null]);

    expect(fn () => $request->validateResolved())
        ->toThrow(function (ValidationException $exception) {
            expect($exception->errors())->toHaveKeys(['name', 'onboarding']);
        });
});
