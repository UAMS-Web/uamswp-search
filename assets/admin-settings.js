(() => {
  'use strict';
  // Adds and removes Name + Weight rows in the field and taxonomy tables on the Settings screen.
  // Without this script the server prints the saved rows and one blank row, and the buttons stay hidden.
  const renumber = table => {
    const label = table.dataset.label;
    table.querySelectorAll('tbody tr').forEach((row, index) => {
      const [name, weight] = row.querySelectorAll('input');
      name.setAttribute('aria-label', `${label} name ${index + 1}`);
      weight.setAttribute('aria-label', `${label} weight ${index + 1}`);
      row.querySelector('.uams-remove-row').setAttribute('aria-label', `Remove ${label} row ${index + 1}`);
    });
  };

  const addRow = table => {
    // A key above every printed one keeps the new row's fields together and in order, whatever was removed.
    const next = Number(table.dataset.next || table.querySelectorAll('tbody tr').length);
    table.dataset.next = String(next + 1);
    const row = document.createElement('tr');
    const cell = () => row.appendChild(document.createElement('td'));
    const name = document.createElement('input');
    name.size = Number(table.dataset.size) || 28;
    name.name = `${table.dataset.prefix}[${next}][name]`;
    cell().appendChild(name);
    const weight = document.createElement('input');
    Object.assign(weight, { type: 'number', min: '0', max: '100', step: '0.1', value: '1' });
    weight.name = `${table.dataset.prefix}[${next}][weight]`;
    cell().appendChild(weight);
    const remove = document.createElement('button');
    Object.assign(remove, { type: 'button', className: 'button uams-remove-row', textContent: 'Remove' });
    cell().appendChild(remove);
    table.tBodies[0].appendChild(row);
    renumber(table);
    name.focus();
  };

  document.querySelectorAll('table.uams-search-rows').forEach(table => {
    table.dataset.next = String(table.querySelectorAll('tbody tr').length);
    table.querySelectorAll('.uams-remove-row').forEach(button => { button.hidden = false; });
    // The Add Row button is the paragraph right after the table.
    const add = table.nextElementSibling.querySelector('.uams-add-row');
    if (add) {
      add.hidden = false;
      add.addEventListener('click', () => addRow(table));
    }

    // A delegated listener covers the printed rows and the added ones alike.
    table.addEventListener('click', event => {
      const button = event.target.closest('.uams-remove-row');
      if (!button) return;
      const row = button.closest('tr');
      const rows = [...table.tBodies[0].rows];
      // Keep focus in the table: on the next row's Remove, or the Add Row button when none is left.
      const neighbor = rows[rows.indexOf(row) + 1] || rows[rows.indexOf(row) - 1];
      row.remove();
      renumber(table);
      (neighbor ? neighbor.querySelector('.uams-remove-row') : table.nextElementSibling.querySelector('.uams-add-row')).focus();
    });
  });
})();
