<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use StripeIntegration\Payments\Helper\RedirectInvoice;

// Filters out invoices that have been de-registered by RedirectInvoice::deregister()
// so they are not persisted by Magento\Sales\Model\ResourceModel\Order\Relation
// during the order save.
class FilterRelatedObjects
{
    public function afterGetRelatedObjects(Order $subject, $result)
    {
        if (empty($result) || !is_array($result))
        {
            return $result;
        }

        $filtered = [];
        foreach ($result as $object)
        {
            if ($object instanceof Invoice && $object->getDataByKey(RedirectInvoice::FLAG))
            {
                continue;
            }
            $filtered[] = $object;
        }

        return $filtered;
    }
}
