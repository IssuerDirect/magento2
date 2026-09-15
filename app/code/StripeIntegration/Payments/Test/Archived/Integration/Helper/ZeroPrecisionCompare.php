<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

use StripeIntegration\Tax\Test\Integration\Helper\Compare;

class ZeroPrecisionCompare extends Compare
{
    public function __construct($test)
    {
        parent::__construct($test);
    }
    /**
     * @param $expected
     * @param $actual
     * @param $message
     *
     * Because we are not able to replicate the Stripe tax calculation algorithm exactly, there are cases where some
     * expected values might differ with a maximum of 2 delta from what comes directly from the API.
     * The purpose of this method is that if the expected and actual values differ, they are tested for a delta.
     * The test will fail if the delta is more than 2 at the moment.
     * The other assertions are made as per usual for a test.
     *
     * @return void
     */
    public function assertValues($expected, $actual, $message)
    {
        if ($expected != $actual) {
            // For debugging purposes we can use xdebug or simply echo the deltas and other values in case
            // tests where the expected and actual values differ from the set delta
            $this->getTest()->assertEqualsWithDelta($expected, $actual, 2, $message . ' more than 0.02');
        } else {
            $this->getTest()->assertEquals($expected, $actual, $message);
        }
    }
}