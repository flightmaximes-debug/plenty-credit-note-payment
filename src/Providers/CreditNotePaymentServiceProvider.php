<?php

namespace CreditNotePaymentAutomation\Providers;

use CreditNotePaymentAutomation\Procedures\BookCreditNoteRefundProcedure;
use Plenty\Modules\EventProcedures\Services\Entries\ProcedureEntry;
use Plenty\Modules\EventProcedures\Services\EventProceduresService;
use Plenty\Plugin\ServiceProvider;

class CreditNotePaymentServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot(EventProceduresService $eventProceduresService)
    {
        $eventProceduresService->registerProcedure(
            'bookCreditNoteRefund',
            ProcedureEntry::EVENT_TYPE_ORDER,
            [
                'de' => 'Gutschrift: offenen Betrag als Rückzahlung buchen',
                'en' => 'Credit note: book outstanding amount as refund',
            ],
            BookCreditNoteRefundProcedure::class . '@execute'
        );
    }
}
