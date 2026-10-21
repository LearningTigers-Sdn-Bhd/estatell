<?php

namespace App\Actions\Platform;

use App\Enums\PartnerStatus;
use App\Models\Partner;
use App\Models\User;
use App\Services\Platform\PartnerSignupMessage;
use Illuminate\Support\Facades\DB;

class UpdatePartnerSignupMessage
{
    public function __construct(private PartnerSignupMessage $message) {}

    /**
     * Save an Active partner's "Copy message" template, and log the change. A template equal to the default is
     * stored as null, so the partner follows the default wording. Returns false when the partner is not Active.
     */
    public function __invoke(User $user, Partner $partner, string $template): bool
    {
        return DB::transaction(function () use ($user, $partner, $template): bool {
            $partner = Partner::query()->lockForUpdate()->findOrFail($partner->id);

            if ($partner->status !== PartnerStatus::Active) {
                return false;
            }

            $template = str_replace("\r\n", "\n", trim($template));
            $saved = $template === $this->message->defaultTemplate() ? null : $template;

            if ($partner->signup_message_template === $saved) {
                return true;
            }

            $partner->update(['signup_message_template' => $saved]);

            activity()
                ->performedOn($partner)
                ->causedBy($user)
                ->event('partner.signup-message-updated')
                ->log('Partner signup message updated');

            return true;
        });
    }
}
