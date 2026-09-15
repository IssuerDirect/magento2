<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Payment\Observer\AbstractDataAssignObserver;

class OrderObserver extends AbstractDataAssignObserver
{
    private $areaCodeHelper;
    private $sessionManager;

    public function __construct(
        \StripeIntegration\Payments\Helper\AreaCode $areaCodeHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager
    )
    {
        $this->areaCodeHelper = $areaCodeHelper;
        $this->sessionManager = $sessionManager;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        // When a guest customer order is placed in the admin area, clear the session saved variables so that new ones are created in the next session
        if ($this->areaCodeHelper->isAdmin())
            $this->sessionManager->setStripeCustomerId(null);
    }
}
