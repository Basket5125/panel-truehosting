<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\PaymentEvent;
use App\Models\CustomOrder;
use App\Services\ServerRenewalService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies a payment that was made for a configured server rather than for
 * credits: either the first period of a new one, or a renewal.
 */
class ApplyCustomOrderPayment
{
    public function __construct(private ServerRenewalService $renewals)
    {
    }

    public function handle(PaymentEvent $event): void
    {
        if ($event->payment->status != PaymentStatus::PAID) {
            return;
        }

        $order = CustomOrder::where('shop_product_id', $event->shopProduct->id)
            ->where('status', CustomOrder::STATUS_PENDING)
            ->first();

        if (!$order) {
            return;
        }

        $order->update(['payment_id' => $event->payment->id]);

        try {
            if ($order->kind === CustomOrder::KIND_RENEWAL) {
                $this->renewals->applyPaidRenewal($order);

                return;
            }

            // First period of a brand new server: provision it now that the
            // money has arrived.
            app(\App\Services\CustomOrderProvisioner::class)->provision($order);
        } catch (Throwable $e) {
            $order->update([
                'status' => CustomOrder::STATUS_FAILED,
                'failure_reason' => mb_substr($e->getMessage(), 0, 190),
            ]);

            Log::error('Custom order payment could not be applied', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
