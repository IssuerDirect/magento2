<?php

namespace StripeIntegration\Tax\Model;

class TaxFlow
{
    public $orderTaxCalculationSuccessful = false;
    public $orderMappingIssues = false;
    public $orderItemCalculationIssues = false;
    public $invoiceTaxCalculationSuccessful = false;
    public $invoiceTransactionSuccessful = false;
    public $reversalSavedToDatabase = false;
    public $reversalCreatedInStripe = false;
    public $reversalAttemptedInStripe = false;

    public $isNewOrderBeingPlaced = false;
    public $customerInvalidLocation = false;

    public function canOrderProceed()
    {
        return $this->orderTaxCalculationSuccessful &&
            !$this->orderMappingIssues &&
            !$this->orderItemCalculationIssues;
    }

    public function canInvoiceProceed()
    {
        return $this->invoiceTaxCalculationSuccessful && $this->invoiceTransactionSuccessful;
    }

    public function reversalFailedInStripe()
    {
        return $this->reversalAttemptedInStripe && !$this->reversalCreatedInStripe;
    }

    public function reversalSucceededInStripe()
    {
        return $this->reversalAttemptedInStripe && $this->reversalCreatedInStripe && !$this->reversalSavedToDatabase;
    }
}
