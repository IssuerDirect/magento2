<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

class BankTransfers extends \Magento\Payment\Block\ConfigurableInfo
{
    private $paymentsConfig;
    private $paymentMethodHelper;
    private $stripePaymentMethodObject;
    private $stripePaymentMethodModelFactory;
    private $stripePaymentIntentObject;
    private $stripePaymentIntentModelFactory;
    private $country;
    private $tokenHelper;
    private $currencyHelper;
    private $areaCodeHelper;
    private $request;
    private $stripeInvoiceModelFactory;
    private $stripeInvoiceModel;
    private $invoicePaymentsHelper;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Token $tokenHelper,
        \StripeIntegration\Payments\Helper\Currency $currencyHelper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Helper\Stripe\InvoicePayments $invoicePaymentsHelper,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Model\Stripe\PaymentMethodFactory $stripePaymentMethodModelFactory,
        \StripeIntegration\Payments\Model\Stripe\PaymentIntentFactory $stripePaymentIntentModelFactory,
        \StripeIntegration\Payments\Model\Stripe\InvoiceFactory $stripeInvoiceModelFactory,
        \Magento\Directory\Model\Country $country,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->paymentsConfig = $paymentsConfig;
        $this->country = $country;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->stripePaymentMethodModelFactory = $stripePaymentMethodModelFactory;
        $this->stripePaymentIntentModelFactory = $stripePaymentIntentModelFactory;
        $this->tokenHelper = $tokenHelper;
        $this->currencyHelper = $currencyHelper;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->stripeInvoiceModelFactory = $stripeInvoiceModelFactory;
        $this->invoicePaymentsHelper = $invoicePaymentsHelper;
        $this->request = $context->getRequest();
    }

    public function getPaymentMethod()
    {
        if (!empty($this->stripePaymentMethodObject))
            return $this->stripePaymentMethodObject;

        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->payment_method))
        {
            return null;
        }
        else if (is_string($paymentIntent->payment_method))
        {
            $stripePaymentMethodModel = $this->stripePaymentMethodModelFactory->create()
                ->fromPaymentMethodId($paymentIntent->payment_method);

            return $this->stripePaymentMethodObject = $stripePaymentMethodModel->getStripeObject();
        }
        else
        {
            return $this->stripePaymentMethodObject = $paymentIntent->payment_method;
        }

        return null;
    }


    public function getPaymentIntent()
    {
        if (!empty($this->stripePaymentIntentObject))
            return $this->stripePaymentIntentObject;

        $transactionId = $this->getTransactionId();
        if ($this->tokenHelper->isPaymentIntentToken($transactionId))
        {
            $stripePaymentIntentModel = $this->stripePaymentIntentModelFactory->create()
                ->setExpandParams(['payment_method'])
                ->fromPaymentIntentId($transactionId);

            return $this->stripePaymentIntentObject = $stripePaymentIntentModel->getStripeObject();
        }

        return null;
    }

    public function getInvoice(): \StripeIntegration\Payments\Model\Stripe\Invoice
    {
        if (!empty($this->stripeInvoiceModel))
            return $this->stripeInvoiceModel;

        $invoiceId = $this->getInfo()->getAdditionalInformation("invoice_id");
        if ($this->tokenHelper->isInvoiceToken($invoiceId))
        {
            return $this->stripeInvoiceModel = $this->stripeInvoiceModelFactory->create()->fromInvoiceId($invoiceId);
        }

        // No invoice found, try the payment intent
        $paymentIntent = $this->getPaymentIntent();
        $invoice = $paymentIntent ? $this->invoicePaymentsHelper->getInvoiceFromPaymentIntentId($paymentIntent->id) : null;
        if ($invoice)
        {
            return $this->stripeInvoiceModel = $this->stripeInvoiceModelFactory->create()->fromObject($invoice);
        }

        return $this->stripeInvoiceModel = $this->stripeInvoiceModelFactory->create();
    }

    public function getPaymentMethodIconUrl($format = null)
    {
        return $this->paymentMethodHelper->getIcon([
            "type" => "customer_balance"
        ], $format);
    }


    public function getPaymentMethodName($hideLast4 = false)
    {
        return $this->paymentMethodHelper->getPaymentMethodName("customer_balance");
    }

    public function getFormattedAmountRemaining()
    {
        $stripeInvoiceModel = $this->getInvoice();
        if ($stripeInvoiceModel->getId())
        {
            $amountRemaining = $stripeInvoiceModel->getStripeObject()->amount_remaining;
            $currency = $stripeInvoiceModel->getStripeObject()->currency;

            return $this->currencyHelper->formatStripePrice($amountRemaining, $currency);
        }
        else if ($paymentIntent = $this->getPaymentIntent())
        {
            // For orders placed from the frontend
            $amountRemaining = 0;
            $currency = $paymentIntent->currency;

            if (!empty($paymentIntent->next_action->display_bank_transfer_instructions->amount_remaining))
            {
                /** @var \Stripe\StripeObject $instructions */
                $instructions = $paymentIntent->next_action->display_bank_transfer_instructions;
                $amountRemaining = $instructions->amount_remaining;
                $currency = $instructions->currency;
            }

            return $this->currencyHelper->formatStripePrice($amountRemaining, $currency);
        }

        return null;
    }

    public function isPaid()
    {
        $stripeInvoiceModel = $this->getInvoice();
        if ($stripeInvoiceModel->getId())
        {
            return $stripeInvoiceModel->getStripeObject()->status == "paid";
        }
        else if ($paymentIntent = $this->getPaymentIntent())
        {
            return $paymentIntent->status == "succeeded";
        }

        return false;
    }

    public function getFormattedAmountRefunded()
    {
        $amountRefunded = 0;
        $currency = null;
        $stripeInvoiceModel = $this->getInvoice();
        if ($stripeInvoiceModel->getId())
        {
            $amountRefunded = $stripeInvoiceModel->getStripeObject()->amount_refunded;
            $currency = $stripeInvoiceModel->getStripeObject()->currency;
        }
        else if ($paymentIntent = $this->getPaymentIntent())
        {
            $amountRefunded = 0;
            $currency = $paymentIntent->currency;
            $charges = $this->paymentsConfig->getStripeClient()->charges->all(['payment_intent' => $paymentIntent->id]);
            if (empty($charges->data))
            {
                return null;
            }

            foreach ($charges->data as $charge)
            {
                if ($charge->refunded)
                {
                    $amountRefunded += $charge->amount_refunded;
                    $currency = $charge->currency;
                }
            }
        }

        if ($amountRefunded != 0)
        {
            return $this->currencyHelper->formatStripePrice($amountRefunded, $currency);
        }

        return null;
    }

    public function getTransactionId()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        return $this->tokenHelper->cleanToken($transactionId);
    }

    public function getIbanDetails()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban))
            return null;

        $details = $paymentIntent->next_action->display_bank_transfer_instructions->financial_addresses[0]->iban;

        $countryName = null;
        if ($details->country)
        {
            $country = $this->country->loadByCode($details->country);
            $countryName = $country->getName();
        }

        return [
            'account_holder_name' => $details->account_holder_name ?? null,
            'bic' => $details->bic ?? null,
            'country' => $countryName,
            'iban' => $details->iban ?? null,
        ];
    }

    public function getReference()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->reference))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->reference;
    }

    public function getHostedInstructionsUrl()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url))
            return null;

        return $paymentIntent->next_action->display_bank_transfer_instructions->hosted_instructions_url;
    }

    public function getCustomerId()
    {
        $stripeInvoiceModel = $this->getInvoice();
        if ($stripeInvoiceModel->getId())
        {
            if (isset($stripeInvoiceModel->getStripeObject()->customer) && !empty($stripeInvoiceModel->getStripeObject()->customer))
                return $stripeInvoiceModel->getStripeObject()->customer;
        }
        if ($paymentIntent = $this->getPaymentIntent())
        {
            if (isset($paymentIntent->customer) && !empty($paymentIntent->customer))
                return $paymentIntent->customer;
        }

        return null;
    }

    public function getPaymentId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->id))
            return $paymentIntent->id;

        return null;
    }

    public function getMode()
    {
        $stripeInvoiceModel = $this->getInvoice();
        if ($stripeInvoiceModel->getId())
        {
            if ($stripeInvoiceModel->getStripeObject()->livemode)
                return "";
        }
        else if ($paymentIntent = $this->getPaymentIntent())
        {
            if ($paymentIntent->livemode)
                return "";
        }

        return "test/";
    }

    public function getTemplate()
    {
        if (!$this->paymentsConfig->getStripeClient())
            return null;

        if (!$this->isAllowedAction())
            return 'StripeIntegration_Payments::paymentInfo/generic.phtml';

        return 'StripeIntegration_Payments::paymentInfo/bank_transfers.phtml';
    }

    public function isAllowedAction()
    {
        if (!$this->areaCodeHelper->isAdmin())
            return true;

        $allowedAdminActions = ["view", "new", "email"];
        $action = $this->request->getActionName();
        if (in_array($action, $allowedAdminActions))
            return true;

        return false;
    }

    public function getTitle()
    {
        return $this->getMethod()->getTitle();
    }

    public function getInvoiceURL()
    {
        $stripeInvoiceModel = $this->getInvoice();

        return $stripeInvoiceModel->getStripeObject()->hosted_invoice_url ?? null;
    }

    public function getInvoicePDF()
    {
        $stripeInvoiceModel = $this->getInvoice();

        return $stripeInvoiceModel->getStripeObject()->invoice_pdf ?? null;
    }

    public function getStripeInvoiceURL()
    {
        $stripeInvoiceModel = $this->getInvoice();

        if (empty($stripeInvoiceModel->getId()))
            return null;

        return "https://dashboard.stripe.com/{$this->getMode()}invoices/" . $stripeInvoiceModel->getId();
    }

    public function getDateDue()
    {
        $stripeInvoiceModel = $this->getInvoice();

        if (empty($stripeInvoiceModel->getStripeObject()->due_date))
            return null;

        $date = $stripeInvoiceModel->getStripeObject()->due_date;

        return date('j M Y', $date);
    }

    public function getStatus()
    {
        $stripeInvoiceModel = $this->getInvoice();

        if (empty($stripeInvoiceModel->getStripeObject()->status))
            return null;

        return ucfirst($stripeInvoiceModel->getStripeObject()->status);
    }
}
