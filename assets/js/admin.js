document.addEventListener('click', (event) => {
  const target = event.target.closest('[data-confirm]');
  if (target && !window.confirm(target.dataset.confirm || 'Confirma esta ação?')) {
    event.preventDefault();
  }
});

document.querySelectorAll('[data-copy]').forEach((button) => {
  button.addEventListener('click', async () => {
    const selector = button.dataset.copy;
    const element = document.querySelector(selector);
    const text = element?.value || element?.textContent || '';
    try {
      await navigator.clipboard.writeText(text.trim());
      const old = button.textContent;
      button.textContent = 'Copiado';
      setTimeout(() => button.textContent = old, 1400);
    } catch (_) {
      window.prompt('Copie o endereço:', text.trim());
    }
  });
});
