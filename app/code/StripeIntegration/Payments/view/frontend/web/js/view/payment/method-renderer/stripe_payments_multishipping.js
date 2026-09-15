define(
    [
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'StripeIntegration_Payments/js/stripe',
        'mage/translate'
    ],
    function (
        Component,
        quote,
        stripe,
        $t
    ) {
        'use strict';

        return Component.extend({
            externalRedirectUrl: null,
            defaults: {
                template: 'StripeIntegration_Payments/payment/element',
                stripePaymentsShowApplePaySection: false
            },
            elements: null,
            initParams: null,
            paymentElement: null,
            zeroDecimalCurrencies: ['BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF'],

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
                        'useQuoteBillingAddress'
                    ]);

                var self = this;

                this.isOrderPlaced(false);
                this.isInitializing(true);
                this.useQuoteBillingAddress(false);

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
                    if (method.method == this.getCode() && !this.isInitializing())
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

                return this;
            },

            selectPaymentMethod: function()
            {
                this._super();
                return true;
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
                return this.elements.update(this.getElementsOptions(true)).catch(function (e)
                {
                    console.warn("Could not update Stripe elements with filtered methods: " + (e.message || e));
                    return self.elements.update(self.getElementsOptions(false)).catch(function (e2)
                    {
                        console.error("Could not update Stripe elements: " + (e2.message || e2));
                    });
                });
            },

            getInitParams: function()
            {
                return window.checkoutConfig.payment.stripe_payments.initParams;
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

                console.error("Error: " + message);
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
                this.isInitializing(false);
                this.isLoading(false);

                if (this.isCollapsed()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-payment-element') === null)
                    return;

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                if (this.getStripeParam("isOrderPlaced"))
                    this.isOrderPlaced(true);

                try
                {
                    try
                    {
                        this.elements = stripe.stripeJs.elements(this.getElementsOptions(true));
                    }
                    catch (e)
                    {
                        console.warn("Could not filter Stripe payment method types: " + e.message);
                        this.elements = stripe.stripeJs.elements(this.getElementsOptions(false));
                    }
                    this.paymentElement = this.elements.create('payment', this.getPaymentElementOptions());
                    this.paymentElement.mount('#stripe-payment-element');
                    this.paymentElement.on('change', this.onChange.bind(this));
                }
                catch (e)
                {
                    this.crash(e.message);
                }
            },

            getElementsOptions: function(filterPaymentMethods)
            {
                var options = window.checkoutConfig.payment.stripe_payments.elementOptions;

                if (!filterPaymentMethods && options.payment_method_types)
                    delete options.payment_method_types;

                if (options.mode != "setup")
                {
                    options.amount = this.getElementsAmount();
                    options.currency = this.getElementsCurrency();
                }

                return options;
            },

            getPaymentElementOptions: function()
            {
                var options = {};

                var params = this.getInitParams();
                if (params && typeof params.wallets != "undefined" && params.wallets)
                    options.wallets = params.wallets;

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
                                phone: (billingAddress.telephone ? 'never' : 'auto'),
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

                if (params.layout)
                {
                    options.layout = params.layout;
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
            },

            getElementsAmount: function()
            {
                var totals = quote.totals();

                if (totals && totals.grand_total)
                {
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
                else
                {
                    return Math.round(amount * 100);
                }
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

            getStripeFormattedAddress: function(address)
            {
                var stripeAddress = {};

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

            showError: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addErrorMessage({ "message": message });
            },

            getCode: function()
            {
                return 'stripe_payments';
            },

        });
    }
);
