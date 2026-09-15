<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Store\Model\ScopeInterface;

class AddExpressButton implements ObserverInterface
{
    private $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    )
    {
        $this->scopeConfig = $scopeConfig;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->scopeConfig->isSetFlag('payment/stripe_payments_express/global_enabled', ScopeInterface::SCOPE_STORE))
        {
            return;
        }

        /** @var \Magento\Catalog\Block\ShortcutButtons $shortcutButtons */
        $shortcutButtons = $observer->getEvent()->getContainer();

        /** @var \Magento\Framework\View\Element\Template $shortcut */
        $shortcut = $shortcutButtons->getLayout()->createBlock(
            \StripeIntegration\Payments\Block\Minicart\Shortcut::class,
            '',
            []
        );

        $shortcut->setIsInCatalogProduct(
            $observer->getEvent()->getIsCatalogProduct()
        )->setShowOrPosition(
            $observer->getEvent()->getOrPosition()
        );

        $shortcut->setIsShoppingCart($observer->getEvent()->getIsShoppingCart());
        $shortcut->setIsCart($observer->getEvent()->getIsShoppingCart());

        $shortcutButtons->addShortcut($shortcut);
    }
}
