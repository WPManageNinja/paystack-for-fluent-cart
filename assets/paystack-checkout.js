class PaystackCheckout {
    #cdnUrl = 'https://js.paystack.co/v2/inline.js';
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

     init() {
        this.paymentLoader.enableCheckoutButton(this.translate(this.submitButton.text));
        const that = this;        
        const paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        if (paystackContainer) {
            paystackContainer.innerHTML = '';
        }

        this.renderPaymentInfo();


        this.#publicKey = this.data?.payment_args?.public_key;

        window.addEventListener("fluent_cart_payment_next_action_paystack", async(e) => {

            const remoteResponse = e.detail?.response;           
            const access_code = remoteResponse?.data?.paystack_data?.access_code;
            const authorizationUrl = remoteResponse?.data?.paystack_data?.authorization_url;
            const intent = remoteResponse?.data?.intent;

             if (access_code && authorizationUrl) {
                // this.paymentLoader.hideLoader();
                if (intent === 'onetime') {
                    this.onetimePaymentHandler(access_code, authorizationUrl);
                } else if (intent === 'subscription') {
                    this.paystackSubscriptionPayment(access_code, authorizationUrl);
                }
             }
               
        });
    }

    translate(string) {
        const translations = window.fct_paystack_data?.translations || {};
        return translations[string] || string;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-paystack-info">';
        
        // Simple header
        html += '<div class="fct-paystack-header">';
        html += '<p class="fct-paystack-subheading">' + this.$t('Available payment methods on Checkout') + '</p>';
        html += '</div>';
        
        // Payment methods
        html += '<div class="fct-paystack-methods">';
        html += '<div class="fct-paystack-method">';
        html += '<span class="fct-method-name">' + this.$t('Cards') + '</span>';
        html += '</div>';
        html += '<div class="fct-paystack-method">';
        html += '<span class="fct-method-name">' + this.$t('Bank Transfer') + '</span>';
        html += '</div>';
        html += '<div class="fct-paystack-method">';
        html += '<span class="fct-method-name">' + this.$t('USSD') + '</span>';
        html += '</div>';
        html += '<div class="fct-paystack-method">';
        html += '<span class="fct-method-name">' + this.$t('QR Code') + '</span>';
        html += '</div>';
        html += '<div class="fct-paystack-method">';
        html += '<span class="fct-method-name">' + this.$t('PayAttitude') + '</span>'; 
        html += '</div>';
        html += '</div>';
        
        html += '</div>';
        
        // Add CSS styles
        html += `<style>
            .fct-paystack-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 20px;
            }
            
            .fct-paystack-header {
                text-align: center;
                margin-bottom: 16px;
            }
            
            .fct-paystack-heading {
                margin: 0 0 4px 0;
                font-size: 18px;
                font-weight: 600;
                color: #0c7fdc;
            }
            
            .fct-paystack-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }
            
            .fct-paystack-methods {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
                gap: 10px;
            }
            
            .fct-paystack-method {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 10px;
                background: white;
                border: 1px solid #ddd;
                border-radius: 6px;
                transition: all 0.2s ease;
                cursor: text;
            }
            
            .fct-method-name {
                font-size: 12px;
                font-weight: 500;
                color: #333;
            }
            
            @media (max-width: 768px) {
                .fct-paystack-info {
                    padding: 16px;
                }
                
                .fct-paystack-heading {
                    font-size: 16px;
                }
                
                .fct-paystack-methods {
                    grid-template-columns: repeat(2, 1fr);
                    gap: 8px;
                }
                
                .fct-paystack-method {
                    padding: 8px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        container.innerHTML = html;
    }

    loadPaystackScript() {
        return new Promise((resolve, reject) => {
            if (typeof PaystackPop !== 'undefined') {
                resolve();
                return;
            }

            const script = document.createElement('script');
            script.src = this.#cdnUrl;
            script.onload = () => {
                resolve();
            };
            script.onerror = () => {
                reject(new Error('Failed to load Paystack script'));
            };

            document.head.appendChild(script);
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
            
            // Setup event listeners for Paystack transaction lifecycle
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel();
                },
                onError: (error) => {
                    this.handlePaystackError(error);
                }
            });
            
        } catch (error) {
            console.error('Error resuming Paystack popup:', error);
            this.handlePaystackError(error);
        }
    }

    async paystackSubscriptionPayment(access_code, authorizationUrl) {
        try {
            await this.loadPaystackScript();
        } catch (error) {
            console.error('Paystack script failed to load:', error);
            this.handlePaystackError(error);
            return;
        }

        try {
            const popup = new PaystackPop();
            
            // Setup event listeners for Paystack subscription payment
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    console.log('sucess: ', transaction)
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel();
                },
                onError: (error) => {
                    this.handlePaystackError(error);
                }
            });
            
        } catch (error) {
            console.error('Error resuming Paystack subscription popup:', error);
            this.handlePaystackError(error);
        }
    }

    handlePaymentSuccess(transaction) {

        const params = new URLSearchParams({
            action: 'fluent_cart_confirm_paystack_payment',
            reference: transaction.reference || transaction.trxref,
            trx_id: transaction.trans || transaction.transaction,
            paystack_fct_nonce: window.fct_paystack_data?.nonce
        });

        const that = this;
        const xhr = new XMLHttpRequest();
        xhr.open('POST', window.fluentcart_checkout_vars.ajaxurl, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');

        xhr.onload = function () {
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const res = JSON.parse(xhr.responseText);
                    if (res?.redirect_url) {
                        that.paymentLoader.triggerPaymentCompleteEvent(res);
                        that.paymentLoader?.changeLoaderStatus('redirecting');
                        window.location.href = res.redirect_url;
                    } else {
                        that.handlePaystackError(new Error(res?.message || 'Payment confirmation failed'));
                    }
                } catch (error) {
                    that.handlePaystackError(error);
                }
            } else {
                that.handlePaystackError(new Error(that.$t('Network error: ' + xhr.status)));
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.handlePaystackError(err);
            } catch (e) {
                console.error('An error occurred:', e);
                that.handlePaystackError(e);
            }
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel() {
        this.paymentLoader.hideLoader();
        this.paymentLoader.enableCheckoutButton(this.submitButton.text);    
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

        let paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        let tempMessage = this.$t('Something went wrong');

        if (paystackContainer) {            
            paystackContainer.innerHTML += '<div id="fct_loading_payment_processor">' + this.$t(tempMessage) + '</div>';
            paystackContainer.style.display = 'block';
            paystackContainer.querySelector('#fct_loading_payment_processor').style.color = '#dc3545';
            paystackContainer.querySelector('#fct_loading_payment_processor').style.fontSize = '14px';
            paystackContainer.querySelector('#fct_loading_payment_processor').style.padding = '10px';
        }
         
        this.paymentLoader.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
    
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
            return;
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
        errorDiv.style.padding = '10px';
        errorDiv.style.fontSize = '14px';
        errorDiv.className = 'fct-error-message';
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


