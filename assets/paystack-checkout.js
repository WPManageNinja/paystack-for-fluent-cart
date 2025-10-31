class PaystackCheckout {
    #publicKey = null;
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.data = response;
        this.paymentLoader = paymentLoader;
        this.$t = this.translate.bind(this);
        this.submitButton = window.fluentcart_checkout_vars?.submit_button;
        this.#publicKey = response?.payment_args?.public_key;
    }

    translate(string) {
        const translations = window.fct_paystack_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="paystack-payment-info">';
        html += '<div class="paystack-payment-container">';
        html += '<div class="paystack-logo">';
        html += '<img src="https://paystack.com/assets/img/logos/paystack-logo-color.svg" alt="Paystack" />';
        html += '</div>';
        html += '<p class="paystack-description">' + this.$t('Pay securely with Paystack') + '</p>';
        html += '<div class="paystack-payment-methods">';
        html += '<span class="payment-badge">' + this.$t('Cards') + '</span>';
        html += '<span class="payment-badge">' + this.$t('Bank Transfer') + '</span>';
        html += '<span class="payment-badge">' + this.$t('USSD') + '</span>';
        html += '<span class="payment-badge">' + this.$t('QR Code') + '</span>';
        html += '</div>';
        html += '</div>';
        html += '</div>';
        
        // Add CSS styles
        html += `<style>
            .paystack-payment-info {
                padding: 16px;
                border: 1px solid #e1e5e9;
                border-radius: 8px;
                background: #fff;
                margin-bottom: 16px;
            }
            .paystack-payment-container {
                text-align: center;
            }
            .paystack-logo {
                margin-bottom: 12px;
            }
            .paystack-logo img {
                max-height: 40px;
                max-width: 150px;
            }
            .paystack-description {
                margin: 0 0 16px 0;
                font-size: 14px;
                color: #333;
                font-weight: 500;
            }
            .paystack-payment-methods {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                justify-content: center;
            }
            .payment-badge {
                display: inline-block;
                padding: 4px 8px;
                background: #0c7fdc;
                color: white;
                font-size: 12px;
                border-radius: 4px;
                font-weight: 500;
            }
            .paystack-payment-error {
                padding: 16px;
                border: 1px solid #dc3545;
                border-radius: 8px;
                background: #f8d7da;
                margin-bottom: 16px;
                color: #721c24;
                text-align: center;
            }
        </style>`;

        return html;
    }

    loadPaystackScript() {
        return new Promise((resolve, reject) => {
            if (typeof PaystackPop !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = 'https://js.paystack.co/v2/inline.js';
            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                reject(new Error('Failed to load Paystack script'));
            };
            document.head.appendChild(script);
        });
    }

    init() {
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const that = this;        
        const paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        if (paystackContainer) {
            paystackContainer.innerHTML = '';
        }

        // Hide payment methods
        const paymentMethods = this.form.querySelector('.fluent_cart_payment_methods');
        if (paymentMethods) {
            paymentMethods.style.display = 'none';
        }

        let intent = this.data?.intent;
        const publicKey = this.data?.payment_args?.public_key;

        // Handle both one-time payments and subscriptions
        // if (this.intent === 'subscription') {
        //     this.subscriptionPaymentHandler(that, this.data, orderData, paystackContainer);
        // } else {
        //     this.onetimePaymentHandler(that, this.data, orderData, paystackContainer);
        // }

        window.addEventListener("fluent_cart_payment_next_action_paystack", async(e) => {
                // that.paymentLoader?.changeLoaderStatus('processing');
                // const loaderElement = document.querySelector('.fc-loader');
                // loaderElement?.classList?.add('active');

            const remoteResponse = e.detail?.response;
           
            const access_code = remoteResponse?.data?.paystack_data?.access_code;
            const authorizationUrl = remoteResponse?.data?.paystack_data?.authorization_url;
            const intent = remoteResponse?.data?.intent;

             if (access_code && authorizationUrl) {
                // remove or hide loader
                this.paymentLoader.hideLoader();
                // load paystack popup
                if (intent === 'onetime') {
                    this.onetimePaymentHandler(access_code, authorizationUrl);
                } else if (intent === 'subscription') {
                    this.paystackSubscriptionPayment(access_code, authorizationUrl);
                }
             }
               
        });
    }

    async onetimePaymentHandler(access_code, authorizationUrl) {
         try {
            await this.loadPaystackScript();
        } catch (error) {
            console.error('Paystack script failed to load:', error);
            this.handlePaystackError(error);
            return;
        }

        try {
            const popup = new PaystackPop();
            popup.resumeTransaction(access_code);
            
        } catch (error) {
            console.error('Error resuming Paystack popup:', error);
            this.handlePaystackError(error);
        }

    }

    handlePaystackError(err) {
        let errorMessage = this.$t('An unknown error occurred');

        if (err?.message) {
            try {
                const jsonMatch = err.message.match(/{.*}/s);
                if (jsonMatch) {
                    errorMessage = JSON.parse(jsonMatch[0]).message || errorMessage;
                } else {
                    errorMessage = err.message;
                }
            } catch {
                errorMessage = err.message || errorMessage;
            }
        }
        
        // TODO: handle error
        console.error('Paystack error:', errorMessage);
    }

}

window.addEventListener("fluent_cart_load_payments_paystack", function (e) {
    const translate = window.fluentcart.$t;
    addLoadingText();
    fetch(e.detail.paymentInfoUrl, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-WP-Nonce": e.detail.nonce,
        },
        credentials: 'include'
    }).then(async (response) => {
        response = await response.json();
        if (response?.status === 'failed') {
            displayErrorMessage(response?.message);
            this.paymentLoader.disableCheckoutButton(this.translate(this.submitButton.text));
        }
        new PaystackCheckout(e.detail.form, e.detail.orderHandler, response, e.detail.paymentLoader).init();
    }).catch(error => {
        const translations = window.fct_paystack_data?.translations || {};
        function $t(string) {
            return translations[string] || string;
        }
        let message = error?.message || $t('An error occurred while loading paystack.');
        displayErrorMessage(message);
    });

    function displayErrorMessage(message) {
        const errorDiv = document.createElement('div');
        errorDiv.style.color = 'red';
        errorDiv.className = 'fc-error-message';
        errorDiv.textContent = message;

        const paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        if (paystackContainer) {
            paystackContainer.appendChild(errorDiv);
        }

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
        return;
    }

    function addLoadingText() {
        let paystackButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        if (paystackButtonContainer) {
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            const translations = window.fct_paystack_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            paystackButtonContainer.appendChild(loadingMessage);
        }
    }
});


