<?php

namespace StripeIntegration\Payments\Block\Adminhtml;

class SelectPaymentMethod extends \Magento\Backend\Block\Widget\Form\Generic
{
    protected $_template = 'StripeIntegration_Payments::form/select_payment_method.phtml';
    private $paymentMethodHelper;
    private $paymentsConfig;
    private $customer;
    private $sessionQuote;
    private $helper;
    private $initParams;
    private $serializer;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\InitParams $initParams,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \Magento\Backend\Model\Session\Quote $sessionQuote,
        \Magento\Backend\Block\Template\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Data\FormFactory $formFactory,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentsConfig = $paymentsConfig;
        $this->sessionQuote = $sessionQuote;
        $this->customer = $helper->getCustomerModel();
        $this->helper = $helper;
        $this->initParams = $initParams;
        $this->serializer = $serializer;
    }

    public function isOrderEdit()
    {
        $order = $this->sessionQuote->getOrder();
        return $order && $order->getPayment() && $order->getPayment()->getAdditionalInformation('payment_action') == "order";
    }

    public function getSavedPaymentMethods()
    {
        try
        {
            if ($this->isOrderEdit())
            {
                $order = $this->sessionQuote->getOrder();
                $stripeCustomerId = $order->getPayment()->getAdditionalInformation('customer_stripe_id');
                $paymentMethodId = $order->getPayment()->getAdditionalInformation('token');
                $this->customer->fromStripeId($stripeCustomerId);
                $paymentMethod = $this->paymentsConfig->getStripeClient()->paymentMethods->retrieve($paymentMethodId, []);
                return $this->paymentMethodHelper->formatPaymentMethods([
                    $paymentMethod->type => [ $paymentMethod ]
                ]);
            }

            $methods = $this->customer->getSavedPaymentMethods(null, true, false);

            return $methods;
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e, $e->getTraceAsString());
            return [];
        }
    }

    public function getAdminInitParams()
    {
        $params = $this->initParams->getAdminPaymentElementParams();

        // Prepare the array so that it can be assigned to a data- attribute on the HTML element
        $jsonParams = $this->serializer->serialize($params);

        return $jsonParams;
    }

    public function getSavePaymentMethod()
    {
        $quote = $this->sessionQuote->getQuote();
        if ($quote && $quote->getPayment())
        {
            return $quote->getPayment()->getAdditionalInformation('save_payment_method');
        }
        return null;
    }
}
