<?php

declare(strict_types=1);

namespace StripeIntegration\Payments\Model\Adminhtml\Source\Subscriptions;
class EmailTemplate implements \Magento\Framework\Data\OptionSourceInterface
{
    private $templatesCollection;
    private $templatesFactory;
    /**
     * @param \Magento\Email\Model\ResourceModel\Template\CollectionFactory $templatesFactory
     */
    public function __construct(
        \Magento\Email\Model\ResourceModel\Template\CollectionFactory $templatesFactory
    ) {
        $this->templatesFactory = $templatesFactory;
    }

    /**
     * Generate list of email templates
     *
     * @return array
     */
    public function toOptionArray()
    {
        /** @var $collection \Magento\Email\Model\ResourceModel\Template\Collection */
        if (!$this->templatesCollection) {
            $collection = $this->templatesFactory->create();
            $this->templatesCollection = $collection->load();
        }
        $options = $this->templatesCollection->toOptionArray();
        // Add empty option as the first option
        array_unshift($options, ['value' => null, 'label' => __('-- Please select a template --')]);

        return $options;
    }
}
