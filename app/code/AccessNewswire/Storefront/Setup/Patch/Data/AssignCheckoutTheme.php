<?php
declare(strict_types=1);

namespace AccessNewswire\Storefront\Setup\Patch\Data;

use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Theme\Model\Config as ThemeConfig;
use Magento\Theme\Model\Data\Design\Config as DesignConfig;
use Magento\Theme\Model\ResourceModel\Theme\CollectionFactory as ThemeCollectionFactory;
use Magento\Theme\Model\Theme\Registration;

/**
 * Assigns the AccessNewswire/checkout theme to every store view - the same
 * thing saving Content > Design > Configuration does.
 */
class AssignCheckoutTheme implements DataPatchInterface
{
    public const THEME_PATH = 'frontend/AccessNewswire/checkout';

    public function __construct(
        private readonly Registration $themeRegistration,
        private readonly ThemeCollectionFactory $themeCollectionFactory,
        private readonly ThemeConfig $themeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly IndexerRegistry $indexerRegistry
    ) {
    }

    public function apply(): self
    {
        // Magento_Theme only registers new themes in its recurring data
        // step, which runs after data patches, so the theme isn't in the
        // `theme` table yet on the upgrade that adds it.
        $this->themeRegistration->register();

        $theme = $this->themeCollectionFactory->create()->getThemeByFullPath(self::THEME_PATH);
        if (!$theme->getId()) {
            throw new \RuntimeException(sprintf('Theme "%s" is not registered.', self::THEME_PATH));
        }

        $storeIds = array_map(
            static fn ($store) => (int) $store->getId(),
            array_values($this->storeManager->getStores())
        );
        $this->themeConfig->assignToStore($theme, $storeIds);

        // The admin's design configuration grid reads from this index.
        $this->indexerRegistry->get(DesignConfig::DESIGN_CONFIG_GRID_INDEXER_ID)->reindexAll();

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
