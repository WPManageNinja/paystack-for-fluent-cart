class PaystackCheckout {
    #cdnUrl = 'https://js.paystack.co/v2/inline.js';
    #publicKey = null;
    #isProcessing = false;

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
        const paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        const hasCustomContent = paystackContainer && paystackContainer.dataset.hasCustomContent === 'true';

        if (paystackContainer && !hasCustomContent) {
            paystackContainer.innerHTML = '';
            this.renderPaymentButton(paystackContainer);
        } else {
            // Custom content owns the UI — still signal the loader so the
            // method doesn't stay stuck in its loading state.
            window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
                detail: { payment_method: 'paystack' }
            }));
            const loadingElement = document.getElementById('fct_loading_payment_processor');
            if (loadingElement) {
                loadingElement.remove();
            }
        }

        this.#publicKey = this.data?.payment_args?.public_key;
    }

    translate(string) {
        const translations = window.fct_paystack_data?.translations || {};
        return translations[string] || string;
    }

    getButtonText() {
        return window.fct_paystack_data?.button_text || this.$t('Pay with Paystack');
    }

    getBodyText() {
        return window.fct_paystack_data?.body_text || this.$t('Pay securely, available payment options are shown in the next step.');
    }

    renderPaymentButton(container) {
        const that = this;

        this.renderPaymentInfo();

        const buttonWrapper = document.createElement('div');
        buttonWrapper.className = 'fct-paystack-button-wrapper';

        const payButton = document.createElement('button');
        payButton.type = 'button';
        payButton.id = 'fct-paystack-pay-button';
        payButton.className = 'fct-paystack-pay-button';
        payButton.innerHTML = `
            <span class="fct-paystack-btn-text"></span>
            <span class="fct-paystack-btn-loader" style="display: none;">
                <svg width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <style>.spinner{transform-origin:center;animation:spinner .75s linear infinite}@keyframes spinner{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}</style>
                    <circle class="spinner" cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" stroke-dasharray="31.4 31.4"/>
                </svg>
            </span>
        `;
        payButton.querySelector('.fct-paystack-btn-text').textContent = this.getButtonText();

        payButton.addEventListener('click', async () => {
            if (that.#isProcessing) return;
            await that.handlePayButtonClick(payButton);
        });

        buttonWrapper.appendChild(payButton);
        container.appendChild(buttonWrapper);

        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_success', {
            detail: { payment_method: 'paystack' }
        }));

        const loadingElement = document.getElementById('fct_loading_payment_processor');
        if (loadingElement) {
            loadingElement.remove();
        }
    }

    async handlePayButtonClick(button) {
        this.#isProcessing = true;

        const btnText = button.querySelector('.fct-paystack-btn-text');
        const btnLoader = button.querySelector('.fct-paystack-btn-loader');
        btnText.textContent = this.$t('Processing...');
        btnLoader.style.display = 'inline-block';
        button.disabled = true;

        try {
            if (typeof this.orderHandler !== 'function') {
                throw new Error(this.$t('Order handler not available'));
            }

            const orderResponse = await this.orderHandler();

            if (!orderResponse) {
                // handleOrder() already surfaced the real reason (validation error,
                // toast, cart-lock message) and re-enabled the shared button state —
                // just reset our own button, don't stack a second generic error on top.
                this.resetPayButton(button);
                return;
            }

            const paystackData = orderResponse?.data?.paystack_data;
            const intent = orderResponse?.data?.intent;

            if (!paystackData?.access_code) {
                throw new Error(this.$t('Payment data not received'));
            }

            await this.loadPaystackScript();

            if (intent === 'subscription') {
                this.paystackSubscriptionPayment(paystackData.access_code, paystackData.authorization_url, button);
            } else {
                this.onetimePaymentHandler(paystackData.access_code, paystackData.authorization_url, button);
            }
        } catch (error) {
            this.handlePaystackError(error);
            this.resetPayButton(button);
        }
    }

    resetPayButton(button) {
        this.#isProcessing = false;
        const btnText = button.querySelector('.fct-paystack-btn-text');
        const btnLoader = button.querySelector('.fct-paystack-btn-loader');
        btnText.textContent = this.getButtonText();
        btnLoader.style.display = 'none';
        button.disabled = false;
    }

    renderPaymentInfo() {
        let html = '<div class="fct-paystack-info">';
        const bodyText = document.createElement('p');
        bodyText.className = 'fct-paystack-subheading';
        bodyText.textContent = this.getBodyText();
        html += bodyText.outerHTML;
        html += '</div>';

        // Add CSS styles
        html += `<style>
            .fct-paystack-info {
                padding: 20px;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                background: #f9f9f9;
                margin-bottom: 10px;
            }

            .fct-paystack-button-wrapper {
                margin-bottom: 10px;
            }

            .fct-paystack-subheading {
                margin: 0;
                font-size: 12px;
                color: #999;
                font-weight: 400;
            }

            .fct-paystack-pay-button {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                width: 100%;
                box-sizing: border-box;
                padding: 14px 20px;
                line-height: 1;
                background: #011B33;
                color: #fff;
                border: none;
                border-radius: 8px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: opacity 0.2s ease;
            }

            .fct-paystack-pay-button .fct-paystack-btn-text {
                line-height: 1;
                display: inline-block;
            }

            .fct-paystack-pay-button:hover {
                opacity: 0.9;
            }

            .fct-paystack-pay-button:disabled {
                opacity: 0.7;
                cursor: not-allowed;
            }

            @media (max-width: 768px) {
                .fct-paystack-info {
                    padding: 16px;
                }
            }
        </style>`;

        let container = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        container.innerHTML = html;
    }

    loadPaystackScript() {
        return new Promise((resolve, reject) => {
            if (typeof PaystackPop === 'function') {
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

    async onetimePaymentHandler(access_code, authorizationUrl, button) {
        try {
            const popup = new PaystackPop();
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel(button);
                },
                onError: (error) => {
                    this.handlePaystackError(error);
                    this.resetPayButton(button);
                }
            });
        } catch (error) {
            this.handlePaystackError(error);
            this.resetPayButton(button);
        }
    }

    async paystackSubscriptionPayment(access_code, authorizationUrl, button) {
        try {
            const popup = new PaystackPop();
            popup.resumeTransaction(access_code, {
                onSuccess: (transaction) => {
                    this.handlePaymentSuccess(transaction);
                },
                onCancel: () => {
                    this.handlePaymentCancel(button);
                },
                onError: (error) => {
                    this.handlePaystackError(error);
                    this.resetPayButton(button);
                }
            });
        } catch (error) {
            this.handlePaystackError(error);
            this.resetPayButton(button);
        }
    }

    handlePaymentSuccess(transaction) {
        this.paymentLoader?.changeLoaderStatus(this.$t('Verifying payment...'));

        const button = document.getElementById('fct-paystack-pay-button');
        if (button) {
            const btnText = button.querySelector('.fct-paystack-btn-text');
            if (btnText) btnText.textContent = this.$t('Verifying payment...');
        }

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
                        if (button) that.resetPayButton(button);
                    }
                } catch (error) {
                    that.handlePaystackError(error);
                    if (button) that.resetPayButton(button);
                }
            } else {
                that.handlePaystackError(new Error(that.$t('Network error: ' + xhr.status)));
                if (button) that.resetPayButton(button);
            }
        };

        xhr.onerror = function () {
            try {
                const err = JSON.parse(xhr.responseText);
                that.handlePaystackError(err);
            } catch (e) {
                that.handlePaystackError(e);
            }
            if (button) that.resetPayButton(button);
        };

        xhr.send(params.toString());
    }

    handlePaymentCancel(button) {
        this.#isProcessing = false;
        this.paymentLoader?.changeLoaderStatus(this.$t('Payment cancelled'));
        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
        if (button) this.resetPayButton(button);
    }

    handlePaystackError(err) {
        this.#isProcessing = false;

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
        if (paystackContainer) {
            const existingError = paystackContainer.querySelector('.fct-paystack-error');
            if (existingError) existingError.remove();

            const errorDiv = document.createElement('div');
            errorDiv.className = 'fct-paystack-error';
            errorDiv.textContent = errorMessage;
            errorDiv.style.color = '#dc3545';
            errorDiv.style.fontSize = '14px';
            errorDiv.style.padding = '10px 0 0 0';
            paystackContainer.appendChild(errorDiv);

            setTimeout(() => {
                if (errorDiv.parentNode) errorDiv.remove();
            }, 5000);
        }

        this.paymentLoader?.hideLoader();
        this.paymentLoader?.enableCheckoutButton(this.submitButton?.text || this.$t('Place Order'));
    }
}

