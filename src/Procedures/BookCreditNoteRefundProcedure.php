<?php

namespace CreditNotePaymentAutomation\Procedures;

use Plenty\Modules\EventProcedures\Events\EventProceduresTriggered;
use Plenty\Modules\Order\Contracts\OrderAmountRepositoryContract;
use Plenty\Modules\Order\Models\Order;
use Plenty\Modules\Payment\Contracts\PaymentOrderRelationRepositoryContract;
use Plenty\Modules\Payment\Contracts\PaymentRepositoryContract;
use RuntimeException;

class BookCreditNoteRefundProcedure
{
    private const CREDIT_NOTE_TYPE_ID = 4;
    private const TARGET_STATUS_ID = 11.5;
    private const PAYMENT_STATUS_APPROVED = 2;
    private const TRANSACTION_TYPE_BOOKED_PAYMENT = 2;
    private const PAYMENT_ORIGIN_PLUGIN = 6;

    public function execute(
        EventProceduresTriggered $event,
        OrderAmountRepositoryContract $orderAmountRepository,
        PaymentRepositoryContract $paymentRepository,
        PaymentOrderRelationRepositoryContract $paymentOrderRelationRepository
    ): void {
        $order = $event->getOrder();

        if (!$order instanceof Order
            || (int) $order->typeId !== self::CREDIT_NOTE_TYPE_ID
            || abs((float) $order->statusId - self::TARGET_STATUS_ID) > 0.0001
        ) {
            return;
        }

        $orderAmount = $this->getOrderCurrencyAmount($orderAmountRepository, (int) $order->id);
        $invoiceTotal = (float) $orderAmount->invoiceTotal;
        $paidAmount = (float) $orderAmount->paidAmount;
        $giftCardAmount = (float) ($orderAmount->giftCardAmount ?? 0.0);
        $openAmount = $invoiceTotal - $giftCardAmount - $paidAmount;
        $precision = max(2, (int) ($order->numberOfDecimals ?? 2));

        if (round($openAmount, $precision) === 0.0) {
            return;
        }

        if ($openAmount > 0) {
            throw new RuntimeException(
                'Die Gutschrift ' . $order->id . ' hat keinen negativen offenen Betrag. '
                . 'Die Rückzahlung wurde aus Sicherheitsgründen nicht gebucht.'
            );
        }

        $methodOfPaymentId = (int) $order->methodOfPaymentId;
        if ($methodOfPaymentId <= 0) {
            throw new RuntimeException(
                'Für die Gutschrift ' . $order->id . ' ist keine gültige Zahlungsart hinterlegt.'
            );
        }

        $payment = $paymentRepository->createPayment([
            'amount' => abs(round($openAmount, $precision)),
            'currency' => (string) $orderAmount->currency,
            'type' => 'debit',
            'status' => self::PAYMENT_STATUS_APPROVED,
            'transactionType' => self::TRANSACTION_TYPE_BOOKED_PAYMENT,
            'mopId' => $methodOfPaymentId,
            'receivedAt' => date('Y-m-d H:i:s'),
            'properties' => [
                [
                    'typeId' => 3,
                    'value' => 'Automatische Rückzahlung für Gutschrift ' . $order->id,
                ],
                [
                    'typeId' => 23,
                    'value' => self::PAYMENT_ORIGIN_PLUGIN,
                ],
            ],
        ]);

        $paymentOrderRelationRepository->createOrderRelation($payment, $order);
    }

    private function getOrderCurrencyAmount(
        OrderAmountRepositoryContract $orderAmountRepository,
        int $orderId
    ) {
        $amounts = $orderAmountRepository->listByOrderId($orderId);
        $systemAmount = null;

        foreach ($amounts as $amount) {
            if (!(bool) $amount->isSystemCurrency) {
                return $amount;
            }
            $systemAmount = $amount;
        }

        if ($systemAmount === null) {
            throw new RuntimeException('Für die Gutschrift ' . $orderId . ' wurden keine Beträge gefunden.');
        }

        return $systemAmount;
    }
}
