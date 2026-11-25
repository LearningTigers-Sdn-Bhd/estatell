<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\SaveOutletCfdQr;
use App\Actions\Pos\SavePosOutletPaymentMethod;
use App\Actions\Pos\SavePosOutletSettings;
use App\Actions\Pos\SavePosRegister;
use App\Enums\PosOperationMode;
use App\Enums\PosRoundingMode;
use App\Enums\PosRoundingPolicy;
use App\Enums\PosTenderType;
use App\Enums\TransactionKind;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pos\Concerns\RespondsToPos;
use App\Http\Requests\Pos\SavePosNativeOutletRequest;
use App\Http\Requests\Pos\SavePosOutletPaymentMethodRequest;
use App\Http\Requests\Pos\SavePosRegisterRequest;
use App\Http\Requests\Pos\UploadPosCfdQrRequest;
use App\Models\PaymentMethod;
use App\Models\PosOutlet;
use App\Models\PosOutletPaymentMethod;
use App\Models\PosRegister;
use App\Models\Property;
use App\Models\TransactionCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class PosOutletSettingsController extends Controller
{
    use RespondsToPos;

    public function index(Request $request, Property $property): Response
    {
        $outlets = PosOutlet::query()->where('property_id', $property->id)->where('pos_enabled', true)->orderBy('name')->get();
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();

        return Inertia::render('settings/pos/outlets', [
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['value' => (string) $row->id, 'label' => $row->name])->all(),
            'outlet' => $outlet === null ? null : [
                'id' => $outlet->id, 'name' => $outlet->name,
                'serviceChargeTransactionCodeId' => $outlet->service_charge_transaction_code_id, 'packagingTransactionCodeId' => $outlet->packaging_transaction_code_id,
                'roundingTransactionCodeId' => $outlet->rounding_transaction_code_id, 'cashVarianceTransactionCodeId' => $outlet->cash_variance_transaction_code_id, 'safeClearingTransactionCodeId' => $outlet->safe_clearing_transaction_code_id,
                'sstRegistrationNumber' => $outlet->sst_registration_number, 'operationMode' => $outlet->operation_mode->value, 'roundingMode' => $outlet->rounding_mode->value, 'roundingPolicy' => $outlet->rounding_policy->value, 'roomChargeLimit' => $outlet->room_charge_limit,
                'requireRoomChargeSignature' => $outlet->require_room_charge_signature, 'requireMealRedemptionConfirmation' => $outlet->require_meal_redemption_confirmation, 'requireReprintPin' => $outlet->require_reprint_pin,
                'requireDualControl' => $outlet->require_dual_control, 'printReceiptAfterPayment' => $outlet->print_receipt_after_payment, 'printDrawerSlip' => $outlet->print_drawer_slip, 'requireClockIn' => $outlet->require_clock_in,
                'allowAnonymousQr' => $outlet->allow_anonymous_qr, 'allowQrCustomerRemark' => $outlet->allow_qr_customer_remark,
                'cfdWelcomeMessage' => $outlet->cfd_welcome_message, 'cfdDuitnowQrUrl' => $outlet->cfd_duitnow_qr_path === null ? null : Storage::disk(config('filesystems.public_media'))->url($outlet->cfd_duitnow_qr_path),
            ],
            'registers' => $outlet === null ? [] : PosRegister::query()->where('pos_outlet_id', $outlet->id)->orderBy('name')->get(['id', 'name', 'active'])->all(),
            'outletPaymentMethods' => $outlet === null ? [] : PosOutletPaymentMethod::query()
                ->where('pos_outlet_id', $outlet->id)
                ->with('paymentMethod:id,name')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (PosOutletPaymentMethod $row): array => [
                    'id' => $row->id,
                    'paymentMethodId' => $row->payment_method_id,
                    'paymentMethodName' => $row->paymentMethod->name,
                    'tenderType' => $row->tender_type->value,
                    'tenderLabel' => $row->tender_type->label(),
                    'pulseDrawer' => $row->pulse_drawer,
                    'active' => $row->active,
                    'sortOrder' => $row->sort_order,
                ])->values()->all(),
            'paymentMethods' => PaymentMethod::query()->where('property_id', $property->id)->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (PaymentMethod $method): array => ['value' => (string) $method->id, 'label' => $method->name])->all(),
            'tenderTypes' => PosTenderType::outletOptions(),
            'transactionCodes' => TransactionCode::query()->where('property_id', $property->id)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->all(),
            'chargeCodes' => TransactionCode::query()->where('property_id', $property->id)->where('kind', TransactionKind::Charge->value)->orderBy('name')->get(['id', 'name'])->map(fn (TransactionCode $code): array => ['value' => (string) $code->id, 'label' => $code->name])->all(),
            'operationModes' => PosOperationMode::options(),
            'roundingModes' => PosRoundingMode::options(),
            'roundingPolicies' => PosRoundingPolicy::options(),
        ]);
    }

    public function update(SavePosNativeOutletRequest $request, Property $property, int $outlet, SavePosOutletSettings $savePosOutletSettings): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($savePosOutletSettings($request->user(), $property, $model, $request->validated())->refusal, __('Outlet settings saved.'));

        return back();
    }

    public function storeCfdQr(UploadPosCfdQrRequest $request, Property $property, int $outlet, SaveOutletCfdQr $save): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($save($request->user(), $property, $model, $request->file('qr'))->refusal, __('DuitNow QR uploaded.'));

        return back();
    }

    public function destroyCfdQr(Request $request, Property $property, int $outlet, SaveOutletCfdQr $save): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($save($request->user(), $property, $model, null)->refusal, __('DuitNow QR removed.'));

        return back();
    }

    public function storeRegister(SavePosRegisterRequest $request, Property $property, int $outlet, SavePosRegister $savePosRegister): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($savePosRegister($request->user(), $property, $model, (string) $request->validated('name'), $request->boolean('active'))->refusal, __('Register added.'));

        return back();
    }

    public function updateRegister(SavePosRegisterRequest $request, Property $property, int $outlet, int $register, SavePosRegister $savePosRegister): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $row = PosRegister::query()->where('pos_outlet_id', $model->id)->findOrFail($register);
        $this->toast($savePosRegister($request->user(), $property, $model, (string) $request->validated('name'), $request->boolean('active'), $row)->refusal, __('Register saved.'));

        return back();
    }

    public function storePaymentMethod(SavePosOutletPaymentMethodRequest $request, Property $property, int $outlet, SavePosOutletPaymentMethod $save): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $this->toast($save(
            $request->user(),
            $property,
            $model,
            (int) $request->validated('payment_method_id'),
            PosTenderType::from((string) $request->validated('tender_type')),
            $request->boolean('pulse_drawer'),
            $request->boolean('active'),
            (int) ($request->validated('sort_order') ?? 0),
        )->refusal, __('Payment method added.'));

        return back();
    }

    public function updatePaymentMethod(SavePosOutletPaymentMethodRequest $request, Property $property, int $outlet, int $method, SavePosOutletPaymentMethod $save): RedirectResponse
    {
        $model = PosOutlet::query()->where('property_id', $property->id)->findOrFail($outlet);
        $row = PosOutletPaymentMethod::query()->where('pos_outlet_id', $model->id)->findOrFail($method);
        $this->toast($save(
            $request->user(),
            $property,
            $model,
            (int) $request->validated('payment_method_id'),
            PosTenderType::from((string) $request->validated('tender_type')),
            $request->boolean('pulse_drawer'),
            $request->boolean('active'),
            (int) ($request->validated('sort_order') ?? 0),
            $row,
        )->refusal, __('Payment method saved.'));

        return back();
    }
}
