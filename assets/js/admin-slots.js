initAdminShell('slots', ['Studio Management', 'Administration Portal', 'Date Time Slot']);

document.getElementById('slot-rows').addEventListener('submit', async event => {
  const form = event.target;
  const button = event.submitter;
  if (!button || button.dataset.closeSlot !== '1' || form.dataset.confirmed === '1') {
    delete form.dataset.confirmed;
    return;
  }

  event.preventDefault();
  const confirmed = await confirmDialog({
    title: 'Tutup slot waktu?',
    message: 'Slot ini tidak akan ditawarkan kepada pelanggan untuk booking.',
    okLabel: 'Tutup Slot',
    danger: true,
  });
  if (!confirmed) return;
  form.dataset.confirmed = '1';
  form.requestSubmit(button);
});
