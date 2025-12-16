<?php
// Standalone Calculator page (moved from index.php)
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>HUSS Calculator</title>
  <link rel="stylesheet" href="css/calculator.css">
  <link rel="stylesheet" href="css/index.css">
</head>
<body>
  <main class="site-main">
  <div class="container">
    <nav class="navbar">
      <div class="brand">
        <div class="logo">HUSS</div>
        <div>
          <div class="title">HUSS Calculator (Standalone)</div>
          <div class="sub">Quick requirements for items — standalone mode</div>
        </div>
      </div>
      <div class="nav-actions">
        <a class="nav-link" href="index.php">Home</a>
      </div>
    </nav>
    

    <div id="calculator" class="card calculator">
      <div class="panel controls">
        <label>Item (name): <input id="calc-item" list="calc-item-list" type="text" placeholder="Item name" autocomplete="off"/></label>
        <datalist id="calc-item-list"></datalist>
        <label>Quantity: <input id="calc-qty" type="number" value="1" min="0" step="0.01"/></label>
        <label class="small"><input id="calc-subtract-stock" type="checkbox"/> Subtract stockpile quantities</label>
        <!-- Target window and presets removed: planner will use default 3600s -->
        <div style="margin:8px 0"><button id="calc-run" class="btn">Calculate Requirements</button></div>
      </div>

      <div class="panel results" style="flex:1">
        <div id="detailed-area">
          <div id="calc-results" class="results"><div class="message">Enter an item and quantity, then click Calculate.</div></div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Minimal calculator logic that talks to php/api.php and renders results
    const calcItem = document.getElementById('calc-item');
    const calcQty = document.getElementById('calc-qty');
    const calcResults = document.getElementById('calc-results');
    const detailedArea = document.getElementById('detailed-area');

    // populate datalist for item suggestions
    const calcItemList = document.getElementById('calc-item-list');
    async function populateCalcItemList(){
      if (!calcItemList) return;
      try{
        // use an explicit relative path to the API to avoid ambiguity
        const resp = await fetch('./php/api.php?items=1');
        const txt = await resp.text();
        // log raw response for easier debugging in browser console
        try{ console.debug('api.items response raw:', txt); }catch(e){}
        const j = txt ? JSON.parse(txt) : {};
        const items = Array.isArray(j.items) ? j.items : [];
        // clear existing
        calcItemList.innerHTML = '';
        if (items.length === 0) {
          // provide a visible placeholder so user can see something is wrong
          const o = document.createElement('option'); o.value = '';
          o.textContent = 'No items (check API response)';
          calcItemList.appendChild(o);
        } else {
          items.forEach(it => { try{ const o = document.createElement('option'); o.value = it; calcItemList.appendChild(o);}catch(e){} });
        }
      }catch(e){
        try{ console.error('Failed to populate item list', e); }catch(err){}
        calcItemList.innerHTML = '';
        const o = document.createElement('option'); o.value = '';
        o.textContent = 'Failed to load items';
        calcItemList.appendChild(o);
      }
    }
    // populate once on load; could be refreshed later if needed
    populateCalcItemList();

    async function calcRunStandalone(){
      const item = calcItem.value.trim();
      const qty = parseFloat(calcQty.value) || 1;
      const subtract = document.getElementById('calc-subtract-stock').checked;
      if (!item) { calcResults.textContent = 'Enter an item name'; return; }
      calcResults.innerHTML = 'Calculating...';
      try{
        const targetSeconds = 3600; // default 1 hour
        const res = await fetch('php/api.php?item=' + encodeURIComponent(item) + '&qty=' + encodeURIComponent(qty) + '&target_seconds=' + encodeURIComponent(targetSeconds));
        const j = await res.json();
        if (j.error) { calcResults.textContent = j.error; return; }
        // Minimal confirmation — detailed requirements and options are handled in the Detailed Planner
        calcResults.innerHTML = `<h4>Calculation complete for ${qty} x ${j.name}</h4>`;
        // style and ensure results are visible to the user
        try{ calcResults.classList.add('result-card'); calcResults.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){}
        // derive base and mats from response (ensure variables exist)
        const base = j.base_requirements && Object.keys(j.base_requirements).length ? j.base_requirements : {};
        const mats = j.materials_needed && Object.keys(j.materials_needed).length ? j.materials_needed : {};
        // store current calc state for external use
        window.currentCalc = { item: j.name, qty: qty, base: base, mats: mats, grouped: j.requirements || {} };
      }catch(e){ calcResults.textContent = 'Error: ' + e.message; }
    }

    document.getElementById('calc-run').addEventListener('click', calcRunStandalone);

    // presets removed; using default targetSeconds = 3600

    // Material breakdown buttons handler (delegated). Create a single handler and attach
    // it both to the results container and to the detailed-area for robustness.
    async function breakdownClickHandler(ev){
      const t = ev.target;
      if (!(t && t.classList && t.classList.contains('material-breakdown-btn'))) return;
      const name = t.dataset.name; const qty = parseFloat(t.dataset.qty) || 1;
      let container = t.parentElement.querySelector('.material-breakdown');
      if (!container) { container = document.createElement('div'); container.className = 'material-breakdown'; t.parentElement.appendChild(container); }
      container.innerHTML = '<div class="breakdown-loading">Loading breakdown...</div>';
      try{
        const r = await fetch('php/api.php?item=' + encodeURIComponent(name) + '&qty=' + encodeURIComponent(qty));
        const jj = await r.json(); container.innerHTML = '';
        if (jj.produced_by && Array.isArray(jj.produced_by.options) && jj.produced_by.options.length){
          jj.produced_by.options.forEach(async (opt, idx)=>{
            const optWrap = document.createElement('div'); optWrap.className = 'breakdown-option';
            const title = document.createElement('div'); title.className = 'breakdown-title'; title.innerHTML = `${opt.structure || 'Structure'}${opt.variant ? ' ('+opt.variant+')' : ''} — Time: ${Math.round(opt.time_seconds||0)}s`; optWrap.appendChild(title);
            const inTbl = document.createElement('table'); inTbl.className = 'requirements'; const inH = document.createElement('tr'); inH.innerHTML = '<th>Input</th><th>Amount</th>'; inTbl.appendChild(inH);
            Object.keys(opt.inputs || {}).forEach(k => { const tr = document.createElement('tr'); tr.innerHTML = `<td>${k}</td><td>${opt.inputs[k]}</td>`; inTbl.appendChild(tr); }); optWrap.appendChild(inTbl);
            const brDiv = document.createElement('div'); brDiv.className = 'material-breakdown breakdown-loading'; brDiv.textContent = 'Loading raw resources...'; optWrap.appendChild(brDiv);
            container.appendChild(optWrap);
            if (opt.structure) {
              try{
                const qres = await fetch('php/api.php?item=' + encodeURIComponent(name) + '&qty=' + encodeURIComponent(qty) + '&structure=' + encodeURIComponent(opt.structure) + '&variant=' + encodeURIComponent(opt.variant || ''));
                const qj = await qres.json();
                const br = qj.base_requirements && Object.keys(qj.base_requirements).length ? qj.base_requirements : (qj.requirements ? Object.values(qj.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
                if (!br || Object.keys(br).length === 0) { brDiv.textContent = 'No raw resource breakdown available for this option.'; brDiv.classList.remove('breakdown-loading'); }
                else {
                  brDiv.classList.remove('breakdown-loading');
                  brDiv.innerHTML = '<strong>Raw resources required:</strong>';
                  const ul = document.createElement('ul'); let total=0; Object.keys(br).forEach(k=>{ const li=document.createElement('li'); li.textContent = k + ': ' + br[k]; ul.appendChild(li); if(typeof br[k]==='number') total += br[k]; }); brDiv.appendChild(ul);
                  const tot = document.createElement('div'); tot.className = 'small'; tot.style.fontWeight='600'; tot.textContent = 'Total raw resources (sum): ' + total; brDiv.appendChild(tot);
                }
              }catch(e){ brDiv.textContent = 'Failed to fetch option breakdown'; }
            }
          });
        } else {
          const br = jj.base_requirements && Object.keys(jj.base_requirements).length ? jj.base_requirements : (jj.requirements ? Object.values(jj.requirements).reduce((a,b)=>Object.assign(a,b), {}) : {});
          if (!br || Object.keys(br).length===0) container.textContent = 'No breakdown available.'; else { const ul=document.createElement('ul'); Object.keys(br).forEach(k=>{ const li=document.createElement('li'); li.textContent = k + ': ' + br[k]; ul.appendChild(li); }); container.appendChild(ul); }
        }
      }catch(err){ container.textContent = 'Failed to load breakdown'; }
    }

    if (calcResults && calcResults.addEventListener) calcResults.addEventListener('click', breakdownClickHandler);
    if (detailedArea && detailedArea.addEventListener) detailedArea.addEventListener('click', breakdownClickHandler);
  </script>
  <script src="js/calculator_ext.js"></script>
  </main>
  <footer class="site-footer">
    Created by <strong>Hypha</strong> — <a href="https://cv.tiebocroons.be" target="_blank" rel="noopener noreferrer">cv.tiebocroons.be</a>
  </footer>
</body>
</html>