window.addEventListener("fluent_cart_load_payments_paystack", function (e) {
    window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading', {
        detail: { payment_method: 'paystack' }
    }));

    const paystackContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
    if (paystackContainer && paystackContainer.children.length > 0) {
        // Only third-party markup counts as custom content. Our own rendered
        // button / loading text / error from a previous load event must not
        // flip this flag, or re-selecting Paystack skips rendering and the
        // loading state never clears.
        const ownContent = paystackContainer.querySelector(
            '.fct-paystack-info, .fct-paystack-button-wrapper, #fct_loading_payment_processor, .fct-error-message, .fct-paystack-error'
        );
        if (!ownContent) {
            paystackContainer.dataset.hasCustomContent = 'true';
        }
    }

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

        window.dispatchEvent(new CustomEvent('fluent_cart_payment_method_loading_failed', {
            detail: { payment_method: 'paystack' }
        }));
        return;
    }

    function addLoadingText() {
        let paystackButtonContainer = document.querySelector('.fluent-cart-checkout_embed_payment_container_paystack');
        if (paystackButtonContainer) {
            if (paystackButtonContainer.dataset.hasCustomContent === 'true') {
                return;
            }
            if (document.getElementById('fct_loading_payment_processor')) {
                return;
            }
            const loadingMessage = document.createElement('p');
            loadingMessage.id = 'fct_loading_payment_processor';
            loadingMessage.className = 'fct-paystack-loading';
            const translations = window.fct_paystack_data?.translations || {};
            function $t(string) {
                return translations[string] || string;
            }
            loadingMessage.textContent = $t('Loading Payment Processor...');
            paystackButtonContainer.appendChild(loadingMessage);
        }
    }
});
