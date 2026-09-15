define(
    [
        'ko',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'StripeIntegration_Payments/js/action/post-update-cart',
        'StripeIntegration_Payments/js/action/post-restore-quote',
        'StripeIntegration_Payments/js/action/get-requires-action',
        'StripeIntegration_Payments/js/action/get-installment-plans',
        'StripeIntegration_Payments/js/stripe',
        'mage/translate',
        'jquery',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Checkout/js/model/payment/method-list',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_CheckoutAgreements/js/model/agreement-validator',
        'Magento_Customer/js/customer-data',
        'Magento_Ui/js/modal/modal'
    ],
    function (
        ko,
        Component,
        quote,
        updateCartAction,
        restoreQuoteAction,
        getRequiresAction,
        getInstallmentPlans,
        stripe,
        $t,
        $,
        placeOrderAction,
        selectPaymentMethodAction,
        methodList,
        additionalValidators,
        agreementValidator,
        customerData,
        modal
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/element',
                stripePaymentsShowExpressCheckoutSection: false
            },
            redirectAfterPlaceOrder: false,
            elements: null,
            initParams: null,
            paymentElement: null,
            zeroDecimalCurrencies: ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'],
            threeDecimalCurrencies: ['BHD','JOD','KWD','OMR','TND'],
            selectedPaymentMethodCode: null,

            initObservable: function ()
            {
                this._super()
                    .observe([
                        'paymentElement',
                        'isLoading',
                        'stripePaymentsError',
                        'permanentError',
                        'isOrderPlaced',
                        'isInitializing',
                        'useQuoteBillingAddress',
                        'isPlaceOrderActionAllowed',
                        'confirmationToken',

                        // Installments
                        'installmentPlans',
                        'selectedInstallmentPlan',
                        'installmentsTotalLabel'
                    ]);

                var self = this;

                this.isOrderPlaced(false);
                this.isInitializing(true);
                this.useQuoteBillingAddress(false);
                this.confirmationToken(null);
                this.installmentPlans([]);
                this.selectedInstallmentPlan(0);
                this.installmentsTotalLabel('');

                var currentTotals = quote.totals();

                quote.totals.subscribe(function (totals)
                {
                    if (!totals || !totals.grand_total || !totals.quote_currency_code)
                    {
                        return;
                    }

                    if (!currentTotals || !currentTotals.grand_total || !currentTotals.quote_currency_code)
                    {
                        currentTotals = totals;
                        return;
                    }

                    var amount1 = totals.grand_total;
                    var amount2 = currentTotals.grand_total;
                    var currency1 = totals.quote_currency_code;
                    var currency2 = currentTotals.quote_currency_code;

                    if (amount1 === amount2 && currency1 === currency2)
                    {
                        return;
                    }

                    currentTotals = totals;

                    self.onQuoteTotalsChanged.bind(self)();
                    self.isOrderPlaced(false);
                }, this);

                quote.paymentMethod.subscribe(function (method)
                {
                    if (method && method.method == this.getCode() && !this.isInitializing())
                    {
                        // We intentionally re-create the element because its container element may have changed
                        this.initPaymentForm();
                    }
                }, this);

                quote.billingAddress.subscribe(function(address)
                {
                    if (address && self.paymentElement && self.paymentElement.update)
                    {
                        // Remove the postcode & country fields if a billing address has been specified
                        self.paymentElement.update(self.getPaymentElementUpdateOptions());
                    }
                });

                // Auto-select this payment method when it loads
                // if (!quote.paymentMethod()) {
                //     this.selectPaymentMethod();
                // }

                return this;
            },

            getPaymentMethodId: function()
            {
                if (this.isExternalPaymentMethodCode(this.selectedPaymentMethodCode))
                {
                    return this.selectedPaymentMethodCode;
                }

                return null;
            },

            getStripeParam: function(param)
            {
                var params = this.getInitParams();

                if (!params)
                {
                    return null;
                }

                if (typeof params[param] != "undefined")
                {
                    return params[param];
                }

                return null;
            },

            onQuoteTotalsChanged: function()
            {
                if (!this.elements || !this.elements.update)
                {
                    return;
                }

                var self = this;
                return this.elements.update(this.getElementsOptions());
            },

            getInitParams: function()
            {
                return window.checkoutConfig.payment.stripe_payments.initParams;
            },

            getPaymentMethodConfiguration: function()
            {
                var elementOptions = this.config().elementOptions || {};
                return elementOptions.paymentMethodConfiguration || null;
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);
                stripe.initStripe(this.getInitParams(), function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initPaymentForm();
                    self.isInitializing(false);
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            crash: function(message)
            {
                this.isLoading(false);
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.permanentError(userError);
                else
                    this.permanentError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: ", message);
            },

            softCrash: function(message)
            {
                var userError = this.getStripeParam("userError");
                if (userError)
                    this.showError(userError);
                else
                    this.showError($t("Sorry, this payment method is not available. Please contact us for assistance."));

                console.error("Error: ", message);
            },

            isCollapsed: function()
            {
                if (this.isChecked() == this.getCode())
                {
                    return false;
                }
                else
                {
                    return true;
                }
            },

            initPaymentForm: function()
            {
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-payment-element') === null)
                    return this.crash("Cannot initialize Payment Element on a DOM that does not contain a div.stripe-payment-element.");

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                if (this.getStripeParam("isOrderPlaced"))
                    this.isOrderPlaced(true);

                try
                {
                    this.elements = stripe.stripeJs.elements(this.getElementsOptions());
                    var options = this.getPaymentElementOptions();
                    this.paymentElement = this.elements.create('payment', options);
                    this.paymentElement.mount('#stripe-payment-element');
                    this.paymentElement.on('change', this.onChange.bind(this));
                    this.paymentElement.on('loaderror', this.onLoadError.bind(this));
                }
                catch (e)
                {
                    this.crash(e.message);
                }
            },

            onLoadError: function(event)
            {
                if (event && event.error && event.error.message)
                {
                    if (event.error.message.indexOf("No valid payment method types") >= 0)
                    {
                        this.disablePaymentMethod();
                        console.warn("[Stripe][" + (this.getPaymentMethodConfiguration() || 'none') + "] " + event.error.message);
                    }
                    else
                    {
                        this.permanentError(event.error.message);
                    }
                }
                else
                {
                    this.crash(event);
                }
            },

            disablePaymentMethod: function()
            {
                var methodCode = this.getCode();

                if (quote.paymentMethod() && quote.paymentMethod().method === methodCode)
                {
                    selectPaymentMethodAction(null);
                }

                methodList.remove(function (method)
                {
                    return method.method === methodCode;
                });
            },

            shouldHideRedirectBasedPaymentMethods: function()
            {
                var totals = quote.totals();
                if (!totals || !totals.total_segments || !totals.total_segments.length)
                {
                    return false;
                }

                var targetSegments = ["giftcardaccount", "customerbalance", "reward"];

                for (var i = 0; i < totals.total_segments.length; i++)
                {
                    var segment = totals.total_segments[i];
                    if (targetSegments.indexOf(segment.code) >= 0 && !isNaN(segment.value) && segment.value != 0)
                    {
                        return true;
                    }
                }

                return false;
            },

            getElementsOptions: function()
            {
                var options = Object.assign({}, window.checkoutConfig.payment.stripe_payments.elementOptions);

                if (this.shouldHideRedirectBasedPaymentMethods())
                {
                    options.paymentMethodTypes = ['card'];

                    // Unset any previously set paymentMethodConfiguration
                    options.paymentMethodConfiguration = null;
                }
                else if (options.paymentMethodTypes)
                {
                    // Case where paymentMethodTypes were passed from the server side
                }
                else
                {
                    // Unset any previously set paymentMethodTypes in case a gift card was removed etc
                    options.paymentMethodTypes = null;
                }

                if (options.paymentMethodTypes && options.paymentMethodTypes !== null)
                {
                    // When paymentMethodTypes is set, do not apply PMCs
                    if (options.paymentMethodConfiguration && options.paymentMethodConfiguration !== null)
                    {
                        delete options.paymentMethodConfiguration;
                    }
                }

                if (options.mode != "setup")
                {
                    options.amount = this.getElementsAmount();
                }

                var externalPaymentMethods = this.getExternalPaymentMethods();
                if (externalPaymentMethods)
                {
                    options.externalPaymentMethodTypes = externalPaymentMethods.map(function(method)
                    {
                        return method.code;
                    });
                }

                return options;
            },

            getExternalPaymentMethods: function()
            {
                var initParams = this.getInitParams();

                if (initParams &&
                    initParams.externalPaymentMethods &&
                    initParams.externalPaymentMethods.length > 0 &&
                    initParams.externalPaymentMethods[0].code &&
                    initParams.externalPaymentMethods[0].redirect_url
                )
                {
                    return initParams.externalPaymentMethods;
                }

                return null;
            },

            getPaymentElementOptions: function()
            {
                var options = {};

                var params = this.getInitParams();
                if (params)
                {
                    if (params.wallets)
                        options.wallets = params.wallets;

                    if (params.layout)
                        options.layout = params.layout;

                    if (params.terms)
                        options.terms = params.terms;
                }

                var billingAddress = quote.billingAddress();

                if (billingAddress)
                {
                    try
                    {
                        this.useQuoteBillingAddress(true);

                        var hasState = (billingAddress.region || billingAddress.regionCode || billingAddress.regionId);

                        options.fields = {
                            billingDetails: {
                                name: 'never',
                                email: 'never',
                                phone: 'auto',
                                address: {
                                    line1: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    line2: ((billingAddress.street.length > 0) ? 'never' : 'auto'),
                                    city: billingAddress.city ? 'never' : 'auto',
                                    state: hasState ? 'never' : 'auto',
                                    country: billingAddress.countryId ? 'never' : 'auto',
                                    postalCode: billingAddress.postcode ? 'never' : 'auto'
                                }
                            }
                        };
                    }
                    catch (e)
                    {
                        this.useQuoteBillingAddress(false);

                        options.fields = {};
                        console.warn('Could not retrieve billing address: '  + e.message);
                    }

                    // Set the default billing address in order to enable the Link payment method
                    var billingDetails = this.getBillingDetails();

                    if (billingDetails)
                    {
                        options.defaultValues = {
                            billingDetails: billingDetails
                        };
                    }
                }
                else
                {
                    this.useQuoteBillingAddress(false);
                }

                return options;
            },

            getPaymentElementUpdateOptions: function()
            {
                var options = this.getPaymentElementOptions();

                if (options.wallets)
                {
                    delete options.wallets;
                }

                return options;
            },

            onChange: function(event)
            {
                this.isLoading(false);

                if (event.value && event.value.type)
                {
                    this.selectedPaymentMethodCode = event.value.type;
                }
                else
                {
                    this.selectedPaymentMethodCode = null;
                }
            },

            isExternalPaymentMethodCode: function(code)
            {
                var methods = this.getExternalPaymentMethods();
                if (!methods)
                    return false;

                for (var i = 0; i < methods.length; i++)
                {
                    if (methods[i].code == code)
                        return true;
                }

                return false;
            },

            getExternalPaymentMethodRedirectUrl: function(code)
            {
                var methods = this.getExternalPaymentMethods();
                if (!methods)
                    return null;

                for (var i = 0; i < methods.length; i++)
                {
                    if (methods[i].code == code)
                        return methods[i].redirect_url;
                }

                return null;
            },

            getElementsAmount: function()
            {
                var totals = quote.totals();

                if (totals && totals.grand_total)
                {
                    // If total_segments includes a grand_total, we use that instead
                    if (totals.total_segments && totals.total_segments.length > 0)
                    {
                        for (var i = 0; i < totals.total_segments.length; i++)
                        {
                            var segment = totals.total_segments[i];
                            if (segment.code == "grand_total")
                            {
                                return this.convertToStripeAmount(segment.value, this.getElementsCurrency());
                            }
                        }
                    }

                    // Othewise use the quote grand total
                    var amount = totals.grand_total;
                    return this.convertToStripeAmount(amount, this.getElementsCurrency());
                }

                return 0;
            },

            getElementsCurrency: function()
            {
                var totals = quote.totals();
                if (totals && totals.quote_currency_code)
                {
                    var currency = totals.quote_currency_code;
                    return currency.toLowerCase();
                }

                return 'USD';
            },

            isBillingAddressSet: function()
            {
                return quote.billingAddress() && quote.billingAddress().canUseForBilling();
            },

            convertToStripeAmount: function(amount, currencyCode)
            {
                var code = currencyCode.toUpperCase();

                if (this.zeroDecimalCurrencies.indexOf(code) >= 0)
                {
                    return Math.round(amount);
                }
                else if (this.threeDecimalCurrencies.indexOf(code) >= 0)
                {
                    return Math.round(amount * 100) * 10;
                }
                else
                {
                    return Math.round(amount * 100);
                }
            },

            isPlaceOrderEnabled: function()
            {
                if (this.stripePaymentsError())
                    return false;

                if (this.permanentError())
                    return false;

                if (!this.isPlaceOrderActionAllowed())
                    return false;

                return this.isBillingAddressSet();
            },

            getAddressField: function(field)
            {
                if (!quote.billingAddress())
                    return null;

                var address = quote.billingAddress();

                if (!address[field] || address[field].length == 0)
                    return null;

                return address[field];
            },

            getBillingDetails: function()
            {
                var details = {};
                var address = {};

                if (this.getAddressField('city'))
                    address.city = this.getAddressField('city');

                if (this.getAddressField('countryId'))
                    address.country = this.getAddressField('countryId');

                if (this.getAddressField('postcode'))
                    address.postal_code = this.getAddressField('postcode');

                if (this.getAddressField('region'))
                    address.state = this.getAddressField('region');

                if (this.getAddressField('street'))
                {
                    var street = this.getAddressField('street');
                    address.line1 = street[0];

                    if (street.length > 1)
                        address.line2 = street[1];
                }

                if (Object.keys(address).length > 0)
                    details.address = address;

                if (this.getAddressField('telephone'))
                    details.phone = this.getAddressField('telephone');

                if (this.getAddressField('firstname'))
                    details.name = this.getAddressField('firstname') + ' ' + this.getAddressField('lastname');

                var email = this.getBillingEmail();
                if (email)
                    details.email = email;

                if (Object.keys(details).length > 0)
                    return details;

                return null;
            },

            config: function()
            {
                return window.checkoutConfig.payment[this.getCode()];
            },

            isActive: function(parents)
            {
                return true;
            },

            placeOrder: function()
            {
                this.messageContainer.clear();

                if (!this.validate())
                    return;

                this.clearErrors();
                this.isPlaceOrderActionAllowed(false);
                this.isLoading(true);

                var params = { };

                if (this.useQuoteBillingAddress())
                {
                    params.payment_method_data = {
                        billing_details: {
                            address: this.getStripeFormattedAddress(quote.billingAddress()),
                            email: this.getBillingEmail(),
                            name: this.getNameFromAddress(quote.billingAddress()),
                            phone: this.getBillingPhone()
                        }
                    };
                }

                if (this.hasShipping())
                {
                    params.shipping = {
                        address: this.getStripeFormattedAddress(quote.shippingAddress()),
                        name: this.getNameFromAddress(quote.shippingAddress())
                    };
                }

                if (this.isExternalPaymentMethodCode(this.selectedPaymentMethodCode))
                {
                    this.onPaymentMethodCreatedForOrderPlacement();
                }
                else
                {
                    this.createPaymentMethod(this.onPaymentMethodCreatedForOrderPlacement.bind(this));
                }

                return false;
            },

            hasShipping: function()
            {
                return (quote && quote.shippingMethod() && quote.shippingMethod().method_code);
            },

            createPaymentMethod: function(callback)
            {
                this.confirmationToken(null);

                var self = this;

                var confirmationTokenData = {
                    elements: this.elements,
                    params: {
                        payment_method_data: {
                            billing_details: null,
                            allow_redisplay: 'always'
                        }
                    }
                };

                var confirmParams = this.getConfirmParams();
                var billingDetails = null;
                if (confirmParams &&
                    confirmParams.confirmParams &&
                    confirmParams.confirmParams.payment_method_data &&
                    confirmParams.confirmParams.payment_method_data.billing_details
                )
                {
                    billingDetails = confirmParams.confirmParams.payment_method_data.billing_details;
                }

                if (billingDetails)
                {
                    confirmationTokenData.params.payment_method_data.billing_details = billingDetails;
                }
                else
                {
                    return this.showError($t("Please specify a billing address."));
                }

                // Add shipping details under params if available
                if (this.hasShipping())
                {
                    confirmationTokenData.params.shipping = {
                        address: this.getStripeFormattedAddress(quote.shippingAddress()),
                        name: this.getNameFromAddress(quote.shippingAddress())
                    };
                }

                setTimeout(function(){
                    // The loading mask is disabled so that the MFTF tests can continue running
                    self.isLoading(false);
                }, 4000);

                this.elements.submit().then(function()
                {
                    self.createConfirmationToken(confirmationTokenData, function()
                    {
                        // The phone number from the billing address is not valid for this payment method.
                        // Retry once without the phone so that the payment method can collect the correct one.
                        var retryData = Object.assign({}, confirmationTokenData);
                        retryData.params = JSON.parse(JSON.stringify(confirmationTokenData.params));
                        delete retryData.params.payment_method_data.billing_details.phone;

                        self.createConfirmationToken(retryData, null, callback);
                    }, callback);
                },
                function(result)
                {
                    if (result.error)
                    {
                        self.showError(result.error.message);
                        console.error(result.error.message);
                    }
                    else
                    {
                        self.showError("A payment submission error has occurred.");
                        console.error(result);
                    }
                });
            },

            createConfirmationToken: function(confirmationTokenData, onInvalidPhoneNumberCallback, onSuccessCallback)
            {
                var self = this;

                stripe.stripeJs.createConfirmationToken(confirmationTokenData).then(function(result)
                {
                    if (result.error)
                    {
                        if (result.error.code === "phone_number_invalid" &&
                            confirmationTokenData.params.payment_method_data.billing_details &&
                            confirmationTokenData.params.payment_method_data.billing_details.phone &&
                            onInvalidPhoneNumberCallback)
                        {
                            onInvalidPhoneNumberCallback();
                        }
                        else
                        {
                            self.showError(result.error.message);
                            console.error(result.error.message);
                        }
                    }
                    else if (result.confirmationToken)
                    {
                        // Read the phone that Stripe resolved from the Elements
                        // (e.g. MB Way may have collected a corrected phone number)
                        var previewPhone = result.confirmationToken.payment_method_preview &&
                            result.confirmationToken.payment_method_preview.billing_details &&
                            result.confirmationToken.payment_method_preview.billing_details.phone;
                        if (previewPhone)
                        {
                            var billingAddress = quote.billingAddress();
                            if (billingAddress && billingAddress.telephone !== previewPhone)
                            {
                                billingAddress.telephone = previewPhone;
                            }
                        }

                        self.confirmationToken(result.confirmationToken);
                        onSuccessCallback(result.confirmationToken);
                    }
                    else
                    {
                        self.showError("Failed to create confirmation token.");
                        console.error(result);
                    }
                }).catch(function(error) {
                    self.showError("An error has occurred while creating confirmation token.");
                    console.error('Confirmation token creation error: ', error.message);
                });
            },

            onPaymentMethodCreatedForOrderPlacement: function(paymentMethod)
            {
                var placeNewOrder = this.placeNewOrder.bind(this);
                var self = this;

                if (self.isOrderPlaced()) // The order was already placed but either 3D Secure failed or the customer pressed the back button from an external payment page
                {
                    updateCartAction(this.getData(), this.onCartUpdated.bind(this));
                }
                else
                {
                    try
                    {
                        placeNewOrder();
                    }
                    catch (e)
                    {
                        self.showError($t("The order could not be placed. Please contact us for assistance."));
                        console.error(e.message);
                    }
                }
            },

            onCartUpdated: function(result, outcome, response)
            {
                var placeNewOrder = this.placeNewOrder.bind(this);
                var onOrderPlaced = this.onOrderPlaced.bind(this);
                try
                {
                    var data = JSON.parse(result);
                    if (data.error)
                    {
                        this.showError(data.error);
                    }
                    else if (data.redirect)
                    {
                        $.mage.redirect(data.redirect);
                    }
                    else if (data.placeNewOrder)
                    {
                        placeNewOrder();
                    }
                    else
                    {
                        onOrderPlaced();
                    }
                }
                catch (e)
                {
                    this.showError($t("The order could not be placed. Please contact us for assistance."));
                    console.error(e.message);
                }
            },

            placeNewOrder: function()
            {
                var self = this;

                this.isPlaceOrderActionAllowed(false);
                this.isLoading(false); // Needed for the terms and conditions checkbox
                this.getPlaceOrderDeferredObject()
                    .fail(this.handlePlaceOrderErrors.bind(this))
                    .done(this.onOrderPlaced.bind(this))
                    .always(function(response, status, xhr)
                    {
                        if (status != "success")
                        {
                            self.isLoading(false);

                            if (response.responseJSON && response.responseJSON.message && response.responseJSON.message.indexOf("Authentication Required: ") < 0)
                            {
                                self.isPlaceOrderActionAllowed(true);
                            }
                        }
                    });
            },

            // Called when:
            // - A brand new order has just been placed
            // - After updateCartAction() with placeNewOrder == false
            onOrderPlaced: function(result, outcome, response)
            {
                if (!this.isOrderPlaced() && isNaN(result))
                {
                    return this.softCrash("The order was placed but the response from the server did not include a numeric order ID.");
                }
                else
                {
                    this.isOrderPlaced(true);
                }

                this.isLoading(true);

                if (this.isExternalPaymentMethodCode(this.selectedPaymentMethodCode))
                {
                    window.location.href = this.getExternalPaymentMethodRedirectUrl(this.selectedPaymentMethodCode);
                    return;
                }

                var self = this;
                getRequiresAction(function(clientSecret)
                {
                    try
                    {
                        if (clientSecret && clientSecret.length)
                        {
                            stripe.authenticateCustomer(clientSecret, function(err)
                            {
                                if (err)
                                    return self.showError(err);

                                self.onConfirm.bind(self)();
                            });
                        }
                        else
                        {
                            // No further actions are needed
                            self.onConfirm(null);
                        }
                    }
                    catch (e)
                    {
                        restoreQuoteAction();
                        self.showError("The order was placed but we could not confirm if the payment was successful.");
                        console.error(e);
                    }

                })
                .fail(function(result)
                {
                    restoreQuoteAction();
                    self.showError("The order was placed but we could not confirm if the payment was successful.");
                    console.error(result);
                });
            },

            isSuccessful: function(stripeObject)
            {

                if (stripeObject.status == "requires_action" &&
                    stripeObject.next_action &&
                    stripeObject.next_action.type &&
                    stripeObject.next_action.type != "use_stripe_sdk"
                )
                {
                    // This is the case for vouchers, where an offline payment is required
                    return true;
                }


                return (['processing', 'requires_capture', 'succeeded'].indexOf(stripeObject.status) >= 0);
            },

            getConfirmParams: function()
            {
                var params = {
                    elements: this.elements,
                    confirmParams: {
                        return_url: this.getStripeParam("successUrl")
                    }
                };

                this.getPaymentElementOptions();
                if (this.useQuoteBillingAddress())
                {
                    params.confirmParams.payment_method_data = {
                        billing_details: {
                            address: this.getStripeFormattedAddress(quote.billingAddress()),
                            email: this.getBillingEmail(),
                            name: this.getNameFromAddress(quote.billingAddress()),
                            phone: this.getBillingPhone()
                        }
                    };
                }

                return params;
            },

            getStripeFormattedAddress: function(address)
            {
                var stripeAddress = {};

                if (address.regionCode)
                    stripeAddress.state = address.regionCode;
                else
                    stripeAddress.state = address.region ? address.region : null;

                stripeAddress.postal_code = address.postcode ? address.postcode : null;
                stripeAddress.country = address.countryId ? address.countryId : null;
                stripeAddress.city = address.city ? address.city : null;

                if (address.street && address.street.length > 0)
                {
                    stripeAddress.line1 = address.street[0];

                    if (address.street.length > 1)
                    {
                        stripeAddress.line2 = address.street[1];
                    }
                    else
                    {
                        stripeAddress.line2 = null;
                    }
                }
                else
                {
                    stripeAddress.line1 = null;
                    stripeAddress.line2 = null;
                }

                return stripeAddress;
            },

            getBillingEmail: function()
            {
                if (quote.guestEmail)
                {
                    return quote.guestEmail;
                }
                else if (window.checkoutConfig.customerData && window.checkoutConfig.customerData.email)
                {
                    return window.checkoutConfig.customerData.email;
                }

                return null;
            },

            getNameFromAddress: function(address)
            {
                if (!address)
                    return null;

                var parts = [];
                if (address.firstname)
                    parts.push(address.firstname);

                if (address.middlename)
                    parts.push(address.middlename);

                if (address.lastname)
                    parts.push(address.lastname);

                return parts.join(" ");
            },

            getBillingPhone: function()
            {
                var billingAddress = quote.billingAddress();
                if (!billingAddress)
                    return null;

                if (billingAddress.telephone)
                    return billingAddress.telephone;

                return null;
            },

            onConfirm: function(result)
            {
                customerData.invalidate(['cart']);
                // In the case of a 3DS, we redirect to stripe/payment/index so that the quote is de-activated
                var successUrl = this.getStripeParam("successUrl");
                $.mage.redirect(successUrl);
            },

            /**
             * @return {*}
             */
            getPlaceOrderDeferredObject: function()
            {
                return placeOrderAction(this.getData(), this.messageContainer);
            },

            getClientSecretFromResponse: function(response)
            {
                if (typeof response != "string")
                {
                    return null;
                }

                if (response.indexOf("Authentication Required: ") >= 0)
                {
                    return response.substring("Authentication Required: ".length);
                }

                return null;
            },

            isInstalmentsSelection: function(response)
            {
                return response.indexOf("Select Installments") >= 0;
            },

            handlePlaceOrderErrors: function (result)
            {
                if (result && result.responseJSON && result.responseJSON.message)
                {
                    var self = this;

                    if (self.isInstalmentsSelection(result.responseJSON.message)) {
                        return getInstallmentPlans().done(function(result) {
                            var plans = JSON.parse(result);
                            self.installmentPlans(plans.details);
                            self.installmentsTotalLabel(plans.total_label);
                            ko.tasks.schedule(self.initModal.bind(self));
                        }).fail(function() {
                            self.selectedInstallmentPlan(-1);
                            self.placeNewOrder.bind(self)();
                        });
                    }

                    var clientSecret = this.getClientSecretFromResponse(result.responseJSON.message);

                    if (clientSecret)
                    {
                        return stripe.authenticateCustomer(clientSecret, function(err)
                        {
                            if (err)
                                return self.showError(err);

                            self.placeNewOrder.bind(self)();
                        });
                    }
                    else
                    {
                        this.showError(result.responseJSON.message);
                    }
                }
                else
                {
                    this.showError($t("The order could not be placed. Please contact us for assistance."));

                    if (result && result.responseText)
                        console.error(result.responseText);
                    else
                        console.error(result);
                }
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.isPlaceOrderActionAllowed(true);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            validate: function(elm)
            {
                return agreementValidator.validate() && additionalValidators.validate();
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {}
                };

                // Always send confirmation token
                if (this.confirmationToken())
                {
                    data.additional_data.confirmation_token = this.confirmationToken().id;
                }
                else
                {
                    // For saved payment methods or external payment methods
                    var paymentMethodId = this.getPaymentMethodId();
                    if (paymentMethodId)
                    {
                        data.additional_data.payment_method = paymentMethodId;
                    }
                }

                if (this.installmentPlans().length !== 0 && this.selectedInstallmentPlan() !== null) {
                    if (this.selectedInstallmentPlan() >= 0) {
                        data.additional_data.selected_installment_plan = JSON.stringify(this.installmentPlans()[this.selectedInstallmentPlan()].data);
                    } else {
                        data.additional_data.selected_installment_plan = this.selectedInstallmentPlan();
                    }
                }

                return data;
            },

            clearErrors: function()
            {
                this.stripePaymentsError(null);
            },

            initModal: function () {
                this.modalInstance = modal({
                    title: $t('Available installment plans'),
                    type: 'popup',
                    responsive: true,
                    modalClass: 'installments-modal stripe-element-font',
                    innerScroll: true,
                    buttons: [],
                    clickableOverlay: false,
                    closeButton: false,
                    escapeClose: false
                }, $('#installments_plan'));

                $('#installments_plan').modal('openModal');
            },

            closeModal: function () {
                $('#installments_plan').modal('closeModal');
            },

            submitModal: function () {
                if (this.selectedInstallmentPlan() >= 0) {
                    this.closeModal();
                    this.placeNewOrder.bind(this)();
                } else {
                    alert($t('Please select an installment plan'));
                }
            }

        });
    }
);
