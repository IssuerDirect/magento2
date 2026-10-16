<?php
declare(strict_types=1);

namespace AccessNewswire\Storefront\Setup\Patch\Data;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Api\Data\BlockInterface;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\Store;

/**
 * Creates the empty CMS block the AccessNewswire/checkout theme renders
 * above the page title, so staff have a slot to fill from the admin.
 */
class CreateCheckoutNoticeBlock implements DataPatchInterface
{
    public const IDENTIFIER = 'checkout_notice';

    public function __construct(
        private readonly BlockFactory $blockFactory,
        private readonly BlockRepositoryInterface $blockRepository
    ) {
    }

    public function apply(): self
    {
        // Leave a block someone already created (e.g. on alpha) alone.
        if ($this->blockFactory->create()->load(self::IDENTIFIER, BlockInterface::IDENTIFIER)->getId()) {
            return $this;
        }

        $block = $this->blockFactory->create();
        $block->setData([
            BlockInterface::IDENTIFIER => self::IDENTIFIER,
            BlockInterface::TITLE => 'Checkout Notice',
            BlockInterface::CONTENT => '',
            BlockInterface::IS_ACTIVE => true,
            'stores' => [Store::DEFAULT_STORE_ID],
        ]);
        $this->blockRepository->save($block);

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
}
