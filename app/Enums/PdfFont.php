<?php

namespace App\Enums;

/**
 * The font style of a property's PDFs: a heading font and a body font for Latin text. Chinese text falls back to
 * Noto Sans SC, then Noto Sans TC, in every style. The TTF files are in resources/fonts; the browser preview loads the
 * same files through Vite. See docs/02-property/05-printing-settings.md#fonts.
 */
enum PdfFont: string
{
    case Office = 'office';
    case Modern = 'modern';
    case Fancy = 'fancy';
    case Handwritten = 'handwritten';

    /** The fonts every style falls back to, in order, for characters its own fonts lack. */
    public const array FALLBACK_FAMILIES = ['Noto Sans SC', 'Noto Sans TC', 'DejaVu Sans'];

    public function label(): string
    {
        return match ($this) {
            self::Office => __('Office'),
            self::Modern => __('Modern'),
            self::Fancy => __('Fancy'),
            self::Handwritten => __('Handwritten'),
        };
    }

    /**
     * The font of the property name, the document title and the section headings.
     */
    public function headingFamily(): string
    {
        return match ($this) {
            self::Office => 'Arimo',
            self::Modern => 'Inter',
            self::Fancy => 'Playfair Display',
            self::Handwritten => 'Caveat',
        };
    }

    /**
     * The font of the body text and the tables.
     */
    public function bodyFamily(): string
    {
        return match ($this) {
            self::Office => 'Arimo',
            self::Modern => 'Inter',
            self::Fancy => 'Lora',
            self::Handwritten => 'Nunito',
        };
    }

    /**
     * The font files dompdf needs for this style and the fallbacks. A family with no bold file prints bold text with
     * its regular file.
     *
     * @return list<array{family: string, weight: 'normal'|'bold', file: string}>
     */
    public function fontFaces(): array
    {
        $files = [
            'Arimo' => ['normal' => 'Arimo-Regular.ttf', 'bold' => 'Arimo-Bold.ttf'],
            'Inter' => ['normal' => 'Inter-Regular.ttf', 'bold' => 'Inter-Bold.ttf'],
            'Lora' => ['normal' => 'Lora-Regular.ttf', 'bold' => 'Lora-Bold.ttf'],
            'Nunito' => ['normal' => 'Nunito-Regular.ttf', 'bold' => 'Nunito-Bold.ttf'],
            'Playfair Display' => ['normal' => 'PlayfairDisplay-Bold.ttf', 'bold' => 'PlayfairDisplay-Bold.ttf'],
            'Caveat' => ['normal' => 'Caveat-Bold.ttf', 'bold' => 'Caveat-Bold.ttf'],
            'Noto Sans SC' => ['normal' => 'NotoSansSC-Regular.ttf', 'bold' => 'NotoSansSC-Regular.ttf'],
            'Noto Sans TC' => ['normal' => 'NotoSansTC-Regular.ttf', 'bold' => 'NotoSansTC-Regular.ttf'],
        ];
        $families = array_unique([$this->headingFamily(), $this->bodyFamily(), 'Noto Sans SC', 'Noto Sans TC']);
        $faces = [];

        foreach ($families as $family) {
            foreach ($files[$family] as $weight => $file) {
                $faces[] = ['family' => $family, 'weight' => $weight, 'file' => resource_path('fonts/'.$file)];
            }
        }

        return $faces;
    }

    /**
     * A CSS font-family list: the family, then the fallbacks.
     */
    public static function stack(string $family): string
    {
        return implode(', ', array_map(fn (string $name): string => "'{$name}'", [$family, ...self::FALLBACK_FAMILIES])).', sans-serif';
    }

    /**
     * @return list<array{value: string, label: string, headingFamily: string, bodyFamily: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'headingFamily' => $case->headingFamily(),
            'bodyFamily' => $case->bodyFamily(),
        ], self::cases());
    }
}
