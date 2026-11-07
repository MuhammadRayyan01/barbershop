const passwordInput = document.getElementById('password');
const passwordToggle = document.getElementById('toggle-pass');

passwordToggle.addEventListener('click', event => {
  const visible = passwordInput.type === 'password';
  passwordInput.type = visible ? 'text' : 'password';
  event.currentTarget.querySelector('span').textContent = visible ? 'visibility_off' : 'visibility';
  event.currentTarget.setAttribute('aria-label', visible ? 'Sembunyikan password' : 'Tampilkan password');
});
