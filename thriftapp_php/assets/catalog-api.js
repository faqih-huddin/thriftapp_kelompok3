(() => {
  const root = document.querySelector('[data-catalog-app]');
  if (!root) return;

  const form = root.querySelector('[data-catalog-filters]');
  const grid = root.querySelector('[data-catalog-grid]');
  const pagination = root.querySelector('[data-catalog-pagination]');
  const resultCount = document.querySelector('[data-result-count]');
  const feedback = root.querySelector('[data-catalog-feedback]');
  const productsUrl = root.dataset.productsUrl;
  const sessionUrl = root.dataset.sessionUrl;
  const cartItemsUrl = root.dataset.cartItemsUrl;
  const loginUrl = root.dataset.loginUrl;
  let sessionData = null;
  let currentPage = 1;
  let lastPage = 1;

  const rupiah = (value) => new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', maximumFractionDigits: 0
  }).format(Number(value || 0));

  async function readJson(response) {
    let payload;
    try { payload = await response.json(); }
    catch { throw new Error('Server mengirim respons yang tidak dapat dibaca.'); }
    if (!response.ok || payload.success !== true) {
      throw new Error(payload?.error?.message || `Permintaan gagal (${response.status}).`);
    }
    return payload;
  }

  async function loadSession() {
    try {
      const response = await fetch(sessionUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const payload = await readJson(response);
      sessionData = payload.data;
    } catch {
      sessionData = { authenticated: false, csrf_token: '' };
    }
  }

  function setFeedback(message, type = 'info') {
    feedback.textContent = message;
    feedback.dataset.type = type;
    feedback.hidden = !message;
  }

  function buildCard(product) {
    const article = document.createElement('article');
    article.className = `card catalog-api-card${product.status === 'sold' ? ' card--sold' : ''}${product.status === 'reserved' ? ' card--reserved' : ''}`;

    const link = document.createElement('a');
    link.className = 'catalog-api-link';
    link.href = product.product_url;
    link.setAttribute('aria-label', `Lihat ${product.title}`);

    const imageBox = document.createElement('div');
    imageBox.className = 'card__img';
    const badge = document.createElement('span');
    badge.className = 'badge';
    badge.textContent = `${product.condition_score}/10`;
    const image = document.createElement('img');
    image.src = product.image_url;
    image.alt = product.title;
    image.loading = 'lazy';
    image.onerror = () => { image.src = `${root.dataset.baseUrl}/assets/img/hero.jpg`; };
    imageBox.append(badge, image);

    if (product.status === 'reserved' || product.status === 'sold') {
      const status = document.createElement('span');
      status.className = 'sold';
      status.textContent = product.status === 'sold' ? 'TERJUAL' : 'DIPESAN';
      imageBox.append(status);
    }

    const info = document.createElement('div');
    info.className = 'card__info';
    const name = document.createElement('span');
    name.className = 'card__name';
    name.textContent = product.title;
    const plus = document.createElement('span');
    plus.className = 'plus';
    plus.textContent = '+';
    const price = document.createElement('span');
    price.className = 'card__price';
    price.textContent = rupiah(product.price);
    const meta = document.createElement('span');
    meta.className = 'card__meta';
    meta.textContent = `Ukuran ${product.size_label} · ${product.shop_city || 'Lokasi belum diatur'} · ${product.shop_name}`;
    info.append(name, plus, price, meta);
    link.append(imageBox, info);
    article.append(link);

    if (product.status === 'active') {
      const addButton = document.createElement('button');
      addButton.type = 'button';
      addButton.className = 'btn btn--ghost btn--sm catalog-add-button';
      addButton.textContent = 'Tambah ke keranjang';
      addButton.dataset.addProduct = String(product.id);
      article.append(addButton);
    }
    return article;
  }

  function renderPagination(page, pages) {
    pagination.replaceChildren();
    if (pages <= 1) { pagination.hidden = true; return; }
    pagination.hidden = false;

    const makeButton = (label, target, disabled = false, current = false) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = current ? 'btn btn--sm catalog-page-button is-current' : 'btn btn--ghost btn--sm catalog-page-button';
      button.textContent = label;
      button.disabled = disabled;
      button.addEventListener('click', () => loadProducts(target));
      return button;
    };

    pagination.append(makeButton('Sebelumnya', page - 1, page <= 1));
    const start = Math.max(1, page - 2);
    const end = Math.min(pages, page + 2);
    for (let number = start; number <= end; number += 1) {
      pagination.append(makeButton(String(number), number, false, number === page));
    }
    pagination.append(makeButton('Berikutnya', page + 1, page >= pages));
  }

  function currentFilters() {
    const params = new URLSearchParams(new FormData(form));
    params.delete('r');
    for (const [key, value] of [...params.entries()]) {
      if (String(value).trim() === '' || value === '0') params.delete(key);
    }
    return params;
  }

  async function loadProducts(page = 1) {
    currentPage = page;
    const params = currentFilters();
    params.set('page', String(page));
    params.set('per_page', '12');
    grid.setAttribute('aria-busy', 'true');
    grid.replaceChildren();
    const loading = document.createElement('div');
    loading.className = 'banner-empty';
    loading.textContent = 'Memuat katalog dari database...';
    grid.append(loading);
    setFeedback('');

    try {
      const response = await fetch(`${productsUrl}?${params.toString()}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      });
      const payload = await readJson(response);
      const items = payload.data.items || [];
      const meta = payload.meta.pagination;
      lastPage = Number(meta.last_page || 1);
      currentPage = Number(meta.page || 1);
      grid.replaceChildren();

      if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'banner-empty';
        empty.textContent = 'Belum ada barang yang sesuai dengan filter ini.';
        grid.append(empty);
      } else {
        const fragment = document.createDocumentFragment();
        items.forEach((product) => fragment.append(buildCard(product)));
        grid.append(fragment);
      }

      resultCount.textContent = `${meta.total} hasil ditemukan`;
      renderPagination(currentPage, lastPage);
      const urlParams = currentFilters();
      urlParams.set('r', 'catalog');
      if (currentPage > 1) urlParams.set('page', String(currentPage));
      history.replaceState({}, '', `${window.location.pathname}?${urlParams.toString()}`);
    } catch (error) {
      grid.replaceChildren();
      const message = document.createElement('div');
      message.className = 'banner-empty';
      message.textContent = 'Katalog belum dapat dimuat. ' + error.message;
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'btn btn--ghost';
      retry.textContent = 'Coba lagi';
      retry.addEventListener('click', () => loadProducts(currentPage));
      message.append(document.createElement('br'), retry);
      grid.append(message);
      resultCount.textContent = 'Gagal memuat katalog';
      setFeedback('Periksa koneksi database dan endpoint API.', 'error');
    } finally {
      grid.removeAttribute('aria-busy');
    }
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    loadProducts(1);
  });

  grid.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-add-product]');
    if (!button) return;
    event.preventDefault();

    if (!sessionData) await loadSession();
    if (!sessionData?.authenticated) {
      window.location.href = loginUrl;
      return;
    }

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Menambahkan...';
    try {
      const response = await fetch(cartItemsUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-Token': sessionData.csrf_token
        },
        body: JSON.stringify({ product_id: Number(button.dataset.addProduct) })
      });
      const payload = await readJson(response);
      button.textContent = payload.data.added ? 'Berhasil ditambahkan' : 'Sudah di keranjang';
      const countElement = document.querySelector('[data-cart-count]');
      if (countElement) countElement.textContent = `(${payload.data.item_count})`;
      setFeedback(payload.data.message, 'success');
    } catch (error) {
      button.textContent = originalText;
      setFeedback(error.message, 'error');
    } finally {
      button.disabled = false;
    }
  });

  loadSession().finally(() => loadProducts(1));
})();
