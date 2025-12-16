<?php
require_once __DIR__ . '/auth.php';
$current = current_user();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>HUSS Main Page</title>
  <link rel="stylesheet" href="css/calculator.css">
  <link rel="stylesheet" href="css/index.css">
</head>
<body>
  <main class="site-main">
  <h2>HUSS Main Page</h2>
  <div class="navbar">
    <a class="nav-link" href="index.php">Home</a>
    <a class="nav-link" href="Calculator.php">Calculator</a>
    <a class="nav-link" href="stockpiles.php">Stockpiles</a>
    <a class="nav-link" href="officer.php">Officer</a>
    <?php if ($current): ?>
      <a class="nav-link" href="logout.php">Logout (<?=htmlspecialchars($current['discord'])?>)</a>
    <?php endif; ?>
  </div>
  <!-- Lookup form moved into the Available items tab. IDs preserved for scripts. -->

  <!-- Calculator moved to Calculator.php -->

  <div id="officer" class="section-hidden panel">
    <h3>Officer</h3>
    <p>Officer actions placeholder — add import/export or DB tools here.</p>
  </div>

  <div id="stockpile" class="section-hidden panel">
    <h3>Stockpile</h3>
    <p>Manage your current stock. Items are saved locally in your browser.</p>
    <label>Item name: <input id="stock-item-name" type="text"/></label>
    <label>Quantity: <input id="stock-item-qty" type="number" value="0" step="0.1"/></label>
    <button id="stock-add">Add / Update</button>
    <button id="stock-clear">Clear All</button>
    <div class="stock-actions" style="margin-top:8px">
      <button id="stock-export">Export JSON</button>
      <button id="stock-import">Import JSON</button>
      <input id="stock-import-data" type="file" style="display:none" accept="application/json" />
    </div>
    <div id="stock-list" class="stock-list" style="margin-top:12px"></div>
  </div>

  <div class="tabs" style="margin-top:18px;margin-bottom:12px">
    <button class="tab-btn" data-target="items">Available items</button>
    <button class="tab-btn" data-target="news">Newsfeed</button>
  </div>

  <div id="tab-items" class="tab-panel">
    <h3>Available items</h3>
    <p>Start typing an item name or pick one from the list, then click Lookup.</p>

    <label>Item name: <input id="item" list="items" autocomplete="off"/></label>
    <datalist id="items"></datalist>
    <label>Quantity: <input id="qty" type="number" value="1" min="0" step="0.01"/></label>
    <button id="btn" class="btn primary">Lookup</button>
    <div id="out"></div>

    <div id="items-list">Loading…</div>
    <div style="margin-top:8px">
      <button id="api-debug-toggle" class="btn" style="display:none">Show API debug</button>
      <div id="api-debug" style="display:none; white-space:pre-wrap; background:#f8f8f8; border:1px solid #ddd; padding:8px; margin-top:8px; max-height:240px; overflow:auto;"></div>
    </div>
  </div>

  <div id="tab-news" class="tab-panel" style="display:none">
    <div id="newsfeed" class="card newsfeed">
      <h4>Newsfeed</h4>
      <?php $isOfficer = ($current && isset($current['role']) && $current['role'] === 'Officer'); ?>
      <div id="news-controls" class="news-controls" style="<?= $isOfficer ? '' : 'display:none' ?>">
        <input id="news-title" class="news-input" type="text" placeholder="Title" />
        <input id="news-content" class="news-input news-input--wide" type="text" placeholder="Short content" />
        <button id="news-add" class="btn">Add</button>
        <button id="news-clear" class="btn secondary">Clear</button>
      </div>
      <?php if (!$isOfficer): ?>
        <div class="muted small">Only Officers can add news items.</div>
      <?php endif; ?>
      <div id="news-list"></div>
    </div>
  </div>

  <script>
    // Newsfeed: simple localStorage-backed list
    (function(){
      const key = 'pet_newsfeed_v1';
      const listEl = document.getElementById('news-list');
      const title = document.getElementById('news-title');
      const content = document.getElementById('news-content');
      const addBtn = document.getElementById('news-add');
      const clearBtn = document.getElementById('news-clear');

      function render(){
        const items = JSON.parse(localStorage.getItem(key) || '[]');
        if (!items.length) { listEl.innerHTML = '<div class="muted small">No news items yet.</div>'; return; }
          listEl.innerHTML = items.map(it => `
            <div class="news-item card">
              <div class="news-row">
                <div class="news-title">${escapeHtml(it.title)}</div>
                <div class="news-ts small muted">${new Date(it.ts).toLocaleString()}</div>
              </div>
              <div class="news-body">${escapeHtml(it.content)}</div>
            </div>
          `).join('');
      }

      function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

      if (addBtn) {
        addBtn.addEventListener('click', ()=>{
          const t = title.value.trim(); const c = content.value.trim();
          if (!t && !c) return alert('Enter title or content');
          const items = JSON.parse(localStorage.getItem(key) || '[]');
          items.unshift({title:t||'(no title)', content:c||'', ts: Date.now()});
          localStorage.setItem(key, JSON.stringify(items.slice(0,50)));
          title.value=''; content.value=''; render();
        });
      }
      if (clearBtn) {
        clearBtn.addEventListener('click', ()=>{ if (!confirm('Clear newsfeed?')) return; localStorage.removeItem(key); render(); });
      }
      render();
    })();
    const input = document.getElementById('item');
    const datalist = document.getElementById('items');
    const itemsListEl = document.getElementById('items-list');

    async function loadItems(){
      const debugBtn = document.getElementById('api-debug-toggle');
      const debugEl = document.getElementById('api-debug');
      let rawResp = null;
      try{
        const res = await fetch('php/api.php?items=1');
        rawResp = await res.text();
        if (!res.ok) {
          // show raw response in debug panel
          debugEl.textContent = rawResp;
          debugEl.style.display = 'block';
          debugBtn.style.display = 'inline-block';
          throw new Error('HTTP ' + res.status + ': ' + rawResp.slice(0,200));
        }
        // try to parse JSON
        let j = null;
        try {
          j = JSON.parse(rawResp);
        } catch(parseErr) {
          debugEl.textContent = rawResp;
          debugEl.style.display = 'block';
          debugBtn.style.display = 'inline-block';
          throw new Error('Invalid JSON response: ' + parseErr.message);
        }
        const items = j.items || [];
        datalist.innerHTML = items.map(i => `<option value="${i}"></option>`).join('');
        itemsListEl.innerHTML = items.slice(0,500).map(i => `<div><a href="#" data-name="${i}">${i}</a></div>`).join('');
        // attach click handlers
        itemsListEl.querySelectorAll('a[data-name]').forEach(a=>{
          a.addEventListener('click', (ev)=>{ ev.preventDefault(); input.value = a.dataset.name; doLookup(); });
        });
        // hide debug controls on success
        debugEl.style.display = 'none';
        debugBtn.style.display = 'none';
      }catch(e){
        console.error('loadItems error', e);
        itemsListEl.textContent = 'Failed to load items: ' + (e && e.message ? e.message : e);
        // if we have raw response, ensure debug UI visible
        if (rawResp !== null) {
          debugEl.textContent = rawResp;
          debugEl.style.display = 'block';
          debugBtn.style.display = 'inline-block';
        }
      }
      // wire toggle (idempotent)
      const debugBtnEl = document.getElementById('api-debug-toggle');
      if (debugBtnEl) {
        debugBtnEl.addEventListener('click', ()=>{
          const d = document.getElementById('api-debug');
          if (d.style.display === 'none' || d.style.display === '') { d.style.display = 'block'; debugBtnEl.textContent = 'Hide API debug'; }
          else { d.style.display = 'none'; debugBtnEl.textContent = 'Show API debug'; }
        });
      }
    }

    async function doLookup(){
      const name = input.value.trim();
      const qty = parseFloat(document.getElementById('qty').value) || 1;
      if (!name) return;
      await doLookupWithParams(name, null, null, qty);
    }

    async function doLookupWithParams(name, structure = null, variant = null, qty = 1){
      const out = document.getElementById('out');
      out.innerHTML = 'Loading…';
      try{
        let url = 'php/api.php?item=' + encodeURIComponent(name) + '&qty=' + qty;
        if (structure && variant) {
          url += '&structure=' + encodeURIComponent(structure) + '&variant=' + encodeURIComponent(variant);
        }
        const res = await fetch(url);
        const j = await res.json();
        console.log('api response', j);
        if (j.error) { out.textContent = j.error; return; }
        out.innerHTML = '';
        const h = document.createElement('div');
        h.innerHTML = `<h3>${j.name}</h3><p>Estimated time: ${Math.round(j.estimated_time_seconds)} seconds</p><p>Total Cost: ${j.total_cost} raw resources</p>`;
        out.appendChild(h);

        // If there are no requirements in any category, show a helpful message
        const hasAny = Object.keys(j.requirements || {}).some(cat => Object.keys(j.requirements[cat] || {}).length > 0);
        if (!hasAny) {
          const m = document.createElement('div');
          m.className = 'muted';
          m.textContent = 'No recipe/requirements found for this item.';
          out.appendChild(m);
          // if the API included a produced_by block, show its inputs/outputs and time
          if (j.produced_by) {
            const pb = j.produced_by;
            const pbWrap = document.createElement('div');
            pbWrap.innerHTML = '<h4>Produced By</h4>';

            // Render multiple options when present
            if (Array.isArray(pb.options) && pb.options.length) {
              // group options by category so we render Production, Item Production, Vehicle production
              const order = ['Production','Item Production','Vehicle production'];
              const groups = { 'Production': [], 'Item Production': [], 'Vehicle production': [] };
              pb.options.forEach((opt, idx) => { groups[opt.category || 'Production'].push({opt, idx}); });

              order.forEach(cat => {
                const arr = groups[cat];
                if (!arr || !arr.length) return;
                const h = document.createElement('h5'); h.textContent = cat; pbWrap.appendChild(h);
                arr.forEach(({opt, idx}) => {
                  const optEl = document.createElement('div');
                  optEl.className = 'opt-card';
                  const chosenMark = (pb.chosen_recipe_id && pb.chosen_recipe_id === opt.recipe_id) ? ' (chosen)' : '';
                  optEl.innerHTML = `<strong>Option ${idx+1} - recipe #${opt.recipe_id}${chosenMark}</strong><div>Recipe time: ${Math.round(opt.time_seconds)} seconds</div>`;

                  if (opt.structure) {
                    optEl.classList.add('clickable');
                    optEl.innerHTML = `<strong>${opt.structure} - ${opt.variant}</strong><div>Time: ${Math.round(opt.time_seconds)} seconds</div>`;
                    optEl.addEventListener('click', () => {
                      const qty = parseFloat(document.getElementById('qty').value) || 1;
                      doLookupWithParams(name, opt.structure, opt.variant, qty);
                    });
                  }

                  const inTable = document.createElement('table');
                  const inHeader = document.createElement('tr'); inHeader.innerHTML = '<th>Input</th><th>Amount</th>'; inTable.appendChild(inHeader);
                  Object.keys(opt.inputs).forEach(k => {
                    const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.inputs[k]}</td>`; inTable.appendChild(tr);
                  });
                  optEl.appendChild(inTable);

                  const outTable = document.createElement('table');
                  const outHeader = document.createElement('tr'); outHeader.innerHTML = '<th>Output</th><th>Amount</th>'; outTable.appendChild(outHeader);
                  Object.keys(opt.outputs).forEach(k => {
                    const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.outputs[k]}</td>`; outTable.appendChild(tr);
                  });
                  optEl.appendChild(outTable);

                  pbWrap.appendChild(optEl);
                });
              });
              // After listing options, also show summed materials for each category
              const materialsByCategory = { 'Production': {}, 'Item Production': {}, 'Vehicle production': {} };
              Object.keys(groups).forEach(cat => {
                const arr = groups[cat] || [];
                arr.forEach(({opt}) => {
                  Object.keys(opt.inputs || {}).forEach(name => {
                    const v = parseFloat(opt.inputs[name]) || 0;
                    if (!materialsByCategory[cat][name]) materialsByCategory[cat][name] = 0;
                    materialsByCategory[cat][name] += v;
                  });
                });
              });

              Object.keys(materialsByCategory).forEach(cat => {
                const map = materialsByCategory[cat];
                const keys = Object.keys(map);
                if (!keys.length) return;
                const mHead = document.createElement('h5'); mHead.textContent = cat + ' - Required Materials'; pbWrap.appendChild(mHead);
                const grid = document.createElement('div');
                grid.className = 'flex-grid';
                keys.forEach(k => {
                    const card = document.createElement('div');
                    card.className = 'stat-card';
                    card.innerHTML = `<div class="item-title">${k}</div><div class="item-qty">Qty: ${map[k]}</div>`;
                  grid.appendChild(card);
                });
                pbWrap.appendChild(grid);
              });
            } else if (pb.recipe_id) {
              // fallback for older single recipe responses
              const opt = pb;
              const optEl = document.createElement('div');
              optEl.innerHTML = `<strong>Recipe #${opt.recipe_id}</strong><div>Recipe time: ${Math.round(opt.time_seconds)} seconds</div>`;
                  if (opt.structure) {
                    optEl.classList.add('clickable');
                    optEl.innerHTML = `<strong>${opt.structure} - ${opt.variant}</strong><div>Time: ${Math.round(opt.time_seconds)} seconds</div>`;
                    optEl.addEventListener('click', () => {
                  const qty = parseFloat(document.getElementById('qty').value) || 1;
                  doLookupWithParams(name, opt.structure, opt.variant, qty);
                });
              }
              const inTable = document.createElement('table');
              const inHeader = document.createElement('tr'); inHeader.innerHTML = '<th>Input</th><th>Amount</th>'; inTable.appendChild(inHeader);
              Object.keys(opt.inputs).forEach(k => { const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.inputs[k]}</td>`; inTable.appendChild(tr); });
              optEl.appendChild(inTable);
              pbWrap.appendChild(optEl);
            }

            out.appendChild(pbWrap);
          } else {
            // also append the raw JSON for debugging if no produced_by info exists
            const pre = document.createElement('pre');
            pre.className = 'pre-box';
            pre.textContent = JSON.stringify(j, null, 2);
            out.appendChild(pre);
          }
          return;
        }

        // Render requirements by category; each resource appears in its own div
        Object.keys(j.requirements).forEach(cat => {
          // Skip rawResources category — no longer necessary to display
          if (cat === 'rawResources') return;
          const list = j.requirements[cat];
          const keys = Object.keys(list);
          if (!keys.length) return;
          const catWrap = document.createElement('div');
          catWrap.className = 'category-wrap';
          const h4 = document.createElement('h4'); h4.textContent = cat; catWrap.appendChild(h4);

          const itemsGrid = document.createElement('div');
          itemsGrid.className = 'items-grid';

          keys.forEach(k => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'item-card';
            itemDiv.innerHTML = `<div class="item-title">${k}</div><div class="item-qty">Qty: ${list[k]}</div>`;
            // add 'Where used' button
            const btn = document.createElement('button');
            btn.textContent = 'Where used';
            btn.dataset.name = k;
            btn.className = 'used-btn';
            itemDiv.appendChild(btn);
            // add 'Item Production' quick selector button
            const ipBtn = document.createElement('button');
            ipBtn.textContent = 'Item Production';
            ipBtn.dataset.name = k;
            ipBtn.className = 'item-prod-btn';
            ipBtn.dataset.name = k;
            ipBtn.className = 'item-prod-btn';
            itemDiv.appendChild(ipBtn);
            // add 'Produce' button for Salvage and Coke
            if (['Salvage', 'Coke'].includes(k)) {
              const produceBtn = document.createElement('button');
              produceBtn.textContent = 'Produce';
              produceBtn.dataset.name = k;
              produceBtn.dataset.qty = list[k];
              produceBtn.className = 'produce-btn';
              itemDiv.appendChild(produceBtn);
              const produceContainer = document.createElement('div');
              produceContainer.className = 'produce-container';
              // margin handled by CSS
              itemDiv.appendChild(produceContainer);
            }
            // placeholder for results
            const usedContainer = document.createElement('div');
            usedContainer.className = 'used-container';
            // margin handled by CSS
            itemDiv.appendChild(usedContainer);
            itemsGrid.appendChild(itemDiv);
          });

          catWrap.appendChild(itemsGrid);
          out.appendChild(catWrap);
        });

          // if calculator visible and multiplier not 1, update calc results
          const mult = parseFloat(document.getElementById('calc-mult')?.value || 1);
          const calcResults = document.getElementById('calc-results');
          if (calcResults) {
            // gather flat requirements
            const flat = {};
            // Prefer base_requirements (flattened raw resources) when available
            if (j.base_requirements && Object.keys(j.base_requirements).length) {
              Object.entries(j.base_requirements).forEach(([k,v]) => { flat[k] = (flat[k]||0) + v; });
            } else {
              Object.keys(j.requirements || {}).forEach(cat => {
                Object.entries(j.requirements[cat] || {}).forEach(([k,v]) => { flat[k] = (flat[k]||0) + v; });
              });
            }
            if (Object.keys(flat).length) {
              calcResults.innerHTML = '<strong>Scaled requirements (x'+mult+')</strong>';
              const ul = document.createElement('ul');
              Object.keys(flat).forEach(k=>{ const li = document.createElement('li'); li.textContent = k + ': ' + (flat[k]*mult); ul.appendChild(li); });
              calcResults.appendChild(ul);
            } else {
              calcResults.textContent = 'No requirements to scale.';
            }
          }
      }catch(e){
        out.textContent = 'Lookup failed';
      }
    }

    document.getElementById('btn').addEventListener('click', doLookup);
    input.addEventListener('keydown', (ev)=>{ if (ev.key === 'Enter') { ev.preventDefault(); doLookup(); } });

    loadItems();
    // Stockpile logic: simple localStorage-backed inventory
    const STOCK_KEY = 'pet_stockpile';
    function loadStockpile() {
      try { return JSON.parse(localStorage.getItem(STOCK_KEY) || '{}'); } catch(e) { return {}; }
    }
    function saveStockpile(obj) { localStorage.setItem(STOCK_KEY, JSON.stringify(obj)); }
    function renderStockpile() {
      const container = document.getElementById('stock-list');
      const data = loadStockpile();
      container.innerHTML = '';
      const keys = Object.keys(data).sort();
      if (!keys.length) { container.textContent = 'Stockpile empty.'; return; }
      const table = document.createElement('table');
      // table width handled by CSS
      const header = document.createElement('tr'); header.innerHTML = '<th>Item</th><th>Qty</th><th>Actions</th>'; table.appendChild(header);
      keys.forEach(k => {
        const tr = document.createElement('tr');
        const td1 = document.createElement('td'); td1.textContent = k; tr.appendChild(td1);
        const td2 = document.createElement('td'); td2.textContent = data[k]; tr.appendChild(td2);
        const td3 = document.createElement('td');
        const btnGoto = document.createElement('button'); btnGoto.textContent = 'Go'; btnGoto.className = 'btn btn-small'; btnGoto.addEventListener('click', ()=>{ input.value = k; doLookup(); });
        const btnDel = document.createElement('button'); btnDel.textContent = 'Delete'; btnDel.addEventListener('click', ()=>{ delete data[k]; saveStockpile(data); renderStockpile(); });
        td3.appendChild(btnGoto); td3.appendChild(btnDel); tr.appendChild(td3);
        table.appendChild(tr);
      });
      container.appendChild(table);
    }
    document.getElementById('stock-add').addEventListener('click', ()=>{
      const name = document.getElementById('stock-item-name').value.trim();
      const qty = parseFloat(document.getElementById('stock-item-qty').value) || 0;
      if (!name) return alert('Enter item name');
      const data = loadStockpile(); data[name] = qty; saveStockpile(data); renderStockpile();
    });
    document.getElementById('stock-clear').addEventListener('click', ()=>{ if (confirm('Clear all stock?')) { localStorage.removeItem(STOCK_KEY); renderStockpile(); } });
    document.getElementById('stock-export').addEventListener('click', ()=>{
      const data = loadStockpile(); const blob = new Blob([JSON.stringify(data, null, 2)], {type:'application/json'}); const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = 'stockpile.json'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    });
    document.getElementById('stock-import').addEventListener('click', ()=>{ document.getElementById('stock-import-data').click(); });
    document.getElementById('stock-import-data').addEventListener('change', (ev)=>{
      const f = ev.target.files[0]; if (!f) return; const reader = new FileReader(); reader.onload = ()=>{ try { const obj = JSON.parse(reader.result); saveStockpile(obj); renderStockpile(); alert('Imported'); } catch(e){ alert('Invalid JSON'); } }; reader.readAsText(f);
    });
    // initial render
    renderStockpile();
    // delegate click for Where used buttons
    itemsListEl.addEventListener('click', (ev)=>{
      // no-op: keep existing handlers intact
    });

    // navbar behavior
    document.querySelectorAll('.nav-btn').forEach(b=>{
      b.addEventListener('click', ()=>{
        const target = b.dataset.target;
        document.querySelectorAll('.nav-btn').forEach(x=>x.classList.remove('inactive'));
        document.querySelectorAll('#calculator,#officer,#stockpile').forEach(x=>x.classList.add('section-hidden'));
        if (target === 'main') {
          // show main view: ensure panels hidden
          document.querySelectorAll('#calculator,#officer,#stockpile').forEach(x=>x.classList.add('section-hidden'));
        } else if (target === 'calculator') {
          // Navigate to the standalone Calculator page instead of showing inline section
          window.location.href = 'Calculator.php';
          return;
        } else if (target === 'officer') {
          document.getElementById('officer').classList.remove('section-hidden');
        } else if (target === 'stockpile') {
          document.getElementById('stockpile').classList.remove('section-hidden');
        }
      });
    });

    // Run calculator: fetch aggregated requirements for a given item and quantity
    const _calcRun = document.getElementById('calc-run');
    if (_calcRun) {
      _calcRun.addEventListener('click', calcRun);
    }

    // Global handler for used-btn clicks (works for dynamically added cards)
    document.body.addEventListener('click', async (ev) => {
            // handle material breakdown button in calculator
            if (ev.target && ev.target.classList && ev.target.classList.contains('material-breakdown-btn')) {
              const name = ev.target.dataset.name;
              const qty = parseFloat(ev.target.dataset.qty) || 1;
              const container = ev.target.parentElement.querySelector('.material-breakdown');
              if (!container) return;
              container.innerHTML = 'Loading breakdown...';
              try {
                const r = await fetch('php/api.php?item=' + encodeURIComponent(name) + '&qty=' + encodeURIComponent(qty));
                const jj = await r.json();
                container.innerHTML = '';
                // If API provides production options, list them and fetch per-option raw breakdowns
                if (jj.produced_by && Array.isArray(jj.produced_by.options) && jj.produced_by.options.length) {
                  jj.produced_by.options.forEach(async (opt, idx) => {
                    const optWrap = document.createElement('div');
                    optWrap.style.border = '1px solid #ddd'; optWrap.style.padding = '8px'; optWrap.style.marginBottom = '8px';
                    const title = document.createElement('div');
                    title.innerHTML = `<strong>${opt.structure ? opt.structure : 'Structure'}${opt.variant ? ' ('+opt.variant+')' : ''}</strong> — Time: ${Math.round(opt.time_seconds || 0)}s`;
                    optWrap.appendChild(title);

                    // inputs table
                    const inTbl = document.createElement('table'); inTbl.style.marginTop = '6px'; const inH = document.createElement('tr'); inH.innerHTML = '<th>Input</th><th>Amount</th>'; inTbl.appendChild(inH);
                    Object.keys(opt.inputs || {}).forEach(k => { const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.inputs[k]}</td>`; inTbl.appendChild(tr); });
                    optWrap.appendChild(inTbl);

                    // outputs table
                    const outTbl = document.createElement('table'); outTbl.style.marginTop = '6px'; const outH = document.createElement('tr'); outH.innerHTML = '<th>Output</th><th>Amount</th>'; outTbl.appendChild(outH);
                    Object.keys(opt.outputs || {}).forEach(k => { const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.outputs[k]}</td>`; outTbl.appendChild(tr); });
                    optWrap.appendChild(outTbl);

                    // placeholder for raw resources breakdown per option
                    const brDiv = document.createElement('div'); brDiv.style.marginTop = '8px'; brDiv.textContent = 'Loading raw resources...'; optWrap.appendChild(brDiv);
                    container.appendChild(optWrap);

                    // fetch breakdown for this specific option by calling API with structure/variant
                    if (opt.structure) {
                      try {
                        const qres = await fetch('php/api.php?item=' + encodeURIComponent(name) + '&qty=' + encodeURIComponent(qty) + '&structure=' + encodeURIComponent(opt.structure) + '&variant=' + encodeURIComponent(opt.variant || ''));
                        const qj = await qres.json();
                        const br = qj.base_requirements && Object.keys(qj.base_requirements).length ? qj.base_requirements : (qj.requirements ? Object.values(qj.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
                        if (!br || Object.keys(br).length === 0) {
                          brDiv.textContent = 'No raw resource breakdown available for this option.';
                        } else {
                          brDiv.innerHTML = '<strong>Raw resources required:</strong>';
                          const ul = document.createElement('ul');
                          let total = 0;
                          Object.keys(br).forEach(k => { const li = document.createElement('li'); li.textContent = k + ': ' + br[k]; ul.appendChild(li); if (typeof br[k] === 'number') total += br[k]; });
                          brDiv.appendChild(ul);
                          const tot = document.createElement('div'); tot.style.fontWeight = '600'; tot.textContent = 'Total raw resources (sum): ' + total; brDiv.appendChild(tot);
                        }
                      } catch(e) {
                        brDiv.textContent = 'Failed to fetch option breakdown';
                      }
                    } else {
                      // option lacks structure metadata; show quick raw map if available
                      const br = jj.base_requirements && Object.keys(jj.base_requirements).length ? jj.base_requirements : null;
                      if (br) {
                        brDiv.innerHTML = '<strong>Raw resources required (approx):</strong>';
                        const ul = document.createElement('ul'); Object.keys(br).forEach(k => { const li = document.createElement('li'); li.textContent = k + ': ' + br[k]; ul.appendChild(li); }); brDiv.appendChild(ul);
                      } else {
                        brDiv.textContent = 'No raw resource breakdown available.';
                      }
                    }
                  });
                } else {
                  // prefer base_requirements if present
                  const br = jj.base_requirements && Object.keys(jj.base_requirements).length ? jj.base_requirements : (jj.requirements ? Object.values(jj.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
                  if (!br || Object.keys(br).length === 0) {
                    container.textContent = 'No breakdown available.';
                  } else {
                    const ul = document.createElement('ul');
                    Object.keys(br).forEach(k => { const li = document.createElement('li'); li.textContent = k + ': ' + br[k]; ul.appendChild(li); });
                    container.appendChild(ul);
                  }
                }
              } catch (err) {
                container.textContent = 'Failed to load breakdown';
              }
              return;
            }
            if (ev.target.matches && ev.target.matches('.used-btn')) {
      const name = ev.target.dataset.name;
      const container = ev.target.parentElement.querySelector('.used-container');
      if (!container) return;
      container.innerHTML = 'Loading...';
      try {
        const res = await fetch('php/api.php?used_by=' + encodeURIComponent(name));
        const j = await res.json();
        const rows = j.used_by || [];
        if (!rows.length) {
          container.textContent = 'No products found that use this material.';
          return;
        }
        const ul = document.createElement('ul');
                // If rows include structure metadata, show unique structures instead of per-product entries
                const hasStructure = rows.some(r => r.structure);
                if (hasStructure) {
                  // group by normalized structure name to avoid duplicates (show building once)
                  const structMap = {};
                  rows.forEach(r => {
                    const structRaw = (r.structure || '').trim();
                    const structKey = structRaw.toLowerCase();
                    if (!structKey) return;
                    if (!structMap[structKey]) structMap[structKey] = { structure: structRaw, variants: {}, products: [] };
                    const variant = (r.variant || '').trim();
                    if (variant) {
                      if (!structMap[structKey].variants[variant]) structMap[structKey].variants[variant] = [];
                      if (r.name && !structMap[structKey].variants[variant].includes(r.name)) structMap[structKey].variants[variant].push(r.name);
                    } else {
                      if (r.name && !structMap[structKey].products.includes(r.name)) structMap[structKey].products.push(r.name);
                    }
                  });
                  Object.keys(structMap).forEach(key => {
                    const s = structMap[key];
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'used-structure';
                    a.dataset.structure = s.structure;
                    a.textContent = s.structure;
                    const li = document.createElement('li');
                    li.appendChild(a);
                    // attach hidden details container with product links grouped by variant
                    const details = document.createElement('div');
                    details.style.display = 'none';
                    details.className = 'structure-products';
                    const pl = document.createElement('div');
                    if (Object.keys(s.variants).length) {
                      Object.keys(s.variants).forEach(v => {
                        const subh = document.createElement('div'); subh.style.fontWeight = '600'; subh.textContent = v; pl.appendChild(subh);
                        const subul = document.createElement('ul');
                        s.variants[v].forEach(pn => { const pa = document.createElement('a'); pa.href = '#'; pa.className = 'used-item'; pa.dataset.name = pn; pa.textContent = pn; const pli = document.createElement('li'); pli.appendChild(pa); subul.appendChild(pli); });
                        pl.appendChild(subul);
                      });
                    }
                    if (s.products && s.products.length) {
                      const subh = document.createElement('div'); subh.style.fontWeight = '600'; subh.textContent = 'Products'; pl.appendChild(subh);
                      const subul = document.createElement('ul');
                      s.products.forEach(pn => { const pa = document.createElement('a'); pa.href = '#'; pa.className = 'used-item'; pa.dataset.name = pn; pa.textContent = pn; const pli = document.createElement('li'); pli.appendChild(pa); subul.appendChild(pli); });
                      pl.appendChild(subul);
                    }
                    details.appendChild(pl);
                    li.appendChild(details);
                    ul.appendChild(li);
                  });
                } else {
                  rows.forEach(r => {
                    const a = document.createElement('a');
                    a.href = '#';
                    a.className = 'used-item';
                    a.dataset.name = r.name;
                    a.dataset.category = r.category || '';
                    a.dataset.time = r.time_seconds || '';
                    if (r.structure) a.dataset.structure = r.structure;
                    if (r.variant) a.dataset.variant = r.variant;
                    // display structure/variant when available, otherwise show product name
                    if (r.structure) {
                      a.textContent = (r.variant ? (r.variant + ' — ') : '') + r.structure + ' (' + r.name + ')';
                    } else {
                      a.textContent = r.name + (r.recipe_id ? (' (recipe #' + r.recipe_id + ')') : '');
                    }
                    const li = document.createElement('li');
                    li.appendChild(a);
                    ul.appendChild(li);
                  });
                }
        container.innerHTML = '';
        container.appendChild(ul);
      } catch (e) {
        container.textContent = 'Lookup failed';
      }
              return;
            }

            // Item Production quick selector: show only Item Production category entries with radio/select
            if (ev.target.matches && ev.target.matches('.item-prod-btn')) {
              const name = ev.target.dataset.name;
              const container = ev.target.parentElement.querySelector('.used-container');
              if (!container) return;
              container.innerHTML = 'Loading Item Production...';
              try {
                const res = await fetch('php/api.php?used_by=' + encodeURIComponent(name));
                const j = await res.json();
                const rows = (j.used_by || []).filter(r => (r.category || '') === 'Item Production');
                if (!rows.length) {
                  container.textContent = 'No Item Production entries found for this material.';
                  return;
                }
                const form = document.createElement('div');
                const list = document.createElement('div');
                rows.forEach((r, idx) => {
                  const label = document.createElement('label');
                  label.style.display = 'block';
                  const radio = document.createElement('input');
                  radio.type = 'radio';
                  radio.name = 'ip-select-' + name;
                  radio.value = r.name; // keep product name as the value to lookup when selected
                  if (idx === 0) radio.checked = true;
                  label.appendChild(radio);
                  // build descriptive label: structure — variant — product (time)
                  let labelText = '';
                  if (r.structure) {
                    labelText += r.structure;
                    if (r.variant) labelText += ' — ' + r.variant;
                    labelText += ' — ';
                  }
                  labelText += r.name + ' (recipe #' + (r.recipe_id || '-') + ', ' + (r.time_seconds || '-') + 's)';
                  label.appendChild(document.createTextNode(' ' + labelText));
                  list.appendChild(label);
                });
                const useBtn = document.createElement('button');
                useBtn.textContent = 'Use selected';
                useBtn.style.marginTop = '8px';
                useBtn.addEventListener('click', () => {
                  const sel = form.querySelector('input[type=radio]:checked');
                  if (!sel) return;
                  input.value = sel.value;
                  doLookup();
                });
                form.appendChild(list);
                form.appendChild(useBtn);
                container.innerHTML = '';
                container.appendChild(form);
              } catch (e) {
                container.textContent = 'Lookup failed';
              }
              return;
            }

            if (ev.target.matches && ev.target.matches('.used-item')) {
              ev.preventDefault();
              const name = ev.target.dataset.name;
              input.value = name;
              doLookup();
              return;
            }

            // toggle structure details when a structure link is clicked
            if (ev.target.matches && ev.target.matches('.used-structure')) {
              ev.preventDefault();
              const li = ev.target.parentElement;
              const details = li.querySelector('.structure-products');
              if (!details) return;
              details.style.display = details.style.display === 'none' ? 'block' : 'none';
              return;
            }

            if (ev.target.classList.contains('produce-btn')) {
              const name = ev.target.dataset.name;
              const qty = ev.target.dataset.qty;
              const container = ev.target.nextElementSibling;
              container.innerHTML = 'Loading options...';
              fetch('php/api.php?item=' + encodeURIComponent(name))
                .then(r => r.json())
                .then(j => {
                  if (j.produced_by && j.produced_by.options) {
                    container.innerHTML = '';
                    j.produced_by.options.forEach(opt => {
                      if (opt.structure) {
                        const btn = document.createElement('button');
                        btn.textContent = `${opt.structure} - ${opt.variant}`;
                        btn.addEventListener('click', () => {
                          container.innerHTML = 'Calculating...';
                          fetch('php/api.php?item=' + encodeURIComponent(name) + '&structure=' + encodeURIComponent(opt.structure) + '&variant=' + encodeURIComponent(opt.variant) + '&qty=' + qty)
                            .then(r => r.json())
                            .then(j2 => {
                              container.innerHTML = '<h5>Inputs needed:</h5>';
                              Object.keys(j2.requirements).forEach(cat => {
                                const h6 = document.createElement('h6'); h6.textContent = cat; container.appendChild(h6);
                                const ul = document.createElement('ul');
                                Object.keys(j2.requirements[cat]).forEach(k => {
                                  const li = document.createElement('li');
                                  li.textContent = `${k}: ${j2.requirements[cat][k]}`;
                                  ul.appendChild(li);
                                });
                                container.appendChild(ul);
                              });
                              container.innerHTML += `<p>Time: ${Math.round(j2.estimated_time_seconds)} seconds</p>`;
                            });
                        });
                        container.appendChild(btn);
                        container.appendChild(document.createElement('br'));
                      }
                    });
                  } else {
                    container.textContent = 'No production options';
                  }
                });
              return;
            }
    });

    // Stockpile management
    const stockpile = JSON.parse(localStorage.getItem('stockpile') || '{}');
    function updateStockDisplay() {
      const list = document.getElementById('stock-list');
      list.innerHTML = '';
      Object.keys(stockpile).forEach(k => {
        const div = document.createElement('div');
        div.textContent = `${k}: ${stockpile[k]}`;
        list.appendChild(div);
      });
    }
    updateStockDisplay();

    document.getElementById('stock-add').addEventListener('click', () => {
      const name = document.getElementById('stock-item-name').value.trim();
      const qty = parseFloat(document.getElementById('stock-item-qty').value);
      if (name && qty >= 0) {
        stockpile[name] = qty;
        localStorage.setItem('stockpile', JSON.stringify(stockpile));
        updateStockDisplay();
      }
    });

    document.getElementById('stock-clear').addEventListener('click', () => {
      Object.keys(stockpile).forEach(k => delete stockpile[k]);
      localStorage.removeItem('stockpile');
      updateStockDisplay();
    });

    document.getElementById('stock-export').addEventListener('click', () => {
      const data = JSON.stringify(stockpile, null, 2);
      navigator.clipboard.writeText(data).then(() => alert('Stockpile copied to clipboard'));
    });

    document.getElementById('stock-import').addEventListener('change', (e) => {
      const file = e.target.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = (e) => {
          try {
            const data = JSON.parse(e.target.result);
            Object.assign(stockpile, data);
            localStorage.setItem('stockpile', JSON.stringify(stockpile));
            updateStockDisplay();
          } catch (err) {
            alert('Invalid JSON file');
          }
        };
        reader.readAsText(file);
      }
    });

    // Calculator
    // currentCalc holds the displayed flattened base/materials totals so option selections can modify them
    window.currentCalc = null;
    async function calcRun() {
      const item = document.getElementById('calc-item').value.trim() || input.value.trim();
      const qty = parseFloat(document.getElementById('calc-qty').value) || 1;
      const subtract = document.getElementById('calc-subtract-stock').checked;
      const results = document.getElementById('calc-results');
      results.innerHTML = 'Calculating...';
      try {
        const res = await fetch('php/api.php?item=' + encodeURIComponent(item) + '&qty=' + qty);
        const j = await res.json();
        if (j.error) { results.textContent = j.error; return; }
        // Prefer base_requirements (flattened raw resource totals) when available
        const base = j.base_requirements && Object.keys(j.base_requirements).length ? JSON.parse(JSON.stringify(j.base_requirements)) : null;
        const mats = j.materials_needed && Object.keys(j.materials_needed).length ? JSON.parse(JSON.stringify(j.materials_needed)) : null;
        // fallback grouped requirements
        let grouped = JSON.parse(JSON.stringify(j.requirements || {})); // deep copy

        // Subtract stockpile from all representations if requested
        if (subtract) {
          // Subtract from base if present
          if (base) {
            Object.keys(stockpile).forEach(sname => {
              if (base[sname]) {
                base[sname] = base[sname] - stockpile[sname];
                if (base[sname] <= 0) delete base[sname];
              }
            });
          }
          // Subtract from mats if present
          if (mats) {
            Object.keys(stockpile).forEach(sname => {
              if (mats[sname]) {
                mats[sname] = mats[sname] - stockpile[sname];
                if (mats[sname] <= 0) delete mats[sname];
              }
            });
          }
          // Subtract from grouped as a fallback display
          Object.keys(grouped).forEach(cat => {
            Object.keys(grouped[cat]).forEach(k => {
              if (stockpile[k]) {
                grouped[cat][k] -= stockpile[k];
                if (grouped[cat][k] <= 0) delete grouped[cat][k];
              }
            });
            if (Object.keys(grouped[cat]).length === 0) delete grouped[cat];
          });
        }

        // store current calculation state so option selections can update it
        window.currentCalc = { base: base ? JSON.parse(JSON.stringify(base)) : {}, mats: mats ? JSON.parse(JSON.stringify(mats)) : {}, grouped: grouped ? JSON.parse(JSON.stringify(grouped)) : {}, item: item, qty: qty, subtract: subtract };

        results.innerHTML = `<h4>Requirements for ${qty} x ${item}</h4>`;
        if (subtract) results.innerHTML += '<p>(After subtracting stockpile)</p>';

        // If base (flattened raw resources) is present, show it first
        if (base && Object.keys(base).length) {
          const h5 = document.createElement('h5'); h5.textContent = 'Raw resources (flattened)'; results.appendChild(h5);
          const ul = document.createElement('ul');
          Object.keys(base).forEach(k => { const li = document.createElement('li'); li.textContent = `${k}: ${base[k]}`; ul.appendChild(li); });
          results.appendChild(ul);
        }

        // Show materials totals (if provided) with per-material breakdown buttons
        if (mats && Object.keys(mats).length) {
          const h5m = document.createElement('h5'); h5m.textContent = 'Materials needed (total)'; results.appendChild(h5m);
          const ulm = document.createElement('ul');
          Object.keys(mats).forEach(k => {
            const li = document.createElement('li');
            li.textContent = `${k}: ${mats[k]} `;
            // breakdown button
            const btn = document.createElement('button');
            btn.textContent = 'Show breakdown';
            btn.style.marginLeft = '8px';
            btn.className = 'material-breakdown-btn';
            btn.dataset.name = k;
            btn.dataset.qty = mats[k];
            li.appendChild(btn);
            // container for breakdown
            const br = document.createElement('div'); br.className = 'material-breakdown'; br.style.marginTop = '6px'; li.appendChild(br);
            ulm.appendChild(li);
          });
          results.appendChild(ulm);
        }

        // Detailed production tree: for each material, fetch its breakdown and show
        if (mats && Object.keys(mats).length) {
          const treeWrap = document.createElement('div');
          treeWrap.style.marginTop = '12px';
          treeWrap.innerHTML = '<h4>Detailed production tree</h4>';
          results.appendChild(treeWrap);
          // fetch breakdowns in parallel
          await Promise.all(Object.keys(mats).map(async (matName) => {
            const matQty = mats[matName];
            const node = document.createElement('div');
            node.style.border = '1px dashed #ccc'; node.style.padding = '8px'; node.style.margin = '6px 0';
            const title = document.createElement('div'); title.style.fontWeight = '700'; title.textContent = `${matName} — ${matQty}`;
            node.appendChild(title);

            try {
              const resp = await fetch('php/api.php?item=' + encodeURIComponent(matName) + '&qty=' + encodeURIComponent(matQty));
              const sub = await resp.json();

              // Show materials/liquids needed to produce this material (materials_needed if present)
              const subs = sub.materials_needed && Object.keys(sub.materials_needed).length ? sub.materials_needed : (sub.requirements ? Object.values(sub.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
              if (subs && Object.keys(subs).length) {
                const subWrap = document.createElement('div'); subWrap.style.marginTop = '8px';
                subWrap.innerHTML = '<div style="font-weight:600">Materials / Liquids required to produce this:</div>';
                const sul = document.createElement('ul');
                Object.keys(subs).forEach(sn => {
                  const sli = document.createElement('li'); sli.textContent = `${sn}: ${subs[sn]}`; sul.appendChild(sli);
                });
                subWrap.appendChild(sul);
                node.appendChild(subWrap);
              }

              // Show raw resources for this material
              const br = sub.base_requirements && Object.keys(sub.base_requirements).length ? sub.base_requirements : (sub.requirements ? Object.values(sub.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
              if (br && Object.keys(br).length) {
                const brWrap = document.createElement('div'); brWrap.style.marginTop = '8px'; brWrap.innerHTML = '<div style="font-weight:600">Raw resources to produce this material:</div>';
                const bul = document.createElement('ul'); let totalRaw = 0;
                Object.keys(br).forEach(rn => { const rli = document.createElement('li'); rli.textContent = `${rn}: ${br[rn]}`; bul.appendChild(rli); if (typeof br[rn] === 'number') totalRaw += br[rn]; });
                brWrap.appendChild(bul);
                const totDiv = document.createElement('div'); totDiv.style.fontWeight = '600'; totDiv.textContent = 'Total raw resources (sum): ' + totalRaw;
                brWrap.appendChild(totDiv);
                node.appendChild(brWrap);
              }
            } catch (err) {
              const errDiv = document.createElement('div'); errDiv.textContent = 'Failed to load breakdown: ' + err.message; node.appendChild(errDiv);
            }

            treeWrap.appendChild(node);
          }));
        }

        // helper to re-render calculator results from window.currentCalc
        function renderCalcFromCurrent() {
          const curr = window.currentCalc || {};
          const baseObj = curr.base || {};
          const matsObj = curr.mats || {};
          // rebuild results area
          results.innerHTML = `<h4>Requirements for ${curr.qty} x ${curr.item}</h4>` + (curr.subtract ? '<p>(After subtracting stockpile)</p>' : '');

          if (Object.keys(baseObj).length) {
            const h5 = document.createElement('h5'); h5.textContent = 'Raw resources (flattened)'; results.appendChild(h5);
            const ul2 = document.createElement('ul');
            Object.keys(baseObj).forEach(k => { const li = document.createElement('li'); li.textContent = `${k}: ${baseObj[k]}`; ul2.appendChild(li); });
            results.appendChild(ul2);
          }

          if (Object.keys(matsObj).length) {
            const h5m = document.createElement('h5'); h5m.textContent = 'Materials needed (total)'; results.appendChild(h5m);
            const ulm2 = document.createElement('ul');
            Object.keys(matsObj).forEach(k => { const li = document.createElement('li'); li.textContent = `${k}: ${matsObj[k]}`; ulm2.appendChild(li); });
            results.appendChild(ulm2);
          }

          // grouped fallback
          if (Object.keys(curr.grouped || {}).length) {
            const h5g = document.createElement('h5'); h5g.textContent = 'Requirements by category'; results.appendChild(h5g);
            Object.keys(curr.grouped).forEach(cat => {
              const h5 = document.createElement('h6'); h5.textContent = cat; results.appendChild(h5);
              const ul = document.createElement('ul');
              Object.keys(curr.grouped[cat]).forEach(k => { const li = document.createElement('li'); li.textContent = `${k}: ${curr.grouped[cat][k]}`; ul.appendChild(li); });
              results.appendChild(ul);
            });
          }
        }

        // As fallback, show grouped requirements by category
        if (Object.keys(grouped).length) {
          const h5g = document.createElement('h5'); h5g.textContent = 'Requirements by category'; results.appendChild(h5g);
          Object.keys(grouped).forEach(cat => {
            const h5 = document.createElement('h6'); h5.textContent = cat; results.appendChild(h5);
            const ul = document.createElement('ul');
            Object.keys(grouped[cat]).forEach(k => { const li = document.createElement('li'); li.textContent = `${k}: ${grouped[cat][k]}`; ul.appendChild(li); });
            results.appendChild(ul);
          });
        } else if (!base && !mats) {
          results.innerHTML += '<p>All requirements met from stockpile or no requirements found.</p>';
        }
      } catch (e) {
        results.textContent = 'Error: ' + e.message;
      }
    }

    // calc-run listener is attached conditionally earlier when present
  </script>
  <script>
    // Tabs: simple switcher for items/news
    (function(){
      const btns = document.querySelectorAll('.tab-btn');
      function activate(target){
        document.getElementById('tab-items').style.display = target === 'items' ? 'block' : 'none';
        document.getElementById('tab-news').style.display = target === 'news' ? 'block' : 'none';
        btns.forEach(b=> b.classList.toggle('active', b.dataset.target === target));
      }
      btns.forEach(b=> b.addEventListener('click', ()=> activate(b.dataset.target)));
      // default: prefer Newsfeed first, fallback to first tab
      const newsBtn = document.querySelector('.tab-btn[data-target="news"]');
      const defaultTarget = newsBtn ? 'news' : (btns && btns.length ? (btns[0].dataset.target || 'items') : 'items');
      activate(defaultTarget);
    })();
  </script>
  <script src="js/calculator_ext.js"></script>
  </main>
  <footer class="site-footer">
    Created by <strong>Hypha</strong> — <a href="https://cv.tiebocroons.be" target="_blank" rel="noopener noreferrer">cv.tiebocroons.be</a>
  </footer>
</body>
</html>
