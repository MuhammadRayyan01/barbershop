document.addEventListener('DOMContentLoaded', () => {
    const payButton = document.getElementById('pay-now-btn');
    const holdTokenInput = document.getElementById('hold-token');
    const csrfInput = document.querySelector('input[name="csrf_token"]');
    const paymentMessage = document.getElementById('payment-message');

    if (!payButton || !holdTokenInput || !csrfInput) {
        console.error('Payment elements are missing from confirmation.php.');
        return;
    }

    let paymentInProgress = false;

    function showMessage(message) {
        if (paymentMessage) {
            paymentMessage.textContent = message;
        } else {
            console.log(message);
        }
    }

    function setButtonState(disabled, text) {
        payButton.disabled = disabled;
        payButton.textContent = text;
    }

    // Load Midtrans Snap

    function loadSnapScript(clientKey, snapUrl) {
        return new Promise((resolve, reject) => {
            if (window.snap) {
                resolve();
                return;
            }

            const script = document.createElement('script');

            script.src =
                snapUrl +
                '?client-key=' +
                encodeURIComponent(clientKey);

            script.setAttribute('data-client-key', clientKey);

            script.onload = () => {
                if (window.snap) {
                    resolve();
                } else {
                    reject(new Error('Midtrans Snap failed to initialize.'));
                }
            };

            script.onerror = () => {
                reject(new Error('Unable to load Midtrans Snap.'));
            };

            document.head.appendChild(script);
        });
    }

    // Check payment status with our backend

    async function checkPaymentStatus() {
        const response = await fetch(
            '../../backend/api/payment_status.php',
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfInput.value
                },
                body: JSON.stringify({
                    hold_token: holdTokenInput.value
                })
            }
        );

        const result = await response.json();

        if (
            result.payment_status === 'paid' &&
            result.booking_status === 'confirmed'
        ) {
            showMessage('Payment successful! Your booking is confirmed.');

            if (result.data?.reservation_id) {
                window.location.href =
                    'success.php?reservation_id=' +
                    encodeURIComponent(result.data.reservation_id);
            }

            return 'paid';
        }

        if (result.booking_status === 'manual_review') {
            showMessage(
                'Your payment was received, but your booking needs further review. Please contact the barbershop.'
            );

            return 'manual_review';
        }

        return result.payment_status || 'unknown';
    }

    // Poll payment status

    async function waitForPaymentConfirmation() {
        showMessage('Checking your payment. Please wait...');

        for (let attempt = 0; attempt < 15; attempt++) {
            try {
                const status = await checkPaymentStatus();

                if (
                    status === 'paid' ||
                    status === 'manual_review'
                ) {
                    return;
                }

            } catch (error) {
                console.error('Payment status check failed:', error);
            }

            await new Promise(resolve => setTimeout(resolve, 2000));
        }

        showMessage(
            'Your payment is still being processed. Please check again shortly.'
        );

        setButtonState(false, 'Check Payment Again');
    }

    // Start payment

    async function startPayment() {
        if (paymentInProgress) {
            return;
        }

        const holdToken = holdTokenInput.value.trim();

        if (!holdToken) {
            showMessage('Booking information is missing. Please try again.');
            return;
        }

        paymentInProgress = true;

        setButtonState(true, 'Preparing payment...');
        showMessage('Preparing your payment session...');

        try {
            const response = await fetch(
                '../../backend/api/create_payment.php',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfInput.value
                    },
                    body: JSON.stringify({
                        hold_token: holdToken
                    })
                }
            );

            const result = await response.json();

            if (!response.ok || !result.success) {
                throw new Error(
                    result.message || 'Unable to create payment session.'
                );
            }

            const payment = result.data;

            if (
                !payment ||
                !payment.snap_token ||
                !payment.client_key ||
                !payment.snap_url
            ) {
                throw new Error('Payment information is incomplete.');
            }

            await loadSnapScript(
                payment.client_key,
                payment.snap_url
            );

            setButtonState(true, 'Waiting for payment...');

            window.snap.pay(payment.snap_token, {
                onSuccess: async function () {
                    await waitForPaymentConfirmation();
                },

                onPending: async function () {
                    showMessage(
                        'Payment is pending. Waiting for confirmation...'
                    );

                    await waitForPaymentConfirmation();
                },

                onError: function (error) {
                    console.error('Midtrans payment error:', error);

                    showMessage(
                        'Payment could not be completed. Please try again.'
                    );

                    paymentInProgress = false;
                    setButtonState(false, 'Try Payment Again');
                },

                onClose: async function () {
                    showMessage(
                        'Payment window closed. Checking payment status...'
                    );

                    await waitForPaymentConfirmation();

                    paymentInProgress = false;

                    if (payButton.disabled) {
                        setButtonState(false, 'Check Payment Again');
                    }
                }
            });

        } catch (error) {
            console.error('Payment error:', error);

            showMessage(error.message || 'An unexpected error occurred.');

            paymentInProgress = false;
            setButtonState(false, 'Try Payment Again');
        }
    }

    payButton.addEventListener('click', startPayment);
});
