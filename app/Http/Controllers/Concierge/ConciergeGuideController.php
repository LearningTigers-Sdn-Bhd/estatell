<?php

namespace App\Http\Controllers\Concierge;

use App\Data\Property\PropertySummaryData;
use App\Enums\UnitStatus;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\ReservationUnit;
use App\Services\Concierge\ConciergeContent;
use App\Services\Concierge\ConciergeGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The part of the Concierge page that tells a guest about the property: how to reach it, its guide (about, facilities,
 * policies, FAQs), its Wi-Fi and the places nearby. A visitor opens the public pages from the lobby hub and needs no stay.
 * A guest with a stay link opens the same pages from the stay, and only a guest who is checked in also sees the Wi-Fi.
 */
class ConciergeGuideController extends Controller
{
    private const array PUBLIC_SECTIONS = ['property', 'amenities', 'policies', 'faqs'];

    public function findForm(Property $property): Response
    {
        return Inertia::render('concierge/find', ['property' => PropertySummaryData::fromModel($property)]);
    }

    public function contact(Property $property, ConciergeGuide $guide): Response
    {
        return Inertia::render('concierge/contact', [
            'property' => PropertySummaryData::fromModel($property),
            'details' => $guide->property($property),
            'back' => ['href' => route('concierge.lobby', ['property' => $property->code], absolute: false), 'label' => __('Home')],
        ]);
    }

    public function nearbyPlaces(Property $property, ConciergeContent $content): Response
    {
        return Inertia::render('concierge/nearby', [
            'property' => PropertySummaryData::fromModel($property),
            'places' => $content->places($property)->values(),
            'back' => ['href' => route('concierge.lobby', ['property' => $property->code], absolute: false), 'label' => __('Home')],
        ]);
    }

    public function info(Property $property, ConciergeGuide $guide, ConciergeContent $content, string $section = 'property'): Response
    {
        $sections = array_map(
            fn (string $key): array => ['key' => $key, 'label' => $this->label($key), 'url' => route('concierge.info', ['property' => $property->code, 'section' => $key], absolute: false)],
            self::PUBLIC_SECTIONS,
        );

        return $this->guidePage($property, $section, $sections, ['href' => route('concierge.lobby', ['property' => $property->code], absolute: false), 'label' => __('Home')], false, $guide, $content);
    }

    public function stayInfo(Request $request, string $token, ConciergeGuide $guide, ConciergeContent $content, string $section = 'property'): Response|RedirectResponse
    {
        $stay = $this->stay($request);
        $inHouse = $stay->status === UnitStatus::CheckedIn;

        if ($section === 'wifi' && ! $inHouse) {
            return to_route('concierge.stay-info', ['token' => $token, 'section' => 'property']);
        }

        $sections = array_map(
            fn (string $key): array => ['key' => $key, 'label' => $this->label($key), 'url' => route('concierge.stay-info', ['token' => $token, 'section' => $key], absolute: false)],
            $inHouse ? ['wifi', ...self::PUBLIC_SECTIONS] : self::PUBLIC_SECTIONS,
        );

        return $this->guidePage($stay->property()->firstOrFail(), $section, $sections, ['href' => route('concierge.show', ['token' => $token], absolute: false), 'label' => __('Your stay')], $inHouse, $guide, $content);
    }

    public function stayContact(Request $request, string $token, ConciergeGuide $guide): Response
    {
        $property = $this->stay($request)->property()->firstOrFail();

        return Inertia::render('concierge/contact', [
            'property' => PropertySummaryData::fromModel($property),
            'details' => $guide->property($property),
            'back' => ['href' => route('concierge.show', ['token' => $token], absolute: false), 'label' => __('Your stay')],
        ]);
    }

    /**
     * @param  list<array{key: string, label: string, url: string}>  $sections
     * @param  array{href: string, label: string}  $back
     */
    private function guidePage(Property $property, string $section, array $sections, array $back, bool $withWifi, ConciergeGuide $guide, ConciergeContent $content): Response
    {
        return Inertia::render('concierge/info', [
            'property' => PropertySummaryData::fromModel($property),
            'section' => $section,
            'sections' => $sections,
            'back' => $back,
            'details' => $section === 'property' ? $guide->property($property) : null,
            'policies' => $section === 'policies' ? $guide->policies($property) : null,
            'amenities' => $section === 'amenities' ? $content->amenities($property)->values() : [],
            'faqs' => $section === 'faqs' ? $guide->faqs($property) : [],
            'wifi' => $section === 'wifi' && $withWifi ? $guide->wifi($property) : [],
        ]);
    }

    private function stay(Request $request): ReservationUnit
    {
        /** @var ReservationUnit */
        return $request->attributes->get('concierge.stay');
    }

    private function label(string $key): string
    {
        return match ($key) {
            'wifi' => __('Wi-Fi'),
            'property' => __('The property'),
            'amenities' => __('Facilities'),
            'policies' => __('Policies'),
            default => __('FAQs'),
        };
    }
}
