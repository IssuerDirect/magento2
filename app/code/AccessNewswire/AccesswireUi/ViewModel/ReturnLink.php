<?php
declare(strict_types=1);

namespace AccessNewswire\AccesswireUi\ViewModel;

use AccessNewswire\AccesswireUi\Model\ReturnDestination;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class ReturnLink implements ArgumentInterface
{
    public function __construct(
        private readonly ReturnDestination $returnDestination
    ) {
    }

    public function getUrl(): ?string
    {
        return $this->returnDestination->getUrlAfterOrder();
    }

    public function getText(): string
    {
        return $this->returnDestination->getText();
    }
}
