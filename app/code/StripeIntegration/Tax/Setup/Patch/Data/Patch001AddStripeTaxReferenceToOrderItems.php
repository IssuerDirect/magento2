<?php
declare(strict_types=1);

namespace StripeIntegration\Tax\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\Data\OrderItemInterfaceFactory;
use StripeIntegration\Tax\Helper\OrderItemFactory;

class Patch001AddStripeTaxReferenceToOrderItems implements DataPatchInterface
{
    private ModuleDataSetupInterface $moduleDataSetup;
    private ResourceConnection $resourceConnection;
    private OrderItemInterfaceFactory $orderItemFactory;
    private $orderItemHelperFactory;

    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        ResourceConnection $resourceConnection,
        OrderItemInterfaceFactory $orderItemFactory,
        OrderItemFactory $orderItemHelperFactory
    ) {
        $this->moduleDataSetup    = $moduleDataSetup;
        $this->resourceConnection = $resourceConnection;
        $this->orderItemFactory   = $orderItemFactory;
        $this->orderItemHelperFactory = $orderItemHelperFactory;
    }

    public function apply()
    {
        $this->moduleDataSetup->startSetup();

        $connection = $this->resourceConnection->getConnection();

        $itemTable  = $this->resourceConnection->getTableName('sales_order_item');
        $orderTable = $this->resourceConnection->getTableName('sales_order');
        $invoiceTable = $this->resourceConnection->getTableName('sales_invoice');
        $orderItemHelper = $this->orderItemHelperFactory->create();

        /**
         * SELECT the order items that were used for tax transactions.
         * We select the order items, join with the orders table for the order increment id and with the invoices table,
         * to determine if the Stripe transaction id appears.
         */
        $select = $connection->select()
            ->from(
                ['oi' => $itemTable],
                [
                    'item_id',
                    'order_id',
                    'product_id',
                    'parent_item_id',
                    'sku',
                    'product_options'
                ]
            )
            ->join(
                ['o' => $orderTable],
                'o.entity_id = oi.order_id',
                [
                    'increment_id'
                ]
            )
            ->join(
                ['i' => $invoiceTable],
                'i.order_id = oi.order_id',
                [
                    'stripe_tax_transaction_id'
                ]
            )
            ->where('i.stripe_tax_transaction_id IS NOT NULL');

        $rows = $connection->fetchAll($select);

        foreach ($rows as $row) {
            // Create lightweight model
            $item = $this->orderItemFactory->create();
            $item->setItemId((int)$row['item_id']);
            $item->setOrderId((int)$row['order_id']);
            $item->setProductId((int)$row['product_id']);
            $item->setParentItemId((int)$row['parent_item_id']);
            $item->setSku($row['sku']);
            $productOptions = json_decode($row['product_options'], true);
            $item->setProductOptions($productOptions);
            $item->setData('increment_id', $row['increment_id']);

            $reference = $this->getReference($item, $orderItemHelper);

            $connection->update(
                $itemTable,
                ['stripe_tax_reference' => $reference],
                ['item_id = ?' => (int)$row['item_id']]
            );
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }

    private function getReference(OrderItemInterface $item, $orderItemHelper)
    {
        $reference = sprintf('%s_%s', $item->getIncrementId(), $item->getSku());

        if ($item->getParentItemId()) {
            $parentItemProduct = $this->getParentItemProductId($item->getParentItemId());
            if ($parentItemProduct) {
                $reference .= '_' . $parentItemProduct['product_id'];
            }
        }

        if ($orderItemHelper->hasCustomizableOptions($item)) {
            $reference .= $orderItemHelper->getCustomizableOptionsSuffix($item);
        }

        return substr($reference, 0, 500);
    }

    private function getParentItemProductId($parentItemId)
    {
        $connection = $this->resourceConnection->getConnection();
        $itemTable  = $this->resourceConnection->getTableName('sales_order_item');

        // Get the product id of an order item based on the order item id
        $select = $connection->select()
            ->from(
                $itemTable,
                [
                    'item_id',
                    'product_id',
                ]
            )
            ->where('item_id = ?', $parentItemId);

        $products = $connection->fetchAll($select);

        if (count($products) == 1) {
            return array_pop($products);
        }

        return null;
    }
}
