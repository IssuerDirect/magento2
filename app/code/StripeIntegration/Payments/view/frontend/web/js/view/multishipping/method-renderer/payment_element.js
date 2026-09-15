define(
    [
        'ko',
        'StripeIntegration_Payments/js/view/payment/method-renderer/stripe_payments_multishipping',
        'StripeIntegration_Payments/js/stripe',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/action/set-payment-information',
        'mage/translate',
        'jquery',
        'Magento_Ui/js/model/messages',
        'uiLayout',
        'domReady!'
    ],
    function (
        ko,
        Component,
        stripe,
        quote,
        setPaymentInformationAction,
        $t,
        $,
        messagesModel,
        layout
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/multishipping/payment_element',
                continueSelector: '#payment-continue',
                cardElement: null,
                confirmationToken: ko.observable(null),
                params: null,
                captureMethod: 'automatic'
            },

            initObservable: function ()
            {
                this._super();

                $(this.continueSelector).on("click", this.onContinue.bind(this));

                this.messageContainer = new messagesModel();

                var messagesComponent = {
                    parent: this.name,
                    name: this.name + '.messages',
                    displayArea: 'messages',
                    component: 'Magento_Ui/js/view/messages',
                    config: {
                        messageContainer: this.messageContainer,
                        autoHideTimeOut: -1
                    }
                };

                layout([messagesComponent]);

                return this;
            },

            onPaymentElementContainerRendered: function()
            {
                var self = this;
                this.isLoading(true);

                this.params = window.initParams;

                stripe.initStripe(this.params, function(err)
                {
                    if (err)
                        return self.crash(err);

                    self.initPaymentForm.bind(self)();
                });
            },

            onContainerRendered: function()
            {
                this.onPaymentElementContainerRendered();
            },

            selectPaymentMethod: function()
            {
                this._super();
                this.initPaymentForm();
                return true;
            },

            initPaymentForm: function()
            {
                this.isLoading(false);

                if (!this.isChecked() && !this.isActive()) // Don't render PE with a height of 0
                    return;

                if (document.getElementById('stripe-payment-element') === null)
                    return;

                if (!stripe.stripeJs)
                    return this.crash("Stripe.js could not be initialized.");

                try
                {
                    var elementOptions = this.getElementsOptions(true);
                    if (this.params && this.params.setupFutureUsage)
                        elementOptions.setupFutureUsage = this.params.setupFutureUsage;
                    elementOptions.captureMethod = this.captureMethod;
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
            },

            onSetPaymentMethodFail: function(result)
            {
                this.confirmationToken(null);
                this.isLoading(false);
                console.error(result);
            },

            onContinue: function(e)
            {
                // If we already have a confirmation token, don't do anything
                if (this.confirmationToken())
                    return;

                var self = this;

                if (!this.isStripeMethodSelected())
                    return;

                e.preventDefault();
                e.stopPropagation();

                this.isLoading(true);

                this.createConfirmationToken(function()
                {
                    $(self.continueSelector).trigger("click");
                });
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

            createConfirmationToken: function(onSuccessCallback)
            {
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

                this.elements.submit().then(function() {
                    stripe.stripeJs.createConfirmationToken(confirmationTokenData).then(function(result)
                    {
                        if (result.error)
                        {
                            self.showError(result.error.message);
                            console.error(result.error.message);
                        }
                        else if (result.confirmationToken)
                        {
                            self.confirmationToken(result.confirmationToken);

                            setPaymentInformationAction(self.messageContainer, self.getData())
                                .then(function() { onSuccessCallback(); })
                                .fail(self.onSetPaymentMethodFail.bind(self));
                        }
                        else
                        {
                            self.showError("Failed to create confirmation token.");
                            console.error(result);
                        }
                    }).catch(function(error) {
                        self.showError(error.message);
                        console.error('Confirmation token creation error: ', error.message);
                    });
                });

            },

            getData: function()
            {
                var data = {
                    'method': this.item.method,
                    'additional_data': {}
                };

                if (this.confirmationToken())
                {
                    data.additional_data.confirmation_token = this.confirmationToken().id;
                }

                return data;
            },

            showError: function(message)
            {
                this.isLoading(false);
                this.messageContainer.addErrorMessage({
                    message: message
                });
            },

            isStripeMethodSelected: function()
            {
                var methods = $('[name^="payment["]');

                if (methods.length === 0)
                    return false;

                var stripe = methods.filter(function(index, value)
                {
                    if (value.id == "p_method_stripe_payments")
                        return value;
                });

                if (stripe.length == 0)
                    return false;

                return stripe[0].checked;
            }
        });
    }
);
