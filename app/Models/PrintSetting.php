<?php

namespace App\Models;

use App\Enums\PdfFont;
use App\Enums\PdfFontSize;
use Database\Factories\PrintSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * How the property's PDFs look. It stores switches, never values: the header reads the values from the property,
 * its logo and its taxes. See docs/02-property/05-printing-settings.md.
 *
 * @property int $id
 * @property int $property_id
 * @property PdfFont $font
 * @property PdfFontSize $font_size
 * @property bool $show_logo
 * @property bool $show_address
 * @property bool $show_contact
 * @property bool $show_registration_number
 * @property bool $show_tin
 * @property string|null $footer_text
 */
#[Fillable([
    'font', 'font_size', 'show_logo', 'show_address', 'show_contact', 'show_registration_number', 'show_tin', 'footer_text',
])]
class PrintSetting extends Model
{
    /** @use HasFactory<PrintSettingFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'font' => PdfFont::class,
            'font_size' => PdfFontSize::class,
            'show_logo' => 'boolean',
            'show_address' => 'boolean',
            'show_contact' => 'boolean',
            'show_registration_number' => 'boolean',
            'show_tin' => 'boolean',
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The taxes whose registration numbers the header shows.
     *
     * @return BelongsToMany<Tax, $this>
     */
    public function taxes(): BelongsToMany
    {
        return $this->belongsToMany(Tax::class, 'print_setting_taxes');
    }
}
