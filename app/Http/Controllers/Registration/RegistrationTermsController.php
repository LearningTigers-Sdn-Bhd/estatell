<?php

namespace App\Http\Controllers\Registration;

use App\Actions\Registration\ApplyTermsPreset;
use App\Actions\Registration\CreateTermsDocument;
use App\Actions\Registration\DeleteTermsDocument;
use App\Actions\Registration\PublishRegistrationTerms;
use App\Data\Property\PropertySettingsData;
use App\Data\Registration\TermsDocumentData;
use App\Data\Registration\TermsEditorData;
use App\Enums\Locale;
use App\Enums\PropertyType;
use App\Enums\TermsPreset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Registration\CreateTermsDocumentRequest;
use App\Http\Requests\Registration\PublishRegistrationTermsRequest;
use App\Models\Property;
use App\Models\TermsDocument;
use App\Services\Property\SettingsLock;
use App\Services\Registration\TermsRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationTermsController extends Controller
{
    /**
     * Registration card page, Terms tab. The open document and language are in the URL.
     * Changing either reloads only the editor data.
     */
    public function index(Request $request, Property $property, SettingsLock $settingsLock, TermsRenderer $renderer): Response|RedirectResponse
    {
        if ($property->settings === null) {
            return to_route('settings.edit', $property);
        }

        $documents = $property->termsDocuments()->withExists('roomTypes')->orderByDesc('is_default')->orderBy('name')->get();
        $document = $documents->firstWhere('id', $request->integer('document')) ?? $documents->firstWhere('is_default', true) ?? $documents->first();
        $locale = Locale::tryFrom((string) $request->query('locale')) ?? $property->settings->default_locale;

        return Inertia::render('settings/registration-card/edit', [
            'settings' => PropertySettingsData::fromModel($property->settings, $settingsLock->locksCurrencyAndTimeZone($property)),
            'propertyName' => $property->name,
            'tab' => 'terms',
            'canManageTerms' => true,
            'termsDocuments' => $documents->map(fn (TermsDocument $terms): TermsDocumentData => TermsDocumentData::fromModel($terms))->values(),
            'termsEditor' => fn (): ?TermsEditorData => $document === null ? null : $this->editor($property, $document, $locale, $renderer),
        ]);
    }

    public function storeDocument(CreateTermsDocumentRequest $request, Property $property, CreateTermsDocument $createTermsDocument): RedirectResponse
    {
        $document = $createTermsDocument($property, $request->user(), $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name created.', ['name' => $document->name])]);

        return $this->back($property, $document, Locale::English);
    }

    public function destroyDocument(Request $request, Property $property, TermsDocument $termsDocument, DeleteTermsDocument $deleteTermsDocument): RedirectResponse
    {
        if (! $deleteTermsDocument($termsDocument, $request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __(':name is the default or a room type uses it, so it cannot be deleted.', ['name' => $termsDocument->name])]);

            return $this->back($property, $termsDocument, Locale::English);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $termsDocument->name])]);

        return to_route('settings.registration-card.terms', $property);
    }

    public function storeVersion(PublishRegistrationTermsRequest $request, Property $property, TermsDocument $termsDocument, PublishRegistrationTerms $publishRegistrationTerms): RedirectResponse
    {
        $terms = $publishRegistrationTerms($termsDocument, $request->locale(), $request->user(), $request->validated('body'));

        Inertia::flash('toast', $terms === null
            ? ['type' => 'error', 'message' => __('The text is the same as the latest version, so nothing was published.')]
            : ['type' => 'success', 'message' => __('Version :version published.', ['version' => $terms->version])]);

        return $this->back($property, $termsDocument, $request->locale());
    }

    public function applyPreset(Request $request, Property $property, TermsDocument $termsDocument, ApplyTermsPreset $applyTermsPreset): RedirectResponse
    {
        $locale = Locale::from($request->validate(['locale' => ['required', Rule::enum(Locale::class)]])['locale']);
        $terms = $applyTermsPreset($termsDocument, $locale, $request->user());

        Inertia::flash('toast', $terms === null
            ? ['type' => 'error', 'message' => __('The latest version already has the template text.')]
            : ['type' => 'success', 'message' => __('Template applied as version :version.', ['version' => $terms->version])]);

        return $this->back($property, $termsDocument, $locale);
    }

    private function editor(Property $property, TermsDocument $document, Locale $locale, TermsRenderer $renderer): TermsEditorData
    {
        $versions = $document->versions()->with('publisher')->forLocale($locale)->orderByDesc('version')->get();
        $publishedLocales = $document->versions()->distinct()->pluck('locale')->map(fn ($value): Locale => $value instanceof Locale ? $value : Locale::from($value))->all();

        return TermsEditorData::forDocument(
            $document,
            $locale,
            $versions,
            $publishedLocales,
            TermsPreset::forPropertyType($property->type ?? PropertyType::Hotel),
            $renderer->valuesFor($property),
        );
    }

    private function back(Property $property, TermsDocument $document, Locale $locale): RedirectResponse
    {
        return to_route('settings.registration-card.terms', [$property, 'document' => $document->id, 'locale' => $locale->value]);
    }
}
