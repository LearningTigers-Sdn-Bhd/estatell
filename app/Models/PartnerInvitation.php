<?php

namespace App\Models;

use Database\Factories\PartnerInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation for one email to bring properties as a new partner. Only the SHA-256 hash of the token is stored.
 *
 * @property int $id
 * @property int $partner_id
 * @property string $email
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon $last_sent_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property int|null $invited_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['partner_id', 'email', 'token_hash', 'expires_at', 'last_sent_at', 'accepted_at', 'revoked_at', 'invited_by'])]
class PartnerInvitation extends Model
{
    /** @use HasFactory<PartnerInvitationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Invitations that are neither accepted nor revoked. Expired ones are still open, so they can be resent.
     *
     * @param  Builder<PartnerInvitation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }
}
