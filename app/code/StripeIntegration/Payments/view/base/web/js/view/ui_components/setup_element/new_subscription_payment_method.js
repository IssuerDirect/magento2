define(
    [
        'StripeIntegration_Payments/js/view/ui_components/setup_element',
        'StripeIntegration_Payments/js/action/add-payment-method',
        'StripeIntegration_Payments/js/action/change-subscription-payment-method',
        'StripeIntegration_Payments/js/stripe',
        'jquery',
        'Magento_Ui/js/modal/modal',
        'mage/translate'
    ],
    function (
        component,
        addPaymentMethodAction,
        changeSubscriptionPaymentMethodAction,
        stripe,
        $,
        modal,
        $t
    ) {
        'use strict';

        return component.extend({
            defaults: {
                template: 'StripeIntegration_Payments/setup_element/new_subscription_payment_method',
                modalSelector: '#stripe-subscription-add-payment-modal',
                triggerSelector: '.stripe-add-payment-method-link'
            },

            initialize: function ()
            {
                this._super();
                this.initModal();
                return this;
            },

            initModal: function ()
            {
                var self = this;
                var $modalElement = $(this.modalSelector);

                if (!$modalElement.length) {
                    return;
                }

                modal({
                    type: 'popup',
                    title: $t('Change subscription payment method'),
                    responsive: true,
                    innerScroll: true,
                    buttons: [],
                    modalClass: 'subscriptions-new-payment-modal stripe-element-font'
                }, $modalElement);

                $(document).on('click', this.triggerSelector, function (e) {
                    e.preventDefault();
                    self.subscriptionId = $(this).data('subscription-id');
                    $modalElement.modal('openModal');
                });
            },

            getPaymentElementCreateOptions: function()
            {
                return {
                    layout: this.getStripeParam('layout'),
                    wallets: {
                        link: 'never'
                    }
                };
            },

            clearFormData: function()
            {
                this.paymentElement.clear();
                $(this.modalSelector).modal('closeModal');
                window.location.href = this.getStripeParam('returnUrl');
            },

            onCancel: function()
            {
                this.paymentElement.clear();
                $(this.modalSelector).modal('closeModal');
            },

            setNewPaymentMethods: function(newMethods, isDuplicate, method)
            {
                var self = this;
                changeSubscriptionPaymentMethodAction(self.subscriptionId, method.id, function (response, status) {
                    if (status === 'success') {
                        var result = JSON.parse(response);
                        if (result.hasOwnProperty('success') && result.success) {
                            self.clearFormData();
                        } else {
                            self.showError($t("The payment method could not be added to the subscription."));
                        }
                    } else {
                        if (response && response.responseJSON && response.responseJSON.message) {
                            self.showError(response.responseJSON.message);
                        } else {
                            console.warn(response);
                            self.showError($t("An error occurred while adding the payment method to the subscription."));
                        }
                    }
                });
            }
        });
    }
);
