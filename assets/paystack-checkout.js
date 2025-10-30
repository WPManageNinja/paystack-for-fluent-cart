/**
 * Paystack Checkout Handler for FluentCart
 */
(function($) {
    'use strict';

    window.FluentCartPaystack = {
        /**
         * Initialize Paystack checkout
         */
        init: function(paymentArgs, formElement) {
            const self = this;
            const paystackData = paymentArgs.paystack_data || {};
            const publicKey = window.fct_paystack_data?.public_key;

            if (!publicKey) {
                console.error('Paystack public key not found');
                return;
            }

            // Load Paystack inline script if not already loaded
            if (typeof PaystackPop === 'undefined') {
                this.loadPaystackScript(function() {
                    self.openPaystackPopup(paystackData, publicKey, formElement);
                });
            } else {
                this.openPaystackPopup(paystackData, publicKey, formElement);
            }
        },

        /**
         * Load Paystack inline script
         */
        loadPaystackScript: function(callback) {
            const script = document.createElement('script');
            script.src = 'https://js.paystack.co/v1/inline.js';
            script.onload = callback;
            script.onerror = function() {
                console.error('Failed to load Paystack script');
            };
            document.head.appendChild(script);
        },

        /**
         * Open Paystack payment popup
         */
        openPaystackPopup: function(paystackData, publicKey, formElement) {
            const handler = PaystackPop.setup({
                key: publicKey,
                email: paystackData.email,
                amount: paystackData.amount,
                currency: paystackData.currency,
                ref: paystackData.reference,
                metadata: paystackData.metadata,
                callback: function(response) {
                    // Payment successful
                    window.location.href = paystackData.callback_url + '&reference=' + response.reference;
                },
                onClose: function() {
                    // User closed the popup
                    console.log('Payment cancelled');
                    // Re-enable submit button if needed
                    if (formElement) {
                        const submitButton = formElement.querySelector('[type="submit"]');
                        if (submitButton) {
                            submitButton.disabled = false;
                        }
                    }
                }
            });

            handler.openIframe();
        }
    };

    // Register with FluentCart payment system
    if (window.fluentCartCheckout) {
        window.fluentCartCheckout.registerPaymentHandler('paystack', function(paymentArgs, formElement) {
            window.FluentCartPaystack.init(paymentArgs, formElement);
        });
    }

})(jQuery);

