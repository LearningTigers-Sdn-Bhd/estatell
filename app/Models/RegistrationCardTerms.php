<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\TermsPreset;
use Database\Factories\RegistrationCardTermsFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One published version of a terms document in one language. It is never edited after publishing.
 *
 * @property int $id
 * @property int $terms_document_id
 * @property Locale $locale
 * @property int $version
 * @property string $body
 * @property TermsPreset|null $preset
 * @property int|null $preset_version
 * @property Carbon $published_at
 * @property int|null $published_by
 */
#[Fillable(['terms_document_id', 'locale', 'version', 'body', 'preset', 'preset_version', 'published_at', 'published_by'])]
class RegistrationCardTerms extends Model
{
    /** @use HasFactory<RegistrationCardTermsFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'registration_card_terms';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'version' => 'integer',
            'preset' => TermsPreset::class,
            'preset_version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TermsDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(TermsDocument::class, 'terms_document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @param Builder<RegistrationCardTerms> $query */
    public function scopeForLocale(Builder $query, Locale $locale): void
    {
        $query->where('locale', $locale->value);
    }
}
