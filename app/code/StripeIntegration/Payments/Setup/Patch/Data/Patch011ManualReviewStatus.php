<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;

class Patch011ManualReviewStatus implements DataPatchInterface
{
    private $moduleDataSetup;
    private $statusFactory;
    private $statusResource;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        StatusFactory $statusFactory,
        StatusResource $statusResource
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->statusFactory = $statusFactory;
        $this->statusResource = $statusResource;
    }

    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $status = $this->statusFactory->create();
        $status->setData('status', 'stripe_manual_review');
        $status->setData('label', 'Manual Review Required');

        $this->statusResource->save($status);
        $status->assignState('stripe_manual_review', false, true);

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    public static function getDependencies()
    {
        return [];
    }

    public function getAliases()
    {
        return [];
    }
}