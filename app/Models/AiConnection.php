<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\Contracts\HasApiTokens as HasApiTokensContract;
use Laravel\Sanctum\HasApiTokens;

/**
 * One n8n workflow (or another tool) that sends guest messages to a property's AI assistant, and the owner of its Sanctum
 * token (`ai:chat`). Estatell's platform admin adds and revokes it. `RevokeAiConnection` deletes the token while the row
 * stays. See docs/35-ai-assistant/03-places-and-routes.md#chat-api-for-n8n.
 *
 * @property int $id
 * @property int $property_id
 * @property string $name
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'last_used_at', 'revoked_at'])]
class AiConnection extends Model implements HasApiTokensContract
{
    /** @use HasFactory<AiConnectionFactory> */
    use HasApiTokens, HasFactory;

    /** The one ability a chat API token has. Always pass it: `createToken` otherwise grants every ability. */
    public const string TOKEN_ABILITY = 'ai:chat';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
