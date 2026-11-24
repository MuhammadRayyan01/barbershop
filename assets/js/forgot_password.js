'use strict';

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('forgot-password-form');
    if (!form) return;

    const message = document.getElementById('forgot-password-message');
    const button = document.getElementById('forgot-password-submit');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        message.textContent = '';
        message.className = 'hidden text-sm rounded-xl p-3';

        const mode = form.dataset.mode;
        const payload = { action: mode, csrf_token: form.dataset.csrfToken };
        if (mode === 'request') {
            payload.email = document.getElementById('email').value.trim();
        } else {
            payload.token = form.elements.token.value;
            payload.password = document.getElementById('new-password').value;
            if (payload.password !== document.getElementById('confirm-password').value) {
                showMessage('Konfirmasi kata sandi tidak cocok.', false);
                return;
            }
        }

        button.disabled = true;
        button.classList.add('opacity-70', 'cursor-not-allowed');
        button.querySelector('span').textContent = 'Memproses...';
        try {
            const response = await fetch('../backend/auth/forgot_password.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': payload.csrf_token
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.message || 'Permintaan gagal. Coba lagi.');
            showMessage(result.message, true);
            if (result.redirect) {
                form.reset();
                window.setTimeout(() => { window.location.href = result.redirect; }, 1800);
            }
        } catch (error) {
            showMessage(error.message || 'Tidak dapat terhubung ke server.', false);
        } finally {
            button.disabled = false;
            button.classList.remove('opacity-70', 'cursor-not-allowed');
            button.querySelector('span').textContent = mode === 'reset' ? 'Simpan Kata Sandi' : 'Kirim Tautan';
        }
    });

    function showMessage(text, success) {
        message.textContent = text;
        message.className = `text-sm rounded-xl p-3 ${success ? 'text-emerald-800 bg-emerald-50 border border-emerald-200' : 'text-red-600 bg-red-50 border border-red-200'}`;
    }
});
