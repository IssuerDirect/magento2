---
title: Build a custom storefront
subtitle: Learn how to build a custom storefront that supports Stripe payment features.
route: /use-stripe-apps/adobe-commerce/payments/custom-storefront
redirects:
  - /use-stripe-apps/adobe-commerce/custom-storefront
  - /connectors/adobe-commerce/payments/custom-storefront
stripe_products: []
---

Adobe Commerce can operate as a headless commerce platform that's decoupled from its storefront. You can use the REST API or GraphQL API to build custom storefronts, such as progressive web apps (PWA), mobile apps, or frontends based on React, Vue, or other frameworks.

The Stripe module extends the REST API and GraphQL API by:

- Setting confirmation tokens during order placement
- Performing 3D Secure customer authentication
- Managing customers' saved payment methods

The Stripe module uses the REST API on the checkout page. You can find examples of how to use the API in the Stripe module directory under the `resources/examples/` subdirectory. This guide uses the GraphQL API to build a custom storefront.

## Retrieve initialization parameters {% #retrieve-initialization-parameters %}

To initialize Stripe.js and the payment form on the front end, you need the Stripe [publishable API key](/keys#obtain-api-keys) that you configured in the admin area. You can retrieve the key and other initialization parameters using the following GraphQL mutation:

```
query {
getStripeConfiguration {
	apiKey
		locale
		appInfo
		options {
			betas
		}
	elementsOptions
	}
}
```

## Tokenize a payment method during the checkout flow {% #tokenize-payment-method %}

You can use the [PaymentElement](/payments/payment-element) to collect a payment method from the customer during checkout. After the customer provides their payment method details and clicks **Place Order**, you can create a confirmation token and use it to place the order. Calling `createConfirmationToken` generates a confirmation token from the details provided in the `PaymentElement`.

```
var stripe = Stripe(API_PUBLISHABLE_KEY);

var options = {
  mode: 'payment',
  amount: 1099,
  currency: 'eur'
};

var elements = stripe.elements(options);
var paymentElement = elements.create('payment');
paymentElement.mount('#payment-element');

var placeOrder = function()
{
    elements.submit().then(function()
    {
        stripe.createConfirmationToken({
            elements: elements,
            params: {
                payment_method_data: {
                    billing_details: {
                        name: 'Jenny Rosen',
                        email: 'jenny@example.com',
                        phone: '704-596-9612'
                    }
                },
                shipping: {
                  name: 'Jenny Rosen',
                  address: {
                    line1: '1182 Concord Street',
                    city: 'Toledo',
                    state: 'Oregon',
                    postal_code: '97391',
                    country: 'US'
                  }
                }
            }
        }).then(function(result)
        {
            if (result.error)
            {
                // result.error.message
            }
            else if (result.confirmationToken)
            {
                // Success
            }
        });
    });
}
```

## Pass the tokenized payment method {% #pass-tokenized-payment-method %}

After you obtain a confirmation token, you must call `setPaymentMethodOnCart` to [set the payment method](https://developer.adobe.com/commerce/webapi/graphql/tutorials/checkout/set-payment-method/#set-payment-method-on-cart) on the order.

```
mutation {
  setPaymentMethodOnCart(input: {
      cart_id: "CART_ID"
      payment_method: {
        code: "stripe_payments"
        stripe_payments: {
          confirmation_token: "CONFIRMATION_TOKEN_ID"
          save_payment_method: true
        }
      }
  }) {
    cart {
      selected_payment_method {
        code
      }
    }
  }
}
```

Use the following parameters for `setPaymentMethodOnCart`:

{% table %}
* Parameter
* Type
* Description
---
* `confirmation_token`
* String
* Use this parameter to pass the confirmation token ID. For saved payment methods, use the `payment_method` parameter instead.
---
* `payment_method`
* String
* Use this parameter to pass saved payment method IDs when a customer chooses a saved payment method during checkout.
---
* `save_payment_method`
* Boolean
* Specify whether or not to save the payment method.
{% /table %}

## Place the order {% #place-order %}

After you set the confirmation token or payment method, you can use the Adobe Commerce `placeOrder` mutation to place an order:

```
mutation {
  placeOrder(input: {cart_id: "CART_ID"}) {
    order {
      order_number
      client_secret
    }
  }
}
```

The example above requests a `client_secret`, which isn't a default `placeOrder` mutation parameter. The Stripe module adds this parameter for you to use after the order is placed to finalize details specific to the selected payment method. You can finalize payment with the `stripe.handleNextAction(client_secret)` method. Options include performing an inline 3D Secure authentication for cards, displaying a printable voucher for certain payment methods, or redirecting the customer externally for authentication.

## Order placement flow {% #order-placement-flow %}

Payment methods of type `card` or `link` that require 3D Secure (3DS) customer authentication go through the following process:

1. The order is placed in `Pending Payment` status.
1. The client secret is passed to the front-end to perform the authentication.
1. After successful authentication, payment is collected client-side, and the customer is redirected to the order success page.
1. A `charge.succeeded` webhook event arrives at your website on the server side.
1. The module handles the event and changes the order status from `Payment Pending` to `processing`.

This flow is the default with GraphQL, not with the REST API. To use the same flow with the REST API, go to **Admin > Stores > Configuration > Sales > Payment Methods > Stripe > Advanced Configuration**, then set **Place order first (REST API)** to **Enabled**.

## Get available payment methods for checkout {% #checkout-payment-methods %}

When using Stripe Checkout (a redirect-based payment flow), you need to determine which payment methods are available for the current quote before you can redirect the customer to Stripe's hosted checkout page. The available methods depend on the quote's billing address, shipping address, and coupon code.

Use `getCheckoutPaymentMethods` to apply these details to the active quote and retrieve the enabled payment method types. Stripe Checkout evaluates payment method eligibility based on the customer's location, currency, and cart contents, so you must provide the addresses before creating the Checkout session.

```
query {
  getCheckoutPaymentMethods(input: {
    billingAddress: "{\"firstname\":\"John\",\"lastname\":\"Smith\",\"street\":[\"123 Main St\"],\"city\":\"Los Angeles\",\"region\":\"California\",\"postcode\":\"90001\",\"country_id\":\"US\",\"email\":\"customer@example.com\"}",
    shippingAddress: "{\"firstname\":\"John\",\"lastname\":\"Smith\",\"street\":[\"123 Main St\"],\"city\":\"Los Angeles\",\"region\":\"California\",\"postcode\":\"90001\",\"country_id\":\"US\"}",
    shippingMethod: "{\"carrier_code\":\"flatrate\",\"method_code\":\"flatrate\"}",
    couponCode: "COUPON_CODE"
  }) {
    methods
  }
}
```

The query accepts JSON-encoded data for the address and shipping method parameters. The `methods` response contains payment method type identifiers such as `card`, `klarna`, `ideal`, `bancontact`, and others that you can present to the customer.

{% table %}
* Parameter
* Type
* Description
---
* `billingAddress`
* String!
* JSON-encoded billing address data with fields like `firstname`, `lastname`, `street`, `city`, `region`, `postcode`, `country_id`, `email`.
---
* `shippingAddress`
* String
* JSON-encoded shipping address data with the same fields as the billing address.
---
* `shippingMethod`
* String
* JSON-encoded shipping method data with `carrier_code` and `method_code` fields.
---
* `couponCode`
* String
* Coupon code to apply to the quote.
{% /table %}

## Restore a quote {% #restore-quote %}

This mutation is used in the Stripe Checkout and 3DS redirect flow. When a customer is sent to an external page for payment (Stripe Checkout, bank redirect, 3DS authentication) and returns to the store, their quote may be in an inactive state. Call `restoreQuote` to reactivate it and replace it in the checkout session so the customer can continue or retry. It's safe to call multiple times and returns an empty result on success.

```
mutation {
  restoreQuote {
    error
  }
}
```

## Update the cart after a payment failure {% #update-cart}

When a payment fails after the order was placed, the customer may change their cart before retrying. Use `updateCart` to detect any differences between the current quote and the previously placed order. If anything changed — totals, items, shipping address, billing address, shipping method, or payment method — the previous order is canceled and `placeNewOrder` is returned as `true` with a reason explaining what changed. If nothing changed, `placeNewOrder` is returned as `false` and the existing order can be retried with a new confirmation token.

The mutation performs the following checks in order:

1. Quote availability — if the quote can't be loaded, a new order is required
2. Order existence — the order associated with the quote's reserved order ID must exist
3. Multishipping check — if the order is multishipping, a new order is required (this method doesn't support multishipping)
4. Quote data comparison — compares currency, customer, subtotal, and grand total
5. Item comparison — compares SKU, quantity, and row totals
6. Shipping address comparison — compares all shipping address fields
7. Shipping method comparison — compares carrier code and amount
8. Billing address comparison — compares all billing address fields
9. Payment method comparison — if a new confirmation token or payment method ID is provided, it's compared against the existing one
10. Order state validation — the order must be in `pending_payment` state to retry

If all checks pass, the payment intent is reconfirmed with the new token and the mutation returns `placeNewOrder: false`.

```
mutation {
  updateCart(input: {
    data: "{\"additional_data\":{\"confirmation_token\":\"ctoken_123\"}}"
  }) {
    placeNewOrder
    reason
    error
  }
}
```

{% table %}
* Parameter
* Type
* Description
---
* `data`
* String
* JSON-encoded additional data, such as `{"additional_data":{"confirmation_token":"ctoken_abc123"}}` or `{"additional_data":{"payment_method":"pm_abc123"}}`.
{% /table %}

## Get checkout session redirect URL {% #checkout-session-url %}

Use `getCheckoutSessionUrl` in the Stripe Checkout redirect flow. After an order is placed using Stripe Checkout, the customer needs to be redirected to Stripe's hosted checkout page to complete payment. This query returns the Stripe Checkout session redirect URL if the following conditions are met:

- A Stripe Checkout session was created for the active quote
- An order was placed against that session
- The quote still matches the order (the customer hasn't changed their cart)

If those conditions are met, you can redirect the customer to the returned URL via `window.location.href = url`.

If the customer changed their cart after placing the order, the previous order is canceled and `null` is returned, allowing a new Checkout session to be created. Returns `null` if no valid session exists.

```
query {
  getCheckoutSessionUrl
}
```

The `getCheckoutSessionId` query is also still available for backwards compatibility and returns the Stripe Checkout session ID, which you can use to construct the redirect URL (`https://checkout.stripe.com/c/pay/<session_id>`). We recommend using `getCheckoutSessionUrl` instead.

## Retrieve saved payment methods {% #retrieve-payment-methods %}

You can use `listStripePaymentMethods` to retrieve a list of saved payment methods for a customer in an active checkout session.

```
mutation {
  listStripePaymentMethods {
    id
    created
    type
    fingerprint
    label
    icon
    cvc
    brand
    exp_month
    exp_year
  }
}
```

## Save a payment method {% #save-payment-method %}

You can use `addStripePaymentMethod` to save payment methods to a customer's account. The `payment_method` parameter is the tokenized payment method ID. The tokenization process is similar to the tokenization process during the checkout flow.

```
mutation {
  addStripePaymentMethod(
    input: {
      payment_method: "PAYMENT_METHOD_ID"
    }
  ) {
    id
    created
    type
    fingerprint
    label
    icon
    cvc
    brand
    exp_month
    exp_year
  }
}
```

## Delete a saved payment method {% #delete-payment-method %}

You can use `deleteStripePaymentMethod` to allow customers to delete saved payment methods from their account.

For most use cases, we recommend passing a payment method fingerprint, which deletes all payment methods that match the fingerprint. The `listStripePaymentMethods` mutation automatically removes duplicates before returning recently added payment methods that match a specific fingerprint. But if you only delete a payment method by ID, the results of `listStripePaymentMethods` might include an older saved payment method with the same fingerprint.

```
mutation {
  deleteStripePaymentMethod(
    input: {
      payment_method: "paste a payment method ID here"
      fingerprint: null
    }
  )
}
```

## Get future subscription details {% #future-subscriptions %}

Subscription products with a trial period or a start date display special preview information to the customer before the order is placed. Use `getFutureSubscriptions` to retrieve these details for subscription products in the active quote. The query applies the provided addresses and shipping method to the quote before calculating subscription totals, so the returned amounts reflect any taxes or discounts.

The response contains:
- `title` — always "Subscription Total" when subscription products are present
- `start_date_label` — the date or delay until the subscription starts (e.g., "7 day trial" or a specific date)
- `frequency_label` — the billing interval (e.g., "every month" or "every 3 months")
- `formatted_amount` — the formatted subscription price including currency symbol (e.g., "$10.00")

```
query {
  getFutureSubscriptions(input: {
    billingAddress: "{\"firstname\":\"John\",\"lastname\":\"Smith\",\"email\":\"customer@example.com\"}"
  }) {
    title
    start_date_label
    frequency_label
    formatted_amount
  }
}
```

{% table %}
* Parameter
* Type
* Description
---
* `billingAddress`
* String
* JSON-encoded billing address data.
---
* `shippingAddress`
* String
* JSON-encoded shipping address data.
---
* `shippingMethod`
* String
* JSON-encoded shipping method data.
{% /table %}

## Get upcoming invoice {% #upcoming-invoice %}

When the customer is updating a subscription (for example, upgrading to a different product or changing quantities), use `getUpcomingInvoice` to preview the next invoice that Stripe will generate. This lets you show the customer the financial impact of the change before it's applied.

The query evaluates the subscription update details currently stored in the session and returns:
- `new_price` — contains the `amount`, `currency`, and a human-readable `label` showing the updated subscription price
- `credit` — if the customer has account credit in Stripe, this field contains a description of how it will be applied (e.g., "Your account's credit of $5.00 will be used to offset future subscription payments.")
- `error` — if the subscription ID is invalid or the price currency doesn't match, an error is returned

Call this after setting the subscription update details in the session and before committing the change.

```
query {
  getUpcomingInvoice {
    new_price {
      amount
      currency
      label
    }
    credit
    error
  }
}
```

## Change the payment method of a subscription {% #change-subscription-payment-method %}

For logged-in customers, you can change a Stripe subscription's default payment method using either GraphQL or REST.

GraphQL mutation:

```
mutation {
  changeSubscriptionPaymentMethod(
    input: {
      subscription_id: "sub_123"
      payment_method: "pm_123"
    }
  )
}
```

REST API endpoint:

```
POST /rest/V1/stripe/payments/change_subscription_payment_method
Content-Type: application/json
Authorization: Bearer <customer_token>

{
  "subscriptionId": "sub_123",
  "paymentMethodId": "pm_123"
}
```

You can use the GraphQL API for orders with Express Checkout Element. It can be used to place orders with a guest customer
or with a logged in customer. You can find an example of using the ECE in the `resources/examples/` subdirectory.

## Retrieve customer token {% #retrieve-customer-token %}

In order to create an order with a logged in customer, you need to have the customer token from Adobe Commerce and pass
it down to all the subsequent requests as a `Bearer` token in the `Authorization` parameter.

Once the token is created and used for subsequent requests, the GraphQL context is able to determine the customer who
is calling the APIs.

This is an out-of-the-box mutation provided by Adobe Commerce.

```
mutation {
    generateCustomerToken(
        email: "email@example.com"
        password: "password"
    ) {
        token
    }
}
```

## Create/Associate quote {% #create-quote %}
By using the customer token and calling the quote creation mutation, a new quote is created or the user gets associated
with a quote he previously had. This mutation is also provided by Adobe Commerce.
```
query {
    customerCart {
        id
    }
}
```

## Get installment plans {% #installment-plans %}

When Stripe card installments are enabled, the module stores available installment plans in the session during order placement. Use `getInstallmentPlans` to retrieve these options so you can display them to the customer for selection.

The raw installment flow works as follows:

1. The customer places an order with a Stripe payment method that supports installments
2. The module detects available installment plans on the Stripe PaymentIntent and stores them in the session, then throws an `InstallmentsException`
3. Your front-end catches this exception and calls `getInstallmentPlans` to display the options
4. The customer selects a plan, and the selected plan is added to the order's payment additional data
5. The order placement is retried with the selected installment plan

The query returns a JSON-encoded object with a `details` array containing one option per installment plan (each with a `data` and `label` field), and a `total_label` field showing the formatted total. Unserialize the result in your front-end:

```
query {
  getInstallmentPlans
}
```

## Retrieve the Stripe ECE configuration {% #retrieve-stripe-configuration %}

These are configurations necessary to be able to create an Express Checkout Element on the page. The `location` parameter
can be either `product`, `cart` or `minicart` and denotes the place where the ECE is initialized.

The mutation will return an `enabled` parameter. If this parameter is `true`, then the response will also have configuration components
like the API keys for initializing Stripe JS library and admin configurations for the ECE buttons which will appear. If the
parameter is `false`, no other data will be provided, as the ECE is not enabled for the location.

```
query {
    getStripeECEConfiguration(
        input: {
            location: "product"
        }
    ) {
        enabled
        initParams {
            apiKey
            locale
            appInfo {
                name
                partner_id
                url
                version
            }
            options {
                betas
            }
        }
        buttonConfig
    }
}
```

## Retrieve ECE parameters {% #retrieve-initialization-parameters %}

These parameters are what is used to initialize the ECE with data such as mandatory addresses, availability countries,
initial totals for payment and theme settings for the element.

```
mutation {
    getECEParams(
        input: {
            location: "product"
            productId: "SIMPLE_PRODUCT_ID"
        }
    ) {
        resolvePayload {
            shippingRates {
                id
                amount
                displayName
            }
        }
        elementOptions {
            mode
            locale
            appearance {
                theme
                variables {
                    colorText
                    fontFamily
                }
            }
            currency
            amount
        }
        expressCheckoutOptions {
            allowedShippingCountries
            billingAddressRequired
            emailRequired
            phoneNumberRequired
            shippingAddressRequired
        }
    }
}
```

Once the Element is initialized, for the purposes of the example, we will assume that we are on a product page. In this scenario,
once we click on the payment button, the product is automatically added to the basket as a handler for the `click` event triggered on the ECE.

## Add a product to the basket {% #add-product-to-basket %}

```
mutation addProductToCart($params: String) {
    addToCart(input: {
        params: $params,
    })
}
```

As there are different types of products that can be added to the, the input parameters will differ based on the product types.

For a simple product, the parameters will look like this:

```
{
    product: productId,
    item: productId,
    qty: 1
}
```

For other product types, different parameters will need to be added to this request object. Configurable products will need the `super_attribute` component.
This component can either be an object with the key - value pairs consisting of the configurable option ID and value, or strings in the
request in the format of `super_attribute[option_id]: option_value`

```
super_attribute: {
    ID1: value1,
    ID2: value2
}
or
super_attribute[ID1]: value1
super_attribute[ID2]: value2
```

For customizable products, the component is called `options` and should follow the same rule.

```
options: {
    ID1: value1,
    ID2: value2
}
or
options[ID1]: value1
options[ID2]: value2
```

For bundled products there are 2 components which need to be added `bundle_option` and `bundle_option_qty`.

```
"bundle_option[ID1]": "value1",
"bundle_option[ID2]": "value2",
"bundle_option[ID3]": "value3",
"bundle_option[ID4]": "value1",
"bundle_option_qty[ID1]": "value",
"bundle_option_qty[ID2]": "value",
"bundle_option_qty[ID3]": "value",
"bundle_option_qty[ID4]": "value"
```

## Shipping address change handler {% #shipping-address-change %}

This endpoint will be called on the `shippingaddresschange` event on the Element. The result of the call will be used for
resolve function of the event, which will update the totals on the Element. The `newAddress` parameter should be taken from
`address` parameter of the event that is being processed. This address should also be kept so that it can be used for the
shipping rate change handler when it is called.

The `shippingaddresschange` will be called on the ECE either when the user enters a shipping address manually when the ECE modal is
created, or when an address is selected if the user has addresses saved under the selected payment method.

```
mutation shippingAddressChanged($newAddress: ECEAddress) {
    eceShippingAddressChanged(
        input: {
            location: "product"
            newAddress: $newAddress
        }
    ) {
        resolvePayload {
            lineItems {
                name
                amount
            }
            shippingRates {
                id
                amount
                displayName
            }
        }
    }
}
```

## Shipping rate change handler {% #shipping-rate-change %}

This endpoint will be called on the `shippingratechange` event on the Element. The rate parameter will come from the processed events'
`shippingRate.id` component if it is set. The address parameter is the saved address from the Address change endpoint. We should have this
element saved at any point in our actions, as the `shippingaddresschange` event will be triggered during ECE modal creation process.

```
mutation shippingRateChanged(
        $address: ECEAddress
        $shippingMethodId: String
    ) {
    eceShippingRateChanged(input: {
        shippingMethodId: $shippingMethodId
        address: $address
    }
    ) {
        resolvePayload {
            lineItems {
                name
                amount
            }
            shippingRates {
                id
                amount
                displayName
            }
        }
    }
}
```

## Place ECE order {% #place-ece-order %}

```
mutation PlaceOrder($payload: String!) {
    ecePlaceOrder(input: {
        location: "product"
        result: $payload
    }) {
        client_secret
        redirect
    }
}
```

The `result` parameter is the event that is being processed. This parameter needs to have a confirmation token added to it.

```
stripe.createConfirmationToken(paymentMethodData).then(function(createConfirmationTokenResult) {
    if (createConfirmationTokenResult.error)
    {
        // handle error
    }
    else if (createConfirmationTokenResult.confirmationToken)
    {
        event.confirmationToken = createConfirmationTokenResult.confirmationToken;
        processedEvent = event;
        // call place order handler using the event
    }
})
```

## Multishipping checkout {% #multishipping-checkout %}

The Stripe module provides two mutations for handling the multishipping checkout flow. In a multishipping checkout, the quote is split into multiple orders (one per shipping address) that are all placed under a single Stripe PaymentIntent. The module extends Magento's built-in multishipping checkout by handling Stripe payment processing for all orders together.

### Setup the multishipping quote

Before calling any multishipping mutations, the quote must be configured through Magento's standard multishipping setup process:

1. Create a quote and set `is_multi_shipping` to `true`
2. Add items to the quote with quantities split across shipping addresses
3. Assign each set of items to a customer shipping address
4. Select shipping methods per address
5. Set a payment method using a Stripe confirmation token

### Place a multishipping order {% #place-multishipping-order %}

After the quote is configured, call `placeMultishippingOrder` to place the orders. The mutation:

1. Validates the multishipping quote configuration
2. Creates Magento orders for each shipping address via Magento's `createOrders()` method
3. Creates a single Stripe PaymentIntent for the combined total of all orders
4. Confirms the PaymentIntent with the confirmation token set on the quote
5. Records the last transaction ID on each order's payment

```
mutation {
  placeMultishippingOrder {
    redirect
    error
    authenticate
  }
}
```

The response contains:
- `redirect` — URL for the next step in Magento's multishipping flow (success page, results page, or cart if expired)
- `error` — error message if the order placement failed
- `authenticate` — client secret for 3DS authentication if the PaymentIntent requires action

If 3DS authentication is required (`authenticate` is present), perform the authentication on the client side using `stripe.handleNextAction({ clientSecret })`. After authentication completes, call `finalizeMultishippingOrder` without an error to finalize the orders.

### Finalize a multishipping order {% #finalize-multishipping-order %}

Call `finalizeMultishippingOrder` after the payment flow completes. The behavior depends on whether an error is provided:

- **With an error** — cancels all pending orders linked to the quote, records the error message on each order as a comment, and redirects the customer to the results page where they can retry.
- **Without an error** — removes successfully placed orders from the quote and redirects the customer to the success page.

```
mutation {
  finalizeMultishippingOrder(error: "Payment declined") {
    redirect
    error
  }
}
```

{% see-also %}

- [SetupIntents API](/payments/setup-intents)
- [Use the Adobe Commerce admin panel](/use-stripe-apps/adobe-commerce/payments/admin)
- [Troubleshooting](/use-stripe-apps/adobe-commerce/payments/troubleshooting)
