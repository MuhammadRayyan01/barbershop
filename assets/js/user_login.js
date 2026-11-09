'use strict';

document.addEventListener('DOMContentLoaded', ()=>{
    const loginForm = document.getElementById('login-form');
    const loginInput = document.getElementById('login-identifier');
    const passwordInput = document.getElementById('login-password');
    const submitButton = document.getElementById('login-submit');
    const errorMessage = document.getElementById('login-message');

    if(!loginForm){
        console.error('Login form not found');
        return;
    }

    loginForm.addEventListener('submit', async (event)=> {
        event.preventDefault();

        const login = loginInput.value.trim();
        const password = passwordInput.value;

        errorMessage.textContent = '';
        errorMessage.classList.add('hidden');

        if(!login || !password){
            showMessage('email and password required.');
            return;
        }

        submitButton.disabled=true;
        submitButton.innerHTML = 'Memproses....';
        submitButton.classList.add('opacity-70', 'cursor-not-allowed');

        try{
            const response = await fetch('../backend/auth/login.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({
                    login: login,
                    password: password
                })
            });

            const result = await response.json();

            if(!response.ok || !result.success){
                throw new Error(result.message || 'login failed. please try again.');
            }

            console.log('login successful: ', result.data);

            window.location.href = 'dashboard/index.html';
        }catch(error){
            console.error('login error: ', error);
            showMessage(error.message || 'unable to connect to the server.');
        }finally{
            submitButton.disabled = false;
            submitButton.innerHTML = 'Masuk <span class="material-symbols-outlined text-[18px]">arrow_forward</span>';

            submitButton.classList.remove('opacity-70', 'cursor-not-allowed');
        }

    });

    function showMessage(message){
        errorMessage.textContent = message;
        errorMessage.classList.remove('hidden');
    }
});