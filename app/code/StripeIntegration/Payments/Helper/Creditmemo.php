<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Sales\Model\Order\CreditmemoFactory;

class Creditmemo
{
    private $creditmemoRepository;
    private $creditmemoManagement;
    private $creditmemoFactory;
    private $stockConfiguration;

    public function __construct(
        \Magento\Sales\Api\CreditmemoRepositoryInterface $creditmemoRepository,
        \Magento\Sales\Api\CreditmemoManagementInterface $creditmemoManagement,
        CreditmemoFactory $creditmemoFactory,
        \Magento\CatalogInventory\Api\StockConfigurationInterface $stockConfiguration
    ) {
        $this->creditmemoRepository = $creditmemoRepository;
        $this->creditmemoManagement = $creditmemoManagement;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->stockConfiguration = $stockConfiguration;
    }

    public function saveCreditmemo($creditmemo)
    {
        return $this->creditmemoRepository->save($creditmemo);
    }

    public function refundCreditmemo($creditmemo, $offline = false)
    {
        $this->creditmemoManagement->refund($creditmemo, $offline);
    }

    public function createOfflineCreditmemoForInvoice($invoice, $order)
    {
        // Prepare credit memo data
        $creditmemo = $this->creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);

        // Set back_to_stock based on admin configuration
        $this->setBackToStockForAllItems($creditmemo, $order->getStoreId());

        // Refund to the customer and save credit memo
        $this->refundCreditmemo($creditmemo, true);

        return $creditmemo;
    }

    public function createPendingCreditmemoForInvoice($invoice, $order)
    {
        $creditmemo = $this->creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);
        $this->setBackToStockForAllItems($creditmemo, $order->getStoreId());
        $creditmemo->setState(\Magento\Sales\Model\Order\Creditmemo::STATE_OPEN);
        $this->creditmemoRepository->save($creditmemo);

        return $creditmemo;
    }

    public function createOnlineCreditmemoForInvoice($invoice, $order)
    {
        // Prepare credit memo data
        $creditmemo = $this->creditmemoFactory->createByOrder($order);
        $creditmemo->setInvoice($invoice);

        // Set back_to_stock based on admin configuration
        $this->setBackToStockForAllItems($creditmemo, $order->getStoreId());

        // Refund to the customer and save credit memo
        $this->refundCreditmemo($creditmemo);

        return $creditmemo;
    }

    private function setBackToStockForAllItems($creditmemo, $storeId)
    {
        $autoReturnEnabled = $this->stockConfiguration->isAutoReturnEnabled($storeId);

        foreach ($creditmemo->getAllItems() as $creditmemoItem)
        {
            if ($creditmemoItem->getQty() > 0)
            {
                $creditmemoItem->setBackToStock($autoReturnEnabled);
            }
        }
    }
}
