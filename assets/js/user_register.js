document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('register-form');
    const messageBox = document.getElementById('register-message');
    const registerButton = document.getElementById('register-button');
    const buttonText = document.getElementById('register-button-text');

    function showMessage(message, success=false){
        messageBox.textContent = message;

        messageBox.classList.remove('hidden','bg-red-50','text-red-600','border'
                                    ,'border-red-200','bg-green-700','border-green-200'
        );

        if(success){
            messageBox.classList.add('bg-green-50','text-green-700','border','border-green-200');
        }else{
            messageBox.classList.add('bg-red-50','text-red-600','border','border-red-200');
        }
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const fullName = document.getElementById('full_name').value.trim();
        const username = document.getElementById('username').value.trim();
        const phoneNumber = document.getElementById('phone_number').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('reg-password').value.trim();
        const confirmPassword = document.getElementById('reg-password-confirm').value.trim();

        if(!fullName || !username || !phoneNumber || !email ||
            !password || !confirmPassword){
                showMessage('please fill in all fields.')
                return;
            }

            if(password.length < 12 || password.length > 72){
                showMessage('Password must be between 12 and 72 characters.');
                return;
            }

            if(password !== confirmPassword){
                showMessage('passwords do not match.');
                return;
            }

            registerButton.disabled = true;
            buttonText.textContent = 'Creating Account....';
            messageBox.classList.add('hidden');

            try{
                const response = await fetch('../backend/auth/register.php',{
                    method: 'POST',
                    headers: {'Content-Type': 'application/json','Accept': 'application/json'},
                    credentials: 'same-origin',
                    
                    body: JSON.stringify({
                        username: username,
                        email: email,
                        full_name: fullName,
                        phone_number: phoneNumber,
                        password: password
                    })
                });

                const result = await response.json();

                if (!response.ok || !result.success){
                    showMessage(result.message || 'Registration failed.');
                    return;
                }

                showMessage('Registration successful! Redirecting.....', true);

                window.location.href = 'dashboard/index.php';

            }catch(error){
                console.error('Registration error: ', error);
                showMessage('Unable to connect to the server. please try again.');

            }finally{
                registerButton.disabled = false;
                buttonText.textContent = 'Dafter Sekarang';
            }
    });
});