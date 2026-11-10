/* SPDX-License-Identifier: MIT */
(() => {
  'use strict';
  const root = document.getElementById('itcore-dock-settings');
  if (!root) return;
  const el = id => document.getElementById('dock-' + id);
  const state = JSON.parse(el('settings-json').value);
  function editorField(editor, id, label, checkbox = false) {
    const wrapper = document.createElement('div'); wrapper.className = 'form-field row align-items-center col-12 col-sm-6 mb-2';
    const input = document.createElement('input'); input.id = 'dock-' + id; input.type = checkbox ? 'checkbox' : 'text'; input.className = checkbox ? 'form-check-input' : 'form-control mt-2';
    input.className = checkbox ? 'form-check-input' : 'form-control';
    const text = document.createElement('label'); text.htmlFor = input.id; text.textContent = label; text.className = 'col-form-label col-xxl-5 text-xxl-end';
    const field = document.createElement('div'); field.className = 'col-xxl-7 field-container'; field.append(input); wrapper.append(text, field);
    el(editor + '-editor').insertBefore(wrapper, el(editor + '-editor').querySelector('.dock-buttons'));
  }
  editorField('rule', 'rule-enabled', 'Правило включено', true);
  editorField('model', 'model-disabled-pids', 'Отключённые PID (через запятую)');
  let selected = el('selected-vendor').value || state.manufacturers[0]?.id || '';
  let editing = null;
  const uid = () => 'custom-' + crypto.randomUUID();
  const vendor = () => state.manufacturers.find(v => v.id === selected);
  const ids = value => [...new Set(value.toUpperCase().split(/[\s,;]+/).filter(Boolean))];
  const mark = () => { el('settings-json').value = JSON.stringify(state); el('dirty').textContent = 'Изменения в форме ещё не сохранены'; };
  function confirmRemoval(message) {
    return new Promise(resolve => {
      const dialog = document.createElement('dialog'); dialog.className = 'dock-confirm card';
      const title = document.createElement('h3'); title.textContent = 'Удаление настроек';
      const text = document.createElement('p'); text.textContent = message;
      const controls = document.createElement('div'); controls.className = 'dock-buttons mt-3';
      let approved = false;
      const remove = button('Удалить', () => { approved = true; dialog.close(); });
      const cancel = button('Отмена', () => dialog.close()); controls.append(remove, cancel);
      dialog.append(title, text, controls); root.append(dialog);
      dialog.addEventListener('close', () => { dialog.remove(); resolve(approved); }, {once:true});
      dialog.showModal(); cancel.focus();
    });
  }
  function button(text, action) {
    const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-' + (text === 'Удалить' ? 'danger' : 'secondary') + ' btn-sm'; b.textContent = text; b.addEventListener('click', action); return b;
  }
  function options(select, values, chosen = []) {
    select.replaceChildren(); values.forEach(([value, text]) => { const o = document.createElement('option'); o.value = value; o.textContent = text; o.selected = chosen.includes(value); select.append(o); });
  }
  function closeEditors() { ['vendor', 'model', 'rule'].forEach(kind => { el(kind + '-editor').hidden = true; }); editing = null; }
  function tab(name) {
    el('active-tab').value = name;
    el('mobile-tab').value = name;
    root.querySelectorAll('[data-dock-panel]').forEach(p => { p.hidden = p.dataset.dockPanel !== name; });
    root.querySelectorAll('[data-dock-tab]').forEach(b => { const active = b.dataset.dockTab === name; b.classList.toggle('active', active); b.setAttribute('aria-selected', String(active)); b.tabIndex = active ? 0 : -1; });
    el('context').hidden = !['models', 'rules', 'preview', 'presets'].includes(name);
  }
  function render() {
    const v = vendor(); el('selected-vendor').value = selected;
    options(el('vendor-select'), state.manufacturers.map(x => [x.id, x.name]), [selected]);
    el('vendor-status').textContent = v ? (v.enabled ? 'Включён' : 'Отключён') : 'Добавьте производителя';
    ['model', 'rule'].forEach(k => { el('add-' + k).disabled = !v; });
    lists.vendors.setRows(state.manufacturers.map(x => ({id:x.id, link:0, cells:[x.name, x.vids.join(', '), x.enabled ? 'Да' : 'Нет', x.models.length + ' / ' + x.rules.length]})));
    lists.models.setRows((v?.models || []).map(m => ({id:m.id, link:0, cells:[m.name, m.code, m.pids.join(', '), m.enabled ? 'Да' : 'Нет']})), selected);
    lists.rules.setRows((v?.rules || []).map((r, i) => ({id:r.id, link:0, cells:[r.name, r.models.length ? r.models.map(id => v.models.find(m => m.id === id)?.code || id).join(', ') : 'Все модели', r.enabled ? 'Да' : 'Нет', String(i + 1)]})), selected);
    el('model-empty').hidden = !!v?.models.length; el('rule-empty').hidden = !!v?.rules.length;
    const currentRule = el('test-rule').value || el('test-rule').dataset.current || 'auto';
    options(el('test-rule'), [['auto', 'Автоматически - по порядку'], ...(v?.rules || []).map(r => [r.id, r.name + (r.enabled ? '' : ' (отключено)')])], [currentRule]);
    if (!el('test-rule').value) el('test-rule').value = 'auto';
    el('example').disabled = v?.name.toUpperCase() !== 'LENOVO';
  }
  function move(index, delta) { const rules = vendor().rules; const to = index + delta; if (to < 0 || to >= rules.length) return; [rules[index], rules[to]] = [rules[to], rules[index]]; mark(); render(); }
  function start(kind, record) { closeEditors(); editing = record; el(kind + '-editor').hidden = false; el(kind + '-error').textContent = ''; el(kind + '-editor').scrollIntoView({block:'nearest',behavior:'smooth'}); }
  function editVendor(v = null) { start('vendor', v); el('vendor-name').value = v?.name || ''; el('vendor-vids').value = v?.vids.join(', ') || ''; el('vendor-enabled').checked = v?.enabled ?? true; }
  function editModel(m = null) { start('model', m); ['code', 'name'].forEach(k => { el('model-' + k).value = m?.[k] || ''; }); el('model-pids').value = m?.pids.join(', ') || ''; el('model-disabled-pids').value = m?.disabled_pids?.join(', ') || ''; el('model-enabled').checked = m?.enabled ?? true; }
  function editRule(r = null) {
    start('rule', r); options(el('rule-models'), vendor().models.map(m => [m.id, m.code + ' - ' + m.name]), r?.models || []);
    el('rule-enabled').checked = r?.enabled ?? true; el('rule-name').value = r?.name || ''; el('rule-pids').value = r?.pids.join(', ') || ''; el('rule-action').value = r?.action || 'extract';
    el('rule-pattern').value = r?.pattern || ''; el('rule-serial').value = r?.serial_group || 1; el('rule-extra').value = r?.extra || 'none'; el('rule-extra-group').value = r?.extra_group || 1; el('rule-check-model').checked = r?.check_model ?? false; regexFields();
  }
  function regexFields() { el('regex-fields').hidden = el('rule-action').value === 'keep'; el('extra-group-label').hidden = el('rule-extra').value === 'none'; }
  function apply(kind, fn) { el(kind + '-apply').addEventListener('click', () => { try { fn(); closeEditors(); mark(); render(); } catch (e) { el(kind + '-error').textContent = e.message; } }); }
  function usb(value) { const list = ids(value); if (list.some(x => !/^[A-F0-9]{4}$/.test(x))) throw new Error('USB ID должен содержать четыре шестнадцатеричных символа.'); return list; }
  apply('vendor', () => {
    const name = el('vendor-name').value.trim(); const vids = usb(el('vendor-vids').value);
    if (!name || !vids.length) throw new Error('Укажите название и хотя бы один VID.');
    if (state.manufacturers.some(v => v !== editing && v.name.toLowerCase() === name.toLowerCase())) throw new Error('Такой производитель уже есть.');
    const target = editing || {id: uid(), models: [], rules: []}; Object.assign(target, {name, vids, enabled: el('vendor-enabled').checked});
    if (!editing) state.manufacturers.push(target); selected = target.id;
  });
  apply('model', () => {
    const v = vendor(), code = el('model-code').value.trim().toUpperCase(), name = el('model-name').value.trim(), pids = usb(el('model-pids').value);
    if (!code || !name || !pids.length) throw new Error('Заполните код, название и PID.');
    if (v.models.some(m => m !== editing && (m.code === code || m.pids.some(p => pids.includes(p))))) throw new Error('Код модели или PID уже используется.');
    if (editing && v.rules.some(r => r.pids.some(p => editing.pids.includes(p) && !pids.includes(p)))) throw new Error('Сначала измените правила, использующие удаляемые PID.');
    const disabled_pids = usb(el('model-disabled-pids').value); if (disabled_pids.some(p => !pids.includes(p))) throw new Error('Отключаемые PID должны присутствовать в списке PID модели.');
    const target = editing || {id: uid()}; Object.assign(target, {code, name, pids, disabled_pids, enabled: el('model-enabled').checked}); if (!editing) v.models.push(target);
  });
  apply('rule', () => {
    const v = vendor(), name = el('rule-name').value.trim(), pids = usb(el('rule-pids').value), models = [...el('rule-models').selectedOptions].map(o => o.value);
    if (!name) throw new Error('Укажите название.');
    const allowed = v.models.filter(m => !models.length || models.includes(m.id)).flatMap(m => m.pids);
    if (pids.some(p => !allowed.includes(p))) throw new Error('PID отсутствует у выбранных моделей.');
    const action = el('rule-action').value, pattern = el('rule-pattern').value;
    if (action === 'extract' && !pattern) throw new Error('Укажите выражение.');
    const target = editing || {id: uid(), enabled: true};
    Object.assign(target, {name, models, pids, action, pattern, enabled: el('rule-enabled').checked, serial_group: Number(el('rule-serial').value), extra: el('rule-extra').value, extra_group: Number(el('rule-extra-group').value), check_model: el('rule-check-model').checked});
    if (!editing) v.rules.push(target);
  });
  root.querySelectorAll('[data-global], select[name^="dock_global_"]').forEach(input => {
    const key = input.dataset.global || input.name.slice('dock_global_'.length);
    const changed = () => { state[key] = input.type === 'checkbox' ? input.checked : input.tagName === 'SELECT' ? input.value === '1' : input.value; mark(); };
    if (window.jQuery) window.jQuery(input).on('change', changed); else input.addEventListener('change', changed);
  });
  root.querySelectorAll('[data-dock-tab]').forEach(b => {
    b.addEventListener('click', event => { event.preventDefault(); tab(b.dataset.dockTab); });
    b.addEventListener('keydown', event => {
      if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault(); const tabs = [...root.querySelectorAll('[data-dock-tab]')]; let index = tabs.indexOf(b);
      index = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : tabs.length - 1)) % tabs.length;
      tab(tabs[index].dataset.dockTab); tabs[index].focus();
    });
  });
  el('mobile-tab').addEventListener('change', () => tab(el('mobile-tab').value));
  root.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', closeEditors));
  root.querySelectorAll('[data-restore-id]').forEach(b => b.addEventListener('click', () => { el('restore-id').value = b.dataset.restoreId; }));
  el('vendor-select').addEventListener('change', () => { selected = el('vendor-select').value; closeEditors(); render(); });
  el('add-vendor').addEventListener('click', () => editVendor()); el('add-model').addEventListener('click', () => editModel()); el('add-rule').addEventListener('click', () => editRule());
  el('rule-action').addEventListener('change', regexFields); el('rule-extra').addEventListener('change', regexFields);
  el('preset-file').addEventListener('change', async () => { el('import-json').value = ''; el('file-error').textContent = ''; const file = el('preset-file').files[0]; if (!file) return; try { if (file.size > 300000) throw new Error('Файл больше 300 КБ.'); const text = await file.text(); JSON.parse(text); el('import-json').value = text; } catch (e) { el('file-error').textContent = e.message; } });
  el('example').addEventListener('change', () => { const samples = {short: ['30A9', '1S40AYZVQ23EBJ'], long: ['30A9', '1SSD21B41469ZVQ23EBJ'], plain: ['30A9', 'ZVQ23EBJ']}; const sample = samples[el('example').value]; if (sample) { el('test-vid').value = '17EF'; el('test-pid').value = sample[0]; el('test-serial').value = sample[1]; } });
  el('profiles-form').addEventListener('submit', event => { if ([...root.querySelectorAll('.dock-editor')].some(e => !e.hidden)) { event.preventDefault(); alert('Примените изменения редактора к форме или нажмите «Отмена».'); return; } el('settings-json').value = JSON.stringify(state); });
  async function bulk(kind, keys, operation) {
    const v = vendor(); const records = kind === 'vendors' ? state.manufacturers : v?.[kind];
    if (!records) return false;
    const chosen = records.filter(record => keys.includes(record.id));
    if (operation === 'delete') {
      const dependent = kind === 'models' ? v.rules.filter(r => r.models.some(id => keys.includes(id)) || r.pids.some(pid => chosen.some(m => m.pids.includes(pid)))) : [];
      const message = 'Удалить выбранные записи настроек (' + chosen.length + ')?' + (dependent.length ? ' Также будут удалены связанные правила: ' + dependent.length + '.' : '') + ' Карточки оборудования сохранятся. Изменения вступят в силу после сохранения настроек.';
      if (!await confirmRemoval(message)) return false;
      if (kind === 'vendors') { state.manufacturers = records.filter(r => !keys.includes(r.id)); if (!vendor()) selected = state.manufacturers[0]?.id || ''; }
      else { v[kind] = records.filter(r => !keys.includes(r.id)); if (kind === 'models') v.rules = v.rules.filter(r => !dependent.includes(r)); }
    } else chosen.forEach(r => { r.enabled = operation === 'enable'; });
    closeEditors(); mark(); render(); return true;
  }
  const lists = {
    vendors: new window.ItcoreDockSettingsList('vendors', ['Наименование','USB VID','Включён','Модели / правила'], id => editVendor(state.manufacturers.find(r => r.id === id)), (ids, op) => bulk('vendors', ids, op)),
    models: new window.ItcoreDockSettingsList('models', ['Наименование','Модель','PID','Включена'], id => editModel(vendor().models.find(r => r.id === id)), (ids, op) => bulk('models', ids, op)),
    rules: new window.ItcoreDockSettingsList('rules', ['Наименование','Модели','Включено','Порядок обработки'], id => editRule(vendor().rules.find(r => r.id === id)), (ids, op) => bulk('rules', ids, op), (id, delta) => move(vendor().rules.findIndex(r => r.id === id), delta)),
  };
  render(); tab(el('active-tab').value || 'general');
})();
