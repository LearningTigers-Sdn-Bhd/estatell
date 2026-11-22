<?php

namespace App\Data\Shared;

use App\Enums\AccountingDocumentStatus;
use App\Enums\AccountingSetupStatus;
use App\Enums\AccountingSyncStatus;
use App\Enums\AiKeyStatus;
use App\Enums\AiOutcome;
use App\Enums\AiThreadStatus;
use App\Enums\ArInvoiceStatus;
use App\Enums\ArrivalImportOutcome;
use App\Enums\AuditStatus;
use App\Enums\BookingChangeKind;
use App\Enums\BookingEngineMode;
use App\Enums\ChannelAlertEvent;
use App\Enums\ChannelConnectionStatus;
use App\Enums\ChannelPropertyStatus;
use App\Enums\ChannelSupport;
use App\Enums\ChargeStatus;
use App\Enums\CommissionRateStatus;
use App\Enums\CompanyStatus;
use App\Enums\ContactEnquiryStatus;
use App\Enums\EventStatus;
use App\Enums\FacilityBookingStatus;
use App\Enums\FolioRowTag;
use App\Enums\FolioStatus;
use App\Enums\ForecastBasis;
use App\Enums\GatewayRefundStatus;
use App\Enums\MailStatus;
use App\Enums\OtaCredentialStatus;
use App\Enums\PartnerStatus;
use App\Enums\PaymentMode;
use App\Enums\PaymentReportStatus;
use App\Enums\PayoutVoucherStatus;
use App\Enums\PosShiftStatus;
use App\Enums\PropertyStatus;
use App\Enums\RatePlanStatus;
use App\Enums\RegistrationCardStatus;
use App\Enums\ReservationStatus;
use App\Enums\RevisionKind;
use App\Enums\RoomDayState;
use App\Enums\SignInResult;
use App\Enums\SignInSessionStatus;
use App\Enums\Tone;
use App\Enums\UnitStatus;
use App\Enums\WebhookDeliveryStatus;
use Spatie\LaravelData\Data;

/**
 * An enum value with its label and tone, for `StatusBadge`.
 */
class BadgeData extends Data
{
    public function __construct(
        public string $value,
        public string $label,
        public Tone $tone,
    ) {}

    public static function fromAiOutcome(AiOutcome $outcome): self
    {
        return new self($outcome->value, $outcome->label(), $outcome->tone());
    }

    public static function fromAiThreadStatus(AiThreadStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    /**
     * The hotel's OpenAI key: Working or Not working, else Not tested, or No key when none is saved.
     */
    public static function forAiKey(bool $hasKey, ?AiKeyStatus $status): self
    {
        return match (true) {
            ! $hasKey => new self('none', __('No key'), Tone::Neutral),
            $status === null => new self('untested', __('Not tested'), Tone::Neutral),
            default => new self($status->value, $status->label(), $status->tone()),
        };
    }

    public static function fromGatewayRefundStatus(GatewayRefundStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromArrivalImportOutcome(ArrivalImportOutcome $outcome): self
    {
        return new self($outcome->value, $outcome->label(), $outcome->tone());
    }

    public static function fromRegistrationCardStatus(RegistrationCardStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromWebhookDeliveryStatus(WebhookDeliveryStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromForecastBasis(ForecastBasis $basis): self
    {
        return new self($basis->value, $basis->label(), $basis->tone());
    }

    public static function fromBookingChangeKind(BookingChangeKind $kind): self
    {
        return new self($kind->value, $kind->label(), $kind->tone());
    }

    public static function fromAuditStatus(AuditStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromBookingEngineMode(BookingEngineMode $mode): self
    {
        return new self($mode->value, $mode->label(), $mode->tone());
    }

    public static function fromPaymentMode(PaymentMode $mode): self
    {
        return new self($mode->value, $mode->label(), $mode->tone());
    }

    public static function fromStatus(PropertyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromAccountingDocumentStatus(AccountingDocumentStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromAccountingSetupStatus(AccountingSetupStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromAccountingSyncStatus(AccountingSyncStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromArInvoiceStatus(ArInvoiceStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromChargeStatus(ChargeStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromCommissionRateStatus(CommissionRateStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromCompanyStatus(CompanyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromEventStatus(EventStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromFacilityBookingStatus(FacilityBookingStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromFolioStatus(FolioStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromFolioRowTag(FolioRowTag $tag): self
    {
        return new self($tag->value, $tag->label(), $tag->tone());
    }

    public static function fromChannelPropertyStatus(ChannelPropertyStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromChannelConnectionStatus(ChannelConnectionStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromRevisionKind(RevisionKind $kind): self
    {
        return new self($kind->value, $kind->label(), $kind->tone());
    }

    public static function fromChannelAlertEvent(ChannelAlertEvent $event): self
    {
        return new self($event->value, $event->label(), $event->tone());
    }

    public static function fromChannelSupport(ChannelSupport $support): self
    {
        return new self($support->value, $support->label(), $support->tone());
    }

    public static function fromOtaCredentialStatus(OtaCredentialStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromMailStatus(MailStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromContactEnquiryStatus(ContactEnquiryStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromSignInResult(SignInResult $result): self
    {
        return new self($result->value, $result->label(), $result->tone());
    }

    public static function fromSignInSessionStatus(SignInSessionStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromPartnerStatus(PartnerStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromPayoutVoucherStatus(PayoutVoucherStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromPaymentReportStatus(PaymentReportStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromRatePlanStatus(RatePlanStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromReservationStatus(ReservationStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromUnitStatus(UnitStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }

    public static function fromRoomDayState(RoomDayState $state): self
    {
        return new self($state->value, $state->label(), $state->tone());
    }

    public static function fromPosShiftStatus(PosShiftStatus $status): self
    {
        return new self($status->value, $status->label(), $status->tone());
    }
}
