/* SPDX-License-Identifier: MIT */
(() => {
  'use strict';
  const node = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; };
  const actionButton = (text, icon, run) => {
    const b = node('button', 'btn btn-sm btn-ghost-secondary'); b.type = 'button';
    const glyph = node('i', 'ti ' + icon + ' me-1'); glyph.setAttribute('aria-hidden', 'true');
    b.append(glyph, document.createTextNode(text)); b.addEventListener('click', run); return b;
  };
  class SettingsList {
    constructor(kind, labels, open, bulk, reorder) {
      this.kind = kind; this.labels = labels; this.open = open; this.bulk = bulk; this.reorder = reorder;
      this.rows = []; this.selected = new Set(); this.query = ''; this.sort = null; this.reverse = false; this.page = 0; this.limit = 20; this.context = '';
      this.table = document.getElementById('dock-' + kind + '-table'); this.body = this.table.tBodies[0]; this.body.id = 'dock-' + kind;
      this.table.classList.add('dock-settings-list');
      this.header = this.table.tHead.rows[this.table.tHead.rows.length - 1];
      this.checkAll = node('input', 'form-check-input'); this.checkAll.type = 'checkbox'; this.checkAll.setAttribute('aria-label', 'Выбрать все строки на странице');
      const first = node('th'); first.append(this.checkAll); this.header.prepend(first);
      this.checkAll.addEventListener('change', () => { this.visible.forEach(row => this.checkAll.checked ? this.selected.add(row.id) : this.selected.delete(row.id)); this.draw(); });
      this.headers = [...this.header.cells].slice(1);
      this.headers.forEach((th, index) => {
        th.replaceChildren(); const b = actionButton(labels[index], 'ti-arrows-sort', () => { this.reverse = this.sort === index ? !this.reverse : false; this.sort = index; this.page = 0; this.draw(); });
        b.className = 'btn btn-link p-0 text-start'; th.append(b);
      });
      const host = document.getElementById('dock-' + kind + '-list');
      this.toolbar = node('div', 'd-flex align-items-center flex-wrap gap-2 border-bottom p-2');
      this.search = node('input', 'form-control form-control-sm'); this.search.type = 'search'; this.search.placeholder = 'Поиск по списку'; this.search.setAttribute('aria-label', 'Поиск по списку'); this.search.hidden = true;
      this.search.addEventListener('input', () => { this.query = this.search.value.trim().toLocaleLowerCase(); this.page = 0; this.draw(); });
      const searchButton = actionButton('Поиск', 'ti-search', () => { this.search.hidden = !this.search.hidden; if (!this.search.hidden) this.search.focus(); });
      this.sortSelect = node('select', 'form-select form-select-sm'); this.sortSelect.setAttribute('aria-label', 'Поле сортировки');
      [['', 'Исходный порядок'], ...labels.map((label, i) => [String(i), label])].forEach(([value, label]) => { const o = node('option', '', label); o.value = value; this.sortSelect.append(o); });
      this.sortSelect.addEventListener('change', () => { this.sort = this.sortSelect.value === '' ? null : Number(this.sortSelect.value); this.page = 0; this.draw(); });
      this.direction = actionButton('', 'ti-sort-ascending', () => { this.reverse = !this.reverse; this.draw(); }); this.direction.setAttribute('aria-label', 'Изменить направление сортировки');
      this.sortSelect.hidden = this.direction.hidden = true;
      const sortButton = actionButton('Сортировка', 'ti-arrows-sort', () => { this.sortSelect.hidden = this.direction.hidden = !this.sortSelect.hidden; });
      this.actions = node('select', 'form-select form-select-sm'); this.actions.setAttribute('aria-label', 'Массовое действие');
      [['', 'Действия'], ['enable', 'Включить'], ['disable', 'Отключить'], ['delete', 'Удалить']].forEach(([value, label]) => { const o = node('option', '', label); o.value = value; this.actions.append(o); });
      this.apply = actionButton('Выполнить', 'ti-check', async () => {
        const operation = this.actions.value; if (!operation || !this.selected.size) return;
        if (await this.bulk([...this.selected], operation)) this.selected.clear();
        this.actions.value = ''; this.draw();
      });
      const columns = node('details', 'dropdown'); const summary = node('summary', 'btn btn-sm btn-ghost-secondary'); summary.append(node('i', 'ti-columns-3')); summary.setAttribute('aria-label', 'Отображаемые колонки'); columns.append(summary);
      const columnBox = node('div', 'dock-column-menu card p-3');
      labels.forEach((label, i) => { const line = node('label', 'form-check mb-1'); const check = node('input', 'form-check-input'); check.type = 'checkbox'; check.checked = true; check.addEventListener('change', () => { this.headers[i].hidden = !check.checked; [...this.body.rows].forEach(tr => { if (tr.cells[i + 1]) tr.cells[i + 1].hidden = !check.checked; }); }); line.append(check, node('span', 'form-check-label', label)); columnBox.append(line); }); columns.append(columnBox);
      const refresh = actionButton('', 'ti-refresh', () => { this.search.value = ''; this.query = ''; this.sort = null; this.reverse = false; this.page = 0; this.selected.clear(); this.draw(); }); refresh.setAttribute('aria-label', 'Сбросить фильтры списка');
      const exportButton = actionButton('', 'ti-download', () => this.csv()); exportButton.setAttribute('aria-label', 'Экспорт списка CSV');
      this.toolbar.append(searchButton, sortButton, this.sortSelect, this.direction, this.actions, this.apply);
      if (reorder) {
        this.up = actionButton('', 'ti-arrow-up', () => this.reorderSelected(-1)); this.up.setAttribute('aria-label', 'Переместить правило выше');
        this.down = actionButton('', 'ti-arrow-down', () => this.reorderSelected(1)); this.down.setAttribute('aria-label', 'Переместить правило ниже'); this.toolbar.append(this.up, this.down);
      }
      const right = node('div', 'ms-auto d-flex align-items-center gap-1'); right.append(columns, refresh, exportButton); this.toolbar.append(right);
      host.prepend(this.toolbar); this.toolbar.after(this.search);
      this.footer = node('div', 'd-flex align-items-center justify-content-between flex-wrap gap-2 p-2 border-top');
      const pageSize = node('select', 'form-select form-select-sm'); pageSize.setAttribute('aria-label', 'Строк на странице');
      [5, 10, 20, 50, 100, 200].forEach(size => { const o = node('option', '', String(size)); o.value = String(size); o.selected = size === this.limit; pageSize.append(o); });
      pageSize.addEventListener('change', () => { this.limit = Number(pageSize.value); this.page = 0; this.draw(); });
      const sizeLabel = node('label', 'd-flex align-items-center gap-2'); sizeLabel.append(pageSize, document.createTextNode('строки/страница'));
      this.range = node('span', 'text-muted');
      const pages = node('div', 'd-flex align-items-center gap-2'); this.prev = actionButton('‹', '', () => { this.page--; this.draw(); }); this.prev.setAttribute('aria-label', 'Предыдущая страница'); this.next = actionButton('›', '', () => { this.page++; this.draw(); }); this.next.setAttribute('aria-label', 'Следующая страница'); this.pageLabel = node('span'); pages.append(this.prev, this.pageLabel, this.next);
      this.footer.append(sizeLabel, this.range, pages); host.append(this.footer);
    }
    setRows(rows, context = '') {
      if (this.context !== context) { this.context = context; this.selected.clear(); this.page = 0; }
      this.rows = rows; const valid = new Set(rows.map(r => r.id)); this.selected = new Set([...this.selected].filter(id => valid.has(id))); this.draw();
    }
    filtered() {
      const rows = this.rows.filter(r => !this.query || r.cells.some(c => String(c).toLocaleLowerCase().includes(this.query)));
      if (this.sort !== null) rows.sort((a, b) => String(a.cells[this.sort]).localeCompare(String(b.cells[this.sort]), 'ru', {numeric:true}) * (this.reverse ? -1 : 1));
      return rows;
    }
    draw() {
      const rows = this.filtered(), pages = Math.max(1, Math.ceil(rows.length / this.limit)); this.page = Math.max(0, Math.min(this.page, pages - 1));
      this.visible = rows.slice(this.page * this.limit, (this.page + 1) * this.limit); this.body.replaceChildren();
      this.visible.forEach(row => {
        const tr = node('tr'); const td = node('td'); const check = node('input', 'form-check-input'); check.type = 'checkbox'; check.checked = this.selected.has(row.id); check.setAttribute('aria-label', 'Выбрать: ' + row.cells[row.link]); check.addEventListener('change', () => { check.checked ? this.selected.add(row.id) : this.selected.delete(row.id); this.draw(); }); td.append(check); tr.append(td);
        row.cells.forEach((value, i) => { const cell = node('td'); cell.hidden = this.headers[i].hidden; if (i === row.link) { const link = node('a', '', value); link.href = '#dock-' + this.kind.slice(0, -1) + '-editor'; link.addEventListener('click', event => { event.preventDefault(); this.open(row.id); }); cell.append(link); } else cell.textContent = value; tr.append(cell); });
        this.body.append(tr);
      });
      if (!rows.length) { const tr = node('tr'); const td = node('td', 'text-center text-muted py-3', 'Нет результатов'); td.colSpan = this.labels.length + 1; tr.append(td); this.body.append(tr); }
      this.checkAll.checked = this.visible.length > 0 && this.visible.every(r => this.selected.has(r.id)); this.checkAll.indeterminate = this.visible.some(r => this.selected.has(r.id)) && !this.checkAll.checked;
      this.actions.disabled = this.apply.disabled = this.selected.size === 0;
      if (this.up) this.up.disabled = this.down.disabled = this.selected.size !== 1;
      this.apply.title = 'Выбрано: ' + this.selected.size; this.sortSelect.value = this.sort === null ? '' : String(this.sort);
      this.headers.forEach((th, i) => th.setAttribute('aria-sort', this.sort === i ? (this.reverse ? 'descending' : 'ascending') : 'none'));
      this.prev.disabled = this.page === 0; this.next.disabled = this.page >= pages - 1; this.pageLabel.textContent = (this.page + 1) + ' / ' + pages;
      this.range.textContent = 'Отображаются строки с ' + (rows.length ? this.page * this.limit + 1 : 0) + ' по ' + Math.min((this.page + 1) * this.limit, rows.length) + ' из ' + rows.length + (this.selected.size ? ' · Выбрано: ' + this.selected.size : '');
    }
    reorderSelected(delta) { if (this.selected.size === 1) this.reorder([...this.selected][0], delta); }
    csv() {
      const quote = value => { let text = String(value); if (/^\s*[=+@\-]|^[\t\r]/.test(text)) text = "'" + text; return '"' + text.replace(/"/g, '""') + '"'; };
      const text = '\uFEFF' + [this.labels, ...this.filtered().map(r => r.cells)].map(r => r.map(quote).join(';')).join('\r\n');
      const url = URL.createObjectURL(new Blob([text], {type:'text/csv;charset=utf-8'})); const a = node('a'); a.href = url; a.download = 'dock-' + this.kind + '.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
  }
  window.ItcoreDockSettingsList = SettingsList;
})();
