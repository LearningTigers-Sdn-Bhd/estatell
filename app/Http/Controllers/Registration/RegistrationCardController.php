<?php

namespace App\Http\Controllers\Registration;

use App\Actions\Property\UpdatePropertySettings;
use App\Actions\Registration\SaveRegistrationCardFields;
use App\Data\Property\PropertySettingsData;
use App\Data\Registration\RegistrationCardFieldData;
use App\Data\Reservations\ChargeItemOptionData;
use App\Enums\PermissionEnum;
use App\Enums\RegistrationCardScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\SaveRegistrationCardFieldsRequest;
use App\Http\Requests\Registration\UpdateRegistrationCardSettingsRequest;
use App\Models\ChargeItem;
use App\Models\Property;
use App\Models\RegistrationCardFieldSetting;
use App\Services\Property\SettingsLock;
use App\Services\Registration\RegistrationCardFieldRules;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationCardController extends Controller
{
    /**
     * Registration card page, General tab. `chargeItems` are the active items the damage charge can post to.
     */
    public function general(Property $property, SettingsLock $settingsLock): Response|RedirectResponse
    {
        if ($property->settings === null) {
            return to_route('settings.edit', $property);
        }

        return $this->renderTab($property, $settingsLock, 'general', [
            'registrationCardScopes' => RegistrationCardScope::options(),
            'chargeItems' => ChargeItem::query()->where('property_id', $property->id)->active()->ordered()->get()
                ->map(ChargeItemOptionData::fromModel(...)),
        ]);
    }

    /**
     * Registration card page, Layout tab. Saved by `updateFields` on every change, so a success shows no toast.
     */
    public function layout(Property $property, SettingsLock $settingsLock, RegistrationCardFieldRules $rules): Response|RedirectResponse
    {
        if ($property->settings === null) {
            return to_route('settings.edit', $property);
        }

        return $this->renderTab($property, $settingsLock, 'layout', [
            'fields' => $property->registrationCardFields()
                ->ordered()
                ->get()
                ->map(fn (RegistrationCardFieldSetting $setting): RegistrationCardFieldData => RegistrationCardFieldData::fromModel($setting, $property, $rules)),
        ]);
    }

    public function update(UpdateRegistrationCardSettingsRequest $request, Property $property, UpdatePropertySettings $updatePropertySettings): RedirectResponse
    {
        abort_if($property->settings === null, 404);

        $updatePropertySettings($property, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Registration card settings saved.')]);

        return to_route('settings.registration-card.general', $property);
    }

    public function updateFields(SaveRegistrationCardFieldsRequest $request, Property $property, SaveRegistrationCardFields $saveRegistrationCardFields): RedirectResponse
    {
        if (! $saveRegistrationCardFields($property, $request->user(), $request->visibilities(), $request->order())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The card fields could not be saved. Reload the page and try again.')]);

            return to_route('settings.registration-card.layout', $property);
        }

        return to_route('settings.registration-card.layout', $property);
    }

    /**
     * Each tab has its own URL, so the open tab survives a refresh. Only the open tab's data is sent.
     *
     * @param  'general'|'layout'  $tab
     * @param  array<string, mixed>  $tabProps
     */
    private function renderTab(Property $property, SettingsLock $settingsLock, string $tab, array $tabProps = []): Response
    {
        return Inertia::render('settings/registration-card/edit', [
            'settings' => PropertySettingsData::fromModel($property->settings, $settingsLock->locksCurrencyAndTimeZone($property)),
            'propertyName' => $property->name,
            'tab' => $tab,
            'canManageTerms' => request()->user()?->can(PermissionEnum::RegistrationCardManageTerms->value) ?? false,
            ...$tabProps,
        ]);
    }
}
