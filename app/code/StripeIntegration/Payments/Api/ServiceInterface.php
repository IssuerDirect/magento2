<?php

namespace StripeIntegration\Payments\Api;

interface ServiceInterface
{
    /**
     * Get Express Checkout Element initialization params
     *
     * @api
     * @param string $location
     * @param string|null $productId
     * @param string|null $attribute
     *
     * @return mixed Json object with params
     */
    public function ece_params($location, $productId = null, $attribute = null);

    /**
     * Get new shipping rates for the new address
     *
     * @api
     * @param mixed $newAddress
     * @param string $location
     *
     * @return string
     */
    public function ece_shipping_address_changed($newAddress, $location);

    /**
     * Apply Shipping Method
     *
     * @api
     * @param mixed $address
     * @param string|null $shipping_id
     *
     * @return string
     */
    public function ece_shipping_rate_changed($address, $shipping_id = null);

    /**
     * Place Order
     *
     * @api
     * @param mixed $result
     * @param mixed $location
     *
     * @return string
     */
    public function place_order($result, $location);

    /**
     * Add to Cart
     *
     * @api
     * @param mixed $params
     * @param string|null $shipping_id
     *
     * @return string
     */
    public function addtocart($params, $shipping_id = null);

    /**
     * Get Trialing Subscription data
     *
     * @api
     * @param mixed $billingAddress
     * @param mixed|null $shippingAddress
     * @param mixed|null $shippingMethod
     * @return string
     */
    public function get_future_subscriptions($billingAddress = null, $shippingAddress = null, $shippingMethod = null);

    /**
     * Get Stripe Checkout available payment methods for the ative customer quote
     *
     * @api
     * @param mixed $billingAddress
     * @param mixed|null $shippingAddress
     * @param mixed|null $shippingMethod
     * @param string|null $couponCode
     *
     * @return string
     */
    public function get_checkout_payment_methods($billingAddress, $shippingAddress = null, $shippingMethod = null, $couponCode = null);

    /**
     * Get Stripe Checkout session ID, only if it is still valid/open/non-expired
     *
     * @api
     *
     * @return string
     */
    public function get_checkout_session_id();

    /**
     * Get Stripe Checkout session redirect URL, only if it is still valid/open/non-expired
     *
     * @api
     *
     * @return string
     */
    public function get_checkout_session_url();

    /**
     * Restores the quote of the last placed order
     *
     * @api
     *
     * @return mixed
     */
    public function restore_quote();

    /**
     * After a payment failure, and before placing the order for a 2nd time, we call the update_cart method to check if anything
     * changed between the quote and the previously placed order. If it has, we cancel the old order and place a new one.
     *
     * @api
     * @param mixed|null $data
     *
     * @return mixed
     */
    public function update_cart($data = null);

    /**
     * If the last payment requires further action, this returns the client secret of the object that requires action
     *
     * @api
     *
     * @return mixed|null
     */
    public function get_requires_action();

    /**
     * Places a multishipping order
     *
     * @api
     *
     * @return mixed|null $result
     */
    public function place_multishipping_order();

    /**
     * Finalizes a multishipping order after a card is declined or customer authentication fails and redirects the customer to the results or success page
     *
     * @api
     * @param string|null $error
     *
     * @return mixed|null $result
     */
    public function finalize_multishipping_order($error = null);

    /**
     * For subscription updates, it retrieves totals for the subscription update
     *
     * @api
     *
     * @return mixed|null
     */
    public function get_upcoming_invoice();

    /**
     * Add a new saved payment method by ID or confirmation token
     *
     * @api
     * @param string $paymentMethodId Payment method ID (pm_*) or confirmation token (ctoken_*)
     * @param string|null $subscriptionId
     *
     * @return mixed $paymentMethod
     */
    public function add_payment_method($paymentMethodId, $subscriptionId = null);

    /**
     * Delete a saved payment method by ID
     *
     * @api
     * @param string $paymentMethodId
     * @param string $fingerprint
     *
     * @return mixed $result
     */
    public function delete_payment_method($paymentMethodId, $fingerprint = null);

    /**
     * List a customer's saved payment methods
     *
     * @api
     * @return mixed $result
     */
    public function list_payment_methods();

    /**
     * Get Module Configuration for Stripe initialization
     * @api
     * @return mixed $result
     */
    public function getStripeConfiguration();

    /**
     * Get Module Configuration for Stripe ECE initialization
     *
     * @api
     * @param string $location
     *
     * @return mixed $result
     */
    public function get_stripe_ece_configuration($location);

    /**
     * Get the installment plans
     *
     * @api
     * @return mixed
     */
    public function getInstallmentPlans();

    /**
     * Change the default payment method of a subscription
     *
     * @api
     * @param string $subscriptionId
     * @param string $paymentMethodId
     *
     * @return mixed $result
     */
    public function change_subscription_payment_method($subscriptionId, $paymentMethodId);
}
