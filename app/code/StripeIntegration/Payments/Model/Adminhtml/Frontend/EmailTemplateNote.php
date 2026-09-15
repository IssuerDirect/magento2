<?php

namespace StripeIntegration\Payments\Model\Adminhtml\Frontend;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class EmailTemplateNote extends Field
{
    public function render(AbstractElement $element)
    {
        $url = $this->getUrl('adminhtml/email_template/index');
        $link = '<a href="' . $this->escapeUrl($url) . '">' . __('Marketing > Email Templates') . '</a>';
        $title = __('Email Templates');
        $subtitle = __('You can configure custom email templates from %1.', $link);

        $html = '<tr>'
            . '<td class="label"></td>'
            . '<td colspan="4" style="padding-top: 2.5rem; padding-bottom: 0.5rem;">'
            . '<strong style="font-size: 1.7rem;">' . $this->escapeHtml($title) . '</strong><br/>'
            . '<span style="font-size: 1.2rem; color: #666;">' . /* @noEscape */ $subtitle . '</span>'
            . '</td>'
            . '</tr>';

        return $html;
    }
}
