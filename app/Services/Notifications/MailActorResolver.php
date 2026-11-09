<?php

namespace App\Services\Notifications;

use App\Enums\MailActorType;
use App\Models\Property;
use App\Models\User;
use App\Services\Platform\Impersonation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Works out who caused the email being sent. A queued mail has no signed-in user, so the actor travels with the job in
 * hidden Context. See docs/04-notifications/03-mail-log.md.
 *
 * @phpstan-type MailActor array{type: string, user_id: int|null, property_id: int|null}
 */
class MailActorResolver
{
    public const string CONTEXT_KEY = 'mail_actor';

    /** Set around a send whose recipient is not staff, so the log still knows the property. */
    public const string PROPERTY_CONTEXT_KEY = 'mail_property_id';

    public function __construct(private Impersonation $impersonation) {}

    /**
     * The actor a queued job carries, otherwise the actor of the current request.
     *
     * @return MailActor
     */
    public function current(): array
    {
        $actor = $this->actor();
        $propertyId = Context::getHidden(self::PROPERTY_CONTEXT_KEY);

        return is_int($propertyId) ? ['type' => $actor['type'], 'user_id' => $actor['user_id'], 'property_id' => $propertyId] : $actor;
    }

    /**
     * @return MailActor
     */
    private function actor(): array
    {
        $actor = Context::getHidden(self::CONTEXT_KEY);

        if (! is_array($actor) || ! is_string($actor['type'] ?? null)) {
            return $this->fromRequest();
        }

        return [
            'type' => $actor['type'],
            'user_id' => is_int($actor['user_id'] ?? null) ? $actor['user_id'] : null,
            'property_id' => is_int($actor['property_id'] ?? null) ? $actor['property_id'] : null,
        ];
    }

    /**
     * The actor of the current request. A support user counts as themselves, not as the staff user they are viewing as.
     *
     * @return MailActor
     */
    public function fromRequest(): array
    {
        $request = request();
        $user = ($request->hasSession() ? $this->impersonation->supportUser() : null) ?? Auth::user();
        $property = $request->route('property');
        $propertyId = $property instanceof Property ? $property->id : null;

        if ($user instanceof User) {
            return ['type' => $this->typeOf($user)->value, 'user_id' => $user->id, 'property_id' => $propertyId ?? $user->property_id];
        }

        if (str_starts_with((string) $request->route()?->getName(), 'api.booking.v1.')) {
            $code = $request->route('code');

            return [
                'type' => MailActorType::Guest->value,
                'user_id' => null,
                'property_id' => is_string($code) ? Property::query()->where('code', $code)->value('id') : null,
            ];
        }

        return ['type' => MailActorType::System->value, 'user_id' => null, 'property_id' => null];
    }

    private function typeOf(User $user): MailActorType
    {
        return match (true) {
            $user->is_admin => MailActorType::Admin,
            $user->partner_id !== null => MailActorType::Partner,
            $user->is_company_user => MailActorType::Company,
            default => MailActorType::Property,
        };
    }
}
