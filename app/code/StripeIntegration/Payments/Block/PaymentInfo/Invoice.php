<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

use Magento\Payment\Block\ConfigurableInfo;

class Invoice extends ConfigurableInfo
{
    private $invoice = null;
    private $invoiceFactory;
    private $helper;
    private $paymentsConfig;
    private $areaCodeHelper;
    private $request;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \StripeIntegration\Payments\Model\Stripe\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->helper = $helper;
        $this->areaCodeHelper = $areaCodeHelper;
        $this->request = $context->getRequest();
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentsConfig = $paymentsConfig;
    }

    public function getInvoice()
    {
        if ($this->invoice)
            return $this->invoice;

        $info = $this->getInfo();
        $invoiceId = $info->getAdditionalInformation('invoice_id');
        $invoice = $this->invoiceFactory->create()->load($invoiceId);
        return $this->invoice = $invoice;
    }

    public function getCustomerUrl()
    {
        $stripeInvoiceModel = $this->getInvoice();
        return $this->helper->getStripeUrl($stripeInvoiceModel->getStripeObject()->livemode, 'customers', $stripeInvoiceModel->getStripeObject()->customer);
    }

    public function getTemplate()
    {
        if (!$this->paymentsConfig->getStripeClient())
            return null;

        if (!$this->isAllowedAction())
            return 'StripeIntegration_Payments::paymentInfo/generic.phtml';

        return 'StripeIntegration_Payments::paymentInfo/invoice.phtml';
    }

    public function isAllowedAction()
    {
        if (!$this->areaCodeHelper->isAdmin())
            return true;

        $allowedAdminActions = ["view", "new", "email", "save"];
        $action = $this->request->getActionName();
        if (in_array($action, $allowedAdminActions))
            return true;

        return false;
    }

    public function getTitle()
    {
        if (!$this->isAllowedAction())
            return __('Online invoice payment');

        return $this->getMethod()->getTitle();
    }

    public function getDateDue()
    {
        $invoice = $this->getInvoice()->getStripeObject();

        $date = $invoice->due_date;

        return date('j M Y', $date);
    }

    public function getStatus()
    {
        $invoice = $this->getInvoice()->getStripeObject();

        return ucfirst($invoice->status);
    }

    public function getInvoiceURL()
    {
        $stripeInvoiceModel = $this->getInvoice();
        $stripeInvoiceObject = $stripeInvoiceModel->getStripeObject();

        if (empty($stripeInvoiceObject->hosted_invoice_url))
            return null;

        return $stripeInvoiceObject->hosted_invoice_url;
    }

    public function isPaid()
    {
        $stripeInvoiceModel = $this->getInvoice();
        $stripeInvoiceObject = $stripeInvoiceModel->getStripeObject();

        return $stripeInvoiceObject->status == 'paid';
    }

    public function getInvoicePDF()
    {
        $stripeInvoiceModel = $this->getInvoice();
        $stripeInvoiceObject = $stripeInvoiceModel->getStripeObject();

        if (empty($stripeInvoiceObject->invoice_pdf))
            return null;

        return $stripeInvoiceObject->invoice_pdf;
    }
}
