<?php

namespace StripeIntegration\Payments\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Serialize\SerializerInterface;

class Patch009PaymentMethodCardDataMigration implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * Constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param SerializerInterface $serializer
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup,
        SerializerInterface $serializer
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
        $this->serializer = $serializer;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();

        $select = $connection->select()->from(
            $this->moduleDataSetup->getTable('stripe_payment_methods'),
            ['entity_id', 'payment_method_card_data']
        )->where('payment_method_card_data IS NOT NULL');

        $paymentMethods = $connection->fetchAll($select);

        foreach ($paymentMethods as $paymentMethod) {
            if (empty($paymentMethod['payment_method_card_data'])) {
                continue;
            }

            try {
                $cardData = $this->serializer->unserialize($paymentMethod['payment_method_card_data']);

                // Check if data is already in the new format (has 'brand' key)
                if (isset($cardData['brand'])) {
                    continue;
                }

                // Migrate from old format to new format
                $newCardData = [];

                if (isset($cardData['card_type'])) {
                    $newCardData['brand'] = $cardData['card_type'];
                }

                if (isset($cardData['card_data'])) {
                    $newCardData['last4'] = $cardData['card_data'];
                }

                if (isset($cardData['wallet'])) {
                    $newCardData['wallet'] = $cardData['wallet'];
                }

                // Only update if we have some data to migrate
                if (!empty($newCardData)) {
                    $connection->update(
                        $this->moduleDataSetup->getTable('stripe_payment_methods'),
                        ['payment_method_card_data' => $this->serializer->serialize($newCardData)],
                        ['entity_id = ?' => $paymentMethod['entity_id']]
                    );
                }
            } catch (\Exception $e) {
                // Log error but continue with other records
                continue;
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [
            Patch008PaymentMethodConfiguration::class
        ];
    }

    public function revert()
    {

    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}
