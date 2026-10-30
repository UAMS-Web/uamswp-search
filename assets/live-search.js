(() => {
  'use strict';
  const config = window.UAMSSearch;
  if (!config) return;
  document.querySelectorAll('input[data-uams-live="true"]').forEach((input, index) => {
    const form = input.form;
    const list = document.createElement('ul');
    const status = document.createElement('span');
    list.id = `uams-search-options-${index}`;
    list.className = 'uams-search-options';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Search suggestions');
    list.hidden = true;
    status.className = 'uams-search-sr';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-atomic', 'true');
    input.parentElement.append(list, status);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', list.id);
    input.setAttribute('aria-expanded', 'false');
    let items = [], selected = -1, timer, controller, generation = 0, composing = false;
    const close = () => {
      generation++;
      clearTimeout(timer);
      controller?.abort();
      list.hidden = true;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      selected = -1;
    };
    const select = (value) => {
      selected = value;
      [...list.children].forEach((option, i) => option.setAttribute('aria-selected', String(i === value)));
      if (value >= 0) {
        input.setAttribute('aria-activedescendant', list.children[value].id);
        list.children[value].scrollIntoView({ block: 'nearest' });
      } else input.removeAttribute('aria-activedescendant');
    };
    const visit = (item) => {
      // Only normal web links are accepted, even if a response was changed upstream.
      const url = new URL(item.url, location.href);
      if (url.protocol === 'https:' || url.protocol === 'http:') location.assign(url.href);
    };
    const fetchItems = async (term, sequence) => {
      controller = new AbortController();
      const url = new URL(config.endpoint, location.href);
      url.searchParams.set('s', term);
      url.searchParams.set('scope', new FormData(form).get('scope') || 'site');
      const type = new FormData(form).get('type');
      if (type) url.searchParams.set('type', type);
      if (config.preview) url.searchParams.set('engine', 'uams');
      try {
        const response = await fetch(url, {
          signal: controller.signal, credentials: 'same-origin',
          headers: config.nonce ? { 'X-WP-Nonce': config.nonce } : {}
        });
        if (!response.ok) throw new Error('Search unavailable');
        const data = await response.json();
        if (sequence !== generation || document.activeElement !== input || composing) return;
        items = Array.isArray(data.items) ? data.items.slice(0, 6) : [];
        list.replaceChildren();
        selected = -1;
        input.removeAttribute('aria-activedescendant');
        items.forEach((item, i) => {
          const option = document.createElement('li');
          option.id = `${list.id}-${i}`;
          option.setAttribute('role', 'option');
          option.setAttribute('aria-selected', 'false');
          option.textContent = item.title;
          option.addEventListener('pointerdown', (event) => event.preventDefault());
          option.addEventListener('click', () => visit(item));
          list.append(option);
        });
        list.hidden = !items.length;
        input.setAttribute('aria-expanded', String(items.length > 0));
        status.textContent = items.length ? `${items.length} suggestions available. Use the up and down arrow keys to choose.` : 'No suggestions. Press Enter to search.';
      } catch (error) {
        if (error.name !== 'AbortError' && sequence === generation) {
          close();
          status.textContent = 'Suggestions are unavailable. Press Enter to search.';
        }
      }
    };
    const change = () => {
      close();
      status.textContent = '';
      if (composing) return;
      const term = input.value.trim().slice(0, 200);
      if (term.length < 2) return;
      const sequence = generation;
      timer = setTimeout(() => fetchItems(term, sequence), 250);
    };
    input.addEventListener('input', change);
    input.addEventListener('compositionstart', () => { composing = true; close(); });
    input.addEventListener('compositionend', () => { composing = false; change(); });
    input.addEventListener('blur', close);
    input.addEventListener('keydown', (event) => {
      if (event.isComposing) return;
      if (event.key === 'Escape') { event.preventDefault(); close(); status.textContent = ''; return; }
      if (event.key === 'Tab') { close(); return; }
      if (list.hidden || !items.length) return;
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        const next = event.key === 'ArrowDown' ? selected + 1 : selected < 0 ? items.length - 1 : selected - 1;
        select((next + items.length) % items.length);
      } else if (event.key === 'Enter' && selected >= 0) {
        event.preventDefault(); visit(items[selected]);
      }
    });
    form.addEventListener('submit', close);
  });
})();
