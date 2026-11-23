(() => {
  const root = document.querySelector('[data-cart-app]');
  if (!root) return;
  const sessionUrl = root.dataset.sessionUrl;
  const feedback = root.querySelector('[data-cart-feedback]');

  async function getJson(response) {
    const payload = await response.json();
    if (!response.ok || payload.success !== true) {
      throw new Error(payload?.error?.message || 'Permintaan gagal.');
    }
    return payload;
  }

  root.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-cart-delete-form]');
    if (!form) return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    if (button) { button.disabled = true; button.textContent = 'Menghapus...'; }

    try {
      const sessionResponse = await fetch(sessionUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const session = await getJson(sessionResponse);
      if (!session.data.authenticated) throw new Error('Sesi login berakhir. Silakan masuk kembali.');
      const itemId = Number(form.dataset.cartItemId);
      const deleteResponse = await fetch(`${root.dataset.cartItemsUrl}/${itemId}`, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-CSRF-Token': session.data.csrf_token }
      });
      const result = await getJson(deleteResponse);
      const row = form.closest('.product-row');
      if (row) row.remove();
      if (feedback) {
        feedback.hidden = false;
        feedback.textContent = result.data.message;
        feedback.dataset.type = 'success';
      }
      window.setTimeout(() => window.location.reload(), 450);
    } catch (error) {
      // Keep the existing server-rendered form as a fallback if the API is unavailable.
      if (feedback) {
        feedback.hidden = false;
        feedback.textContent = `${error.message} Menggunakan proses hapus biasa...`;
        feedback.dataset.type = 'error';
      }
      form.removeAttribute('data-cart-delete-form');
      form.submit();
    }
  });
})();
