document.addEventListener('DOMContentLoaded', () => {
  const dashboardName = document.getElementById('dashboard-nama');

  const accountBtn = document.getElementById('akun-btn');
  const accountMenu = document.getElementById('akun-menu');
  
  const accountFirstName = document.getElementById('akun-nama-depan');
  const accountName = document.getElementById('akun-nama');
  const accountPhone = document.getElementById('akun-hp');
  const accountEmail = document.getElementById('akun-email');

  const accountEditBtn = document.getElementById('akun-edit-btn');
  const accountModal = document.getElementById('akun-modal');
  const accountForm = document.getElementById('akun-form');
  const accountCancelBtn = document.getElementById('akun-batal');

  const inputName = document.getElementById('akun-input-nama');
  const inputPhone = document.getElementById('akun-input-hp');
  const inputEmail = document.getElementById('akun-input-email');

  const accountError = document.getElementById('akun-error');

  const csrfToken = document.querySelector(
    'meta[name="csrf-token"]')?.content;

  if(!dashboardName || !accountModal || !accountForm){
    console.error('Dashboard profile elements are missing');
    return;
  }

  let customer = null;

  function displayCustomer(profile){
    customer = profile;

    const fullName = profile.full_name || 'Customer';
    const firstName = fullName.trim().split(/\s+/)[0];

    if (dashboardName){
      dashboardName.textContent = fullName;
    }

    if(accountFirstName){
      accountFirstName.textContent = firstName;
    }

    if(accountName){
      accountName.textContent = fullName;
    }

    if(accountPhone){
      accountPhone.textContent = profile.phone_number || '';
    }

    if(accountEmail){
      accountEmail.textContent = profile.email || '';
    }

    const initial = accountBtn.querySelector('span');

    if(initial){
      initial.textContent = firstName.charAt(0).toUpperCase();
    }

  }

  async function loadProfile(){
    try{
      const response = await fetch('../../backend/api/profile.php',{
        method: 'GET',
        headers: {'Accept': 'application/json'},
      credentials: 'same-origin'});

      const result = await response.json();

      if(response.status === 401){
        window.location.href = '../login.php';
        return;
      }
      if(!response.ok || !result.success){
        throw new Error(result.message || 'Unable to load profile.');
      }
      displayCustomer(result.data);
    }catch(error){
      console.error('Profile loading error: ', error);

      if(dashboardName){
        dashboardName.textContent = 'unable to load profile.'
      }
    }
  }

  function openProfileModel(){
    if(!customer){
      return;
    }

    inputName.value = customer.full_name || '';
    inputPhone.value = customer.phone_number || '';
    inputEmail.value = customer.email || '';

    accountError.textContent = '';
    accountError.classList.add('hidden');

    accountMenu?.classList.add('hidden');

    accountModal.classList.remove('hidden');
    accountModal.classList.add('flex');
  }

function closeProfileModal(){
    accountModal.classList.add('hidden');
    accountModal.classList.remove('flex');
}

  accountEditBtn?.addEventListener('click', openProfileModel);
  accountCancelBtn?.addEventListener('click', closeProfileModal);

  accountModal.addEventListener('click', (event) => {
    if (event.target === accountModal){
      closeProfileModal();
    }
  });

  accountForm.addEventListener('submit', async (event) => {
    event.preventDefault();

    const fullName = inputName.value.trim();
    const phoneNumber = inputPhone.value.trim();
    const email = inputEmail.value.trim();

    accountError.classList.add('hidden');

    if(fullName.length < 3){
      showProfileError('Full name must contain at least 3 characters.');
      return;
    }

    if(phoneNumber.replace(/\D/g,'').length < 9){
      showProfileError('Please enter a valid phone number.');
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showProfileError('Please enter a valid email address.');
      return;
        }

    if(!csrfToken){
      showProfileError('please reload the page.');
      return;
    }

    const submitButton = accountForm.querySelector('button[type="submit"]');

    if(submitButton){
      submitButton.disabled = true;
      submitButton.textContent = 'Saving....';
    }

    try{
      const response = await fetch('../../backend/api/profile.php', {
        method: 'PATCH',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken},
        credentials: 'same-origin',
        body: JSON.stringify({
          full_name: fullName,
          phone_number: phoneNumber,
          email: email
        })
      });

      const result = await response.json();

      if(response.status === 401){
        window.location.href = '../login.php';
        return;
      }

      if(!response.ok || !result.success){
        throw new Error(result.message || 'Profile update failed.');
      }

      displayCustomer(result.data);
      closeProfileModal();

      alert('your profile has been updated successfully');
    }catch(error){
      console.error('profile update error: ',error);
      showProfileError(error.message);

    }finally{
      if(submitButton){
        submitButton.disabled = false;
        submitButton.textContent = 'Simpan Perbahan';
      }
    }
  });

  function showProfileError(message){
    accountError.textContent = message;
    accountError.classList.remove('hidden');
  }

  loadProfile();
});
