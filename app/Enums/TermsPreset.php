<?php

namespace App\Enums;

use InvalidArgumentException;

/**
 * A ready-made set of registration terms shipped with the app, in `resources/terms/{preset}/{locale}.md`.
 * The first lines of each file hold `version: N` between `---` marks.
 */
enum TermsPreset: string
{
    case StandardHotel = 'standard_hotel';
    case Hostel = 'hostel';
    case Resort = 'resort';
    case ServicedApartment = 'serviced_apartment';
    case Homestay = 'homestay';

    public function label(): string
    {
        return match ($this) {
            self::StandardHotel => __('Standard hotel'),
            self::Hostel => __('Hostel'),
            self::Resort => __('Resort'),
            self::ServicedApartment => __('Serviced apartment'),
            self::Homestay => __('Homestay'),
        };
    }

    public static function forPropertyType(PropertyType $type): self
    {
        return match ($type) {
            PropertyType::Hotel => self::StandardHotel,
            PropertyType::Hostel => self::Hostel,
            PropertyType::Resort => self::Resort,
            PropertyType::ServicedApartment => self::ServicedApartment,
            PropertyType::Homestay => self::Homestay,
        };
    }

    public function path(Locale $locale): string
    {
        return resource_path("terms/{$this->value}/{$locale->value}.md");
    }

    /** The template version written in the file. */
    public function version(Locale $locale): int
    {
        return $this->read($locale)['version'];
    }

    /** The template text without the version lines. */
    public function body(Locale $locale): string
    {
        return $this->read($locale)['body'];
    }

    /**
     * @return array{version: int, body: string}
     */
    private function read(Locale $locale): array
    {
        $contents = file_get_contents($this->path($locale));

        if ($contents === false || ! preg_match('/\A---\nversion: (\d+)\n---\n(.*)\z/s', $contents, $matches)) {
            throw new InvalidArgumentException("The terms template [{$this->value}/{$locale->value}] has no version line.");
        }

        return ['version' => (int) $matches[1], 'body' => trim($matches[2])];
    }
}
