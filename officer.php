<?php
require_once __DIR__ . '/database.php';
// Require auth and enforce role-based access: only Officer, Head of Facility, Head of MPF
require_once __DIR__ . '/auth.php';
$current = current_user();
if (!$current) {
  // not logged in -> redirect to login
  header('Location: login.php');
  exit;
}
$allowed_roles = ['Officer', 'Head of Facility', 'Head of MPF'];
if (!in_array(isset($current['role']) ? $current['role'] : '', $allowed_roles, true)) {
  // Log denied access attempt to user_audit (if DB available)
  try {
    $user_id = isset($current['id']) ? $current['id'] : null;
    if ($user_id !== null) {
      $details = json_encode([
        'page' => 'officer.php',
        'attempted_role' => isset($current['role']) ? $current['role'] : null,
        'remote_ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'],0,200) : null,
      ]);
      dbExecute('INSERT INTO user_audit (user_id, changed_by, action, details) VALUES (?,?,?,?)', [$user_id, null, 'denied_officer_access', $details]);
    }
  } catch (Exception $e) { /* ignore audit failures */ }
  http_response_code(403);
  echo "<h1>403 Forbidden</h1><p>Access denied. You do not have sufficient privileges to view this page.</p>";
  exit;
}

// Verify database connection early and provide a clear message if it fails.
try {
    $pdo = getDatabaseConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo "<h1>Database connection error</h1><p>Unable to connect to the database: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check <code>database.php</code> and your DB server (MySQL/SQLite).</p>";
    exit;
}

// Simple JSON response helper
function jsonResp($data){ header('Content-Type: application/json'); echo json_encode($data); exit; }

// Simple categorize helper (minimal mirror of stockpiles categorize lists)
function officer_categorize($name){
  if (!$name) return 'other';
  $n = strtolower(trim($name));
  $excludeList = ['facility materials ix','facility materials x','facility materials xi','naval turbine comps','components_min','components_max','basic materials',
                  'heavy assault rifle crate (colonial)','heavy assault rifle crate (warden)','power mw','sniper rifle crate'];
  $rawList = ['aluminum','coal','components','copper','damaged components','iron','rare metal','salvage','sulfur','wreckage'];
  $materialsList = ['construction materials','explosive powder','gravel','heavy explosive powder','refined materials','assembly materials i','assembly materials ii','assembly materials iii','assembly materials iv','assembly materials v','concrete materials','pipe','processed construction materials','rare alloys','steel construction materials','thermal shielding','unstable substances','barbed wire','metal beam','sandbag'];
  if (in_array($n, $excludeList)) return 'other';
  if (in_array($n, $rawList)) return 'raw';
  if (in_array($n, $materialsList)) return 'materials';
  if (strpos($n,'(l)') !== false || strpos($n,'liquid') !== false || strpos($n,'oil') !== false || strpos($n,'water') !== false) return 'liquids';
  if (strpos($n,'large') !== false) return 'large';
  if (strpos($n,'materials') !== false || strpos($n,'alloy') !== false || strpos($n,'components') !== false || strpos($n,'mat') !== false) return 'materials';
  return 'other';
}

// Determine default slots for a given category/kind/storage_type
function officer_default_slots($category, $storage_type = null, $kind = null){
  $cat = strtolower(trim((string)$category));
  $stype = strtolower(trim((string)$storage_type));
  $k = strtolower(trim((string)$kind));
  if (strpos($stype,'separate') !== false || strpos($k,'separate') !== false || strpos($stype,'separate_liquid') !== false) return 5;
  if ($cat === 'raw') return 7;
  if ($cat === 'materials') return 4;
  if ($cat === 'liquids' || $cat === 'liquid') return 15;
  return 15;
}

// Ensure our officer tables exist: facilities and facility_structures
try{
  // reuse $pdo initialized above during the DB connection check
  $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $pdo->exec("CREATE TABLE IF NOT EXISTS facilities (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255) UNIQUE, notes TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_structures (id INTEGER PRIMARY KEY AUTOINCREMENT, facility_id INTEGER, structure_name VARCHAR(255), type VARCHAR(64), capacity INTEGER DEFAULT 32000, icon VARCHAR(255), FOREIGN KEY(facility_id) REFERENCES facilities(id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_structure_types (id INTEGER PRIMARY KEY AUTOINCREMENT, structure_id INTEGER, type VARCHAR(64), FOREIGN KEY(structure_id) REFERENCES facility_structures(id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_storage (id INTEGER PRIMARY KEY AUTOINCREMENT, structure_id INTEGER, storage_type VARCHAR(64), capacity INTEGER, FOREIGN KEY(structure_id) REFERENCES facility_structures(id))");
    try { $pdo->exec("ALTER TABLE facility_structures ADD COLUMN slots INTEGER DEFAULT 15"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE facility_storage ADD COLUMN unit VARCHAR(16)"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE facility_storage ADD COLUMN allowed_item VARCHAR(255)"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE facility_storage ADD COLUMN per_item_volume INTEGER DEFAULT NULL"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE facility_storage ADD COLUMN kind VARCHAR(64) DEFAULT NULL"); } catch (Exception $e) { }
    try { $pdo->exec("ALTER TABLE facility_storage ADD COLUMN slots INTEGER DEFAULT 15"); } catch (Exception $e) { }
}catch(Exception $e){ }

// AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
    $action = $_POST['action'];
    try{
        if ($action === 'list_facilities'){
            $rows = dbQueryAll('SELECT id,name,notes FROM facilities ORDER BY name');
            jsonResp(['ok'=>true,'facilities'=>$rows]);
        }
        if ($action === 'create_facility'){
          $name = trim(isset($_POST['name']) ? $_POST['name'] : '');
          $notes = trim(isset($_POST['notes']) ? $_POST['notes'] : '');
            if ($name === '') jsonResp(['ok'=>false,'error'=>'Name required']);
            $existing = dbQueryOne('SELECT id FROM facilities WHERE name = ?', [$name]);
            if ($existing && isset($existing['id'])) {
              dbExecute('UPDATE facilities SET notes = ? WHERE id = ?', [$notes, $existing['id']]);
            } else {
              dbExecute('INSERT INTO facilities (name,notes) VALUES (?,?)', [$name,$notes]);
            }
            jsonResp(['ok'=>true]);
        }
        if ($action === 'list_structures'){
          $facility_id = intval(isset($_POST['facility_id']) ? $_POST['facility_id'] : 0);
            $rows = dbQueryAll('SELECT id,facility_id,structure_name,type,capacity,icon,slots FROM facility_structures WHERE facility_id = ? ORDER BY structure_name', [$facility_id]);
          // for each structure, include any storage entries and types
          foreach ($rows as &$r) {
            $stor = dbQueryAll('SELECT storage_type,capacity,unit,allowed_item,per_item_volume,kind,slots FROM facility_storage WHERE structure_id = ?', [$r['id']]);
            $r['storage'] = $stor;
            $types = dbQueryAll('SELECT type FROM facility_structure_types WHERE structure_id = ?', [$r['id']]);
            $r['types'] = array_map(function($x){ return $x['type']; }, $types ?: []);
          }
          jsonResp(['ok'=>true,'structures'=>$rows]);
        }
        if ($action === 'add_structure'){
          $facility_id = intval(isset($_POST['facility_id']) ? $_POST['facility_id'] : 0);
          $structure = trim(isset($_POST['structure_name']) ? $_POST['structure_name'] : '');
          $capacity = intval(isset($_POST['capacity']) ? $_POST['capacity'] : 32000);
          $icon = trim(isset($_POST['icon']) ? $_POST['icon'] : '');
          $typesPayload = isset($_POST['types']) ? trim($_POST['types']) : '';
          $types = [];
          if ($typesPayload) {
            $decoded = json_decode($typesPayload, true);
            if (is_array($decoded)) $types = array_filter(array_map('trim', $decoded));
          }
          if (empty($types) && isset($_POST['type'])) $types = [ trim($_POST['type']) ];
            if (!$facility_id || $structure === '') jsonResp(['ok'=>false,'error'=>'facility_id and structure_name required']);
            $primaryType = count($types)>0 ? $types[0] : 'materials';
            dbExecute('INSERT INTO facility_structures (facility_id,structure_name,type,capacity,icon) VALUES (?,?,?,?,?)', [$facility_id,$structure,$primaryType,$capacity,$icon]);
            $srow = dbQueryOne('SELECT id FROM facility_structures WHERE facility_id = ? AND structure_name = ? ORDER BY id DESC LIMIT 1', [$facility_id, $structure]);
            $sid = $srow ? intval($srow['id']) : 0;
            if ($sid && !empty($types)){
              foreach ($types as $t){
                if ($t==='') continue;
                $ex = dbQueryOne('SELECT id FROM facility_structure_types WHERE structure_id = ? AND type = ?', [$sid, $t]);
                if (!$ex) dbExecute('INSERT INTO facility_structure_types (structure_id,type) VALUES (?,?)', [$sid,$t]);
              }
            }
            $inputNames = [];
            try{
              $rows = dbQueryAll('SELECT id FROM recipes WHERE structure = ?', [$structure]);
              if ($rows) foreach ($rows as $rr){
                $ins = dbQueryAll('SELECT DISTINCT name FROM inputs WHERE recipe_id = ?', [intval($rr['id'])]);
                if ($ins) foreach ($ins as $i) if (!empty($i['name'])) $inputNames[trim($i['name'])]=true;
              }
            }catch(Exception $e){ }
            $dbDir = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'db');
            $tryFiles = [];
            $merged = $dbDir ? ($dbDir . DIRECTORY_SEPARATOR . 'merged.json') : null;
            if ($merged && file_exists($merged)) $tryFiles[] = $merged;
            if ($dbDir && is_dir($dbDir)){
              foreach (glob($dbDir . DIRECTORY_SEPARATOR . '*.json') as $jf) if (!in_array($jf, $tryFiles)) $tryFiles[] = $jf;
            }
            foreach ($tryFiles as $jf){
              $txt = @file_get_contents($jf);
              if ($txt === false) continue;
              $data = json_decode($txt, true);
              if (!is_array($data)) continue;
              $walker = function($node) use (&$walker, $structure, &$inputNames){
                if (!is_array($node)) return;
                if (isset($node['structure']) && $node['structure'] && strtolower(trim($node['structure'])) === strtolower(trim($structure))){
                  if (isset($node['input']) && is_array($node['input'])){
                    foreach ($node['input'] as $iname => $_) { if (!empty($iname)) $inputNames[trim((string)$iname)]=true; }
                  }
                }
                foreach ($node as $child) if (is_array($child)) $walker($child);
              };
              $walker($data);
            }
            $outputNames = [];
            try{
              $rowsOut = dbQueryAll('SELECT id FROM recipes WHERE structure = ?', [$structure]);
              if ($rowsOut) foreach ($rowsOut as $rr){
                $outs = dbQueryAll('SELECT DISTINCT name FROM outputs WHERE recipe_id = ?', [intval($rr['id'])]);
                if ($outs) foreach ($outs as $o) if (!empty($o['name'])) $outputNames[trim($o['name'])]=true;
              }
            }catch(Exception $e){ }
            foreach ($tryFiles as $jf){
              $txt = @file_get_contents($jf);
              if ($txt === false) continue;
              $data = json_decode($txt, true);
              if (!is_array($data)) continue;
              $walkerOut = function($node) use (&$walkerOut, $structure, &$outputNames){
                if (!is_array($node)) return;
                if (isset($node['structure']) && $node['structure'] && strtolower(trim($node['structure'])) === strtolower(trim($structure))){
                  if (isset($node['output']) && is_array($node['output'])){
                    foreach ($node['output'] as $oname => $_) { if (!empty($oname)) $outputNames[trim((string)$oname)]=true; }
                  }
                  if (isset($node['outputs']) && is_array($node['outputs'])){
                    foreach ($node['outputs'] as $oname => $_) { if (!empty($oname)) $outputNames[trim((string)$oname)]=true; }
                  }
                }
                foreach ($node as $child) if (is_array($child)) $walkerOut($child);
              };
              $walkerOut($data);
            }
            $itemNames = $inputNames;
            foreach (array_keys($outputNames) as $o) $itemNames[trim($o)] = true;
            foreach (array_keys($itemNames) as $iname){
              $iname = trim($iname);
              if ($iname === '') continue;
              $cat = officer_categorize($iname);
              if ($cat !== 'raw' && $cat !== 'materials') continue;
              $exists = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND allowed_item = ?', [$sid, $iname]);
              if ($exists && isset($exists['id'])) continue;
              $defSlots = officer_default_slots($cat, 'auto', $cat);
              dbExecute('INSERT INTO facility_storage (structure_id,storage_type,capacity,unit,allowed_item,per_item_volume,kind,slots) VALUES (?,?,?,?,?,?,?,?)', [$sid, 'auto', 32000, '', $iname, null, $cat, $defSlots]);
            }
            $liqSiloCap = intval(isset($_POST['liquid_silo_capacity']) ? $_POST['liquid_silo_capacity'] : 0);
            $liqSepCap = intval(isset($_POST['liquid_separate_capacity']) ? $_POST['liquid_separate_capacity'] : 0);
            jsonResp(['ok'=>true]);
        }
          if ($action === 'add_storage_entry'){
            $structure_id = intval(isset($_POST['structure_id']) ? $_POST['structure_id'] : 0);
            $kind = trim(isset($_POST['kind']) ? $_POST['kind'] : '');
            $capacity = intval(isset($_POST['capacity']) ? $_POST['capacity'] : 0);
            $unit = trim(isset($_POST['unit']) ? $_POST['unit'] : '');
            $allowed_item = trim(isset($_POST['allowed_item']) ? $_POST['allowed_item'] : '');
            $per_item_volume = intval(isset($_POST['per_item_volume']) ? $_POST['per_item_volume'] : 0);
            if (!$structure_id || $kind === '') jsonResp(['ok'=>false,'error'=>'structure_id and kind required']);
              $slots = null;
              if (isset($_POST['slots'])) {
                $slots = intval($_POST['slots']);
              } else if ($capacity === 32000) {
                $inferredCat = '';
                if (!empty($allowed_item)) $inferredCat = officer_categorize($allowed_item);
                else $inferredCat = (stripos($kind,'liquid')!==false) ? 'liquids' : ((stripos($kind,'material')!==false) ? 'materials' : 'other');
                $slots = officer_default_slots($inferredCat, $kind, $kind);
              }
              if ($slots === null) {
                dbExecute('INSERT INTO facility_storage (structure_id,storage_type,capacity,unit,allowed_item,per_item_volume,kind) VALUES (?,?,?,?,?,?,?)', [$structure_id, $kind, $capacity, $unit, $allowed_item ?: null, $per_item_volume ?: null, $kind]);
              } else {
                dbExecute('INSERT INTO facility_storage (structure_id,storage_type,capacity,unit,allowed_item,per_item_volume,kind,slots) VALUES (?,?,?,?,?,?,?,?)', [$structure_id, $kind, $capacity, $unit, $allowed_item ?: null, $per_item_volume ?: null, $kind, $slots]);
              }
            jsonResp(['ok'=>true]);
          }
          if ($action === 'update_structure_slots'){
            $structure_id = intval(isset($_POST['structure_id']) ? $_POST['structure_id'] : 0);
            $slots = intval(isset($_POST['slots']) ? $_POST['slots'] : 0);
            if (!$structure_id) jsonResp(['ok'=>false,'error'=>'structure_id required']);
            dbExecute('UPDATE facility_storage SET slots = ? WHERE structure_id = ? AND LOWER(storage_type) = ?', [$slots, $structure_id, 'auto']);
            jsonResp(['ok'=>true]);
          }
        if ($action === 'update_storage_slots'){
          $storage_id = intval(isset($_POST['storage_id']) ? $_POST['storage_id'] : 0);
          $slots = intval(isset($_POST['slots']) ? $_POST['slots'] : 0);
          if (!$storage_id) jsonResp(['ok'=>false,'error'=>'storage_id required']);
          dbExecute('UPDATE facility_storage SET slots = ? WHERE id = ?', [$slots, $storage_id]);
          jsonResp(['ok'=>true]);
        }
        if ($action === 'update_structure'){
          $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
          $capacity = intval(isset($_POST['capacity']) ? $_POST['capacity'] : 32000);
          $icon = trim(isset($_POST['icon']) ? $_POST['icon'] : '');
          $typesPayload = isset($_POST['types']) ? trim($_POST['types']) : '';
          $types = [];
          if ($typesPayload) {
            $decoded = json_decode($typesPayload, true);
            if (is_array($decoded)) $types = array_filter(array_map('trim', $decoded));
          }
          if (empty($types) && isset($_POST['type'])) $types = [ trim($_POST['type']) ];
            if (!$id) jsonResp(['ok'=>false,'error'=>'id required']);
            $primaryType = count($types)>0 ? $types[0] : 'materials';
            dbExecute('UPDATE facility_structures SET type=?,capacity=?,icon=? WHERE id=?', [$primaryType,$capacity,$icon,$id]);
            dbExecute('DELETE FROM facility_structure_types WHERE structure_id = ?', [$id]);
            if (!empty($types)){
              foreach ($types as $t){ if ($t==='') continue; dbExecute('INSERT INTO facility_structure_types (structure_id,type) VALUES (?,?)', [$id,$t]); }
            }
            jsonResp(['ok'=>true]);
        }
        if ($action === 'delete_structure'){
          $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
            if (!$id) jsonResp(['ok'=>false,'error'=>'id required']);
            $sids = dbQueryAll('SELECT id FROM facility_storage WHERE structure_id = ?', [$id]);
            if ($sids) foreach ($sids as $sr){ dbExecute('DELETE FROM facility_storage_stock WHERE storage_id = ?', [intval($sr['id'])]); }
            dbExecute('DELETE FROM facility_storage WHERE structure_id = ?', [$id]);
            dbExecute('DELETE FROM facility_structure_types WHERE structure_id = ?', [$id]);
            dbExecute('DELETE FROM facility_structures WHERE id=?', [$id]);
            jsonResp(['ok'=>true]);
        }
        if ($action === 'list_structure_options'){
          $rows1 = dbQueryAll('SELECT DISTINCT structure FROM recipes WHERE structure IS NOT NULL AND structure <> ""');
          $rows2 = [];
          try{ $rows2 = dbQueryAll('SELECT DISTINCT structure_name as structure FROM facility_structures WHERE structure_name IS NOT NULL AND structure_name <> ""'); }catch(Exception $e){ }
          $names = [];
          foreach (array_merge($rows1, $rows2) as $r) if (isset($r['structure']) && $r['structure']) $names[trim($r['structure'])]=true;
          $names = array_values(array_keys($names));
          sort($names, SORT_STRING | SORT_FLAG_CASE);
          jsonResp(['ok'=>true,'options'=>$names]);
        }
        if ($action === 'import_structures_from_json'){
          $found = [];
          $base = __DIR__ . '/db';
          $merged = $base . '/merged.json';
          if (file_exists($merged)){
            $data = json_decode(file_get_contents($merged), true);
            if (is_array($data)){
              foreach ($data as $node){
                if (is_array($node) && isset($node['structure']) && $node['structure']) $found[trim($node['structure'])] = true;
                if (is_array($node) && isset($node['produced_by']) && is_array($node['produced_by'])){
                  $opts = $node['produced_by'];
                  if (isset($opts['options']) && is_array($opts['options'])){
                    foreach ($opts['options'] as $o) if (isset($o['structure']) && $o['structure']) $found[trim($o['structure'])]=true;
                  }
                }
              }
            }
          }
          $structDir = $base . '/structures';
          if (is_dir($structDir)){
            $files = scandir($structDir);
            foreach ($files as $f){
              if (!preg_match('/\.json$/i',$f)) continue;
              $p = $structDir . '/' . $f;
              $j = json_decode(@file_get_contents($p), true);
              if (!is_array($j)) continue;
              if (isset($j['structure']) && $j['structure']) $found[trim($j['structure'])]=true;
              if (array_values($j) === $j){
                foreach ($j as $node){ if (is_array($node) && isset($node['structure']) && $node['structure']) $found[trim($node['structure'])]=true; }
              }
            }
          }
          $inserted = 0; $skipped=0;
          foreach (array_keys($found) as $sname){
            if (!$sname) continue;
            $exists = dbQueryOne('SELECT id FROM facility_structures WHERE structure_name = ?', [$sname]);
            if ($exists && isset($exists['id'])) { $skipped++; continue; }
            dbExecute('INSERT INTO facility_structures (facility_id,structure_name,type,capacity,icon) VALUES (?,?,?,?,?)', [null,$sname,'materials',32000,null]);
            $inserted++;
          }
          jsonResp(['ok'=>true,'inserted'=>$inserted,'skipped'=>$skipped,'total'=>count($found),'names'=>array_keys($found)]);
        }

        // List users (AJAX)
        if ($action === 'list_users'){
          try{
            $rows = dbQueryAll('SELECT id, discord, role, created_at FROM users ORDER BY discord');
            jsonResp(['ok'=>true,'users'=>$rows]);
          } catch(Exception $e){ jsonResp(['ok'=>false,'error'=>$e->getMessage()]); }
        }

        // Update user role (AJAX)
        if ($action === 'update_user_role'){
          if (!isset($current['role']) || $current['role'] !== 'Officer') {
            jsonResp(['ok'=>false,'error'=>'permission_denied']);
          }
          $id = intval(isset($_POST['id']) ? $_POST['id'] : 0);
          $role = trim(isset($_POST['role']) ? $_POST['role'] : 'Member');
          if (!$id) jsonResp(['ok'=>false,'error'=>'id required']);
          try{ dbExecute('UPDATE users SET role = ? WHERE id = ?', [$role, $id]); jsonResp(['ok'=>true]); } catch(Exception $e){ jsonResp(['ok'=>false,'error'=>$e->getMessage()]); }
        }
    }catch(Exception $e){ jsonResp(['ok'=>false,'error'=>$e->getMessage()]); }
    jsonResp(['ok'=>false,'error'=>'unknown action']);
}

// If not POST, render officer UI
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>Officer — Facility Structures</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif;margin:20px}
    .panel{border:1px solid #ddd;padding:12px;margin-bottom:12px}
    label{display:block;margin-top:8px}
    select,input,button{padding:6px;margin-top:4px}
    .structure-row{border:1px solid #eee;padding:8px;margin:6px 0;display:flex;align-items:center;justify-content:space-between}
    .structure-info{flex:1}
    .icon-sample{width:48px;height:48px;background:#222;border-radius:4px;margin-right:8px;display:inline-block;vertical-align:middle;background-size:cover;background-position:center}
  </style>
  <link rel="stylesheet" href="css/officer.css" />
  <link rel="stylesheet" href="css/index.css" />
</head>
<body class="officer-page">
  <main class="site-main">
  <div class="officer-navbar">
    <div class="container">
      <div class="officer-brand"><span class="logo" aria-hidden="true"></span><span class="title">HUSS Officer Panel</span></div>
      <div class="officer-nav-actions">
        <a class="officer-btn" href="index.php">Home</a>
        <a class="officer-btn ghost" href="logout.php">Logout</a>
      </div>
    </div>
  </div>

  <div class="officer-wrap">
    <div class="officer-hero">
      <h1>Facility Structures Officer</h1>
      <p>Manage facilities, structures and users from one place.</p>
    </div>

    <script>window.CURRENT_USER = <?php echo json_encode($current ?: null); ?>;</script>

    <div class="officer-tabs" role="tablist" aria-label="Officer sections">
      <button class="officer-tab active" data-target="facilities" role="tab" aria-selected="true">Facilities</button>
      <button class="officer-tab" data-target="structures" role="tab" aria-selected="false">Manage Structures</button>
      <button class="officer-tab" data-target="users" role="tab" aria-selected="false">Users</button>
    </div>

    <div class="officer-content">
      <section id="section-facilities" class="officer-section active">
        <div class="panel">
          <h3>Facilities</h3>
          <div id="facilities-list">Loading...</div>
          <hr />
          <label>New facility name</label>
          <input id="new-facility-name" />
          <label>Notes</label>
          <input id="new-facility-notes" />
          <button id="create-facility" class="btn primary">Create facility</button>
        </div>
      </section>

      <section id="section-structures" class="officer-section">
        <div class="panel">
          <h3>Manage Structures in Facility</h3>
          <label>Select facility</label>
          <select id="select-facility"></select>

          <hr />
          <label>Add structure (choose from known or custom)</label>
          <select id="structure-options"></select>
          <button id="import-structures" class="btn ghost" style="margin-left:8px;">Import structures from JSON</button>
          <input id="structure-custom" placeholder="Or type custom name" />
          <label>Type (click to toggle)</label>
          <div id="structure-type" style="display:flex;gap:8px;flex-wrap:wrap">
            <label style="font-weight:normal"><input type="checkbox" name="structure-type-checkbox" value="materials" /> Materials</label>
            <label style="font-weight:normal"><input type="checkbox" name="structure-type-checkbox" value="raw" /> Raw materials</label>
            <label style="font-weight:normal"><input type="checkbox" name="structure-type-checkbox" value="large" /> Large materials</label>
            <label style="font-weight:normal"><input type="checkbox" name="structure-type-checkbox" value="liquid" /> Liquids</label>
          </div>
          <label>Capacity</label>
          <input id="structure-capacity" value="32000" />
          <div id="liquid-options" style="display:none;margin-top:8px;border:1px dashed #ddd;padding:8px">
            <label>Separate Liquid Transfer Capacity (items) — optional</label>
            <input id="liquid-separate-capacity" value="1000" />
          </div>
          <label>Icon (relative path, e.g. img/materials/rare-alloys.png)</label>
          <input id="structure-icon" placeholder="optional icon path" />
          <button id="add-structure" class="btn primary">Add structure to facility</button>
          <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
            <button id="quick-add-liquid-silo" class="btn ghost">Create Liquid Silo structure</button>
            <button id="quick-add-liquid-transfer" class="btn ghost">Create Liquid Transfer Station structure</button>
            <button id="quick-add-resource-transfer" class="btn ghost">Create Resource Transfer Station structure</button>
            <button id="quick-add-material-transfer" class="btn ghost">Create Material Transfer Station structure</button>
          </div>

          <hr />
          <h4>Assigned structures</h4>
          <div id="assigned-structures">Select a facility to view assigned structures</div>
        </div>
      </section>

      <section id="section-users" class="officer-section">
        <div class="panel" id="users-panel">
          <h3>Users</h3>
          <div id="users-banner" class="officer-banner info" style="display:none">Only Officers may change roles — members can view but not modify roles.</div>
          <div id="users-list">Loading users...</div>
          <div style="margin-top:8px">
            <button id="refresh-users" class="btn ghost">Refresh users</button>
          </div>
        </div>
      </section>
    </div>
  </div>
</main>
  <script>
    async function post(action, data={}){
      const f = new FormData(); f.append('action', action);
      for (const k in data) f.append(k, data[k]);
      const res = await fetch('officer.php', {method:'POST', body: f});
      return await res.json();
    }

    async function loadFacilities(){
      const r = await post('list_facilities');
      const el = document.getElementById('facilities-list');
      const sel = document.getElementById('select-facility');
      sel.innerHTML = '';
      if (!r.ok) { el.textContent = 'Error: ' + (r.error || 'unknown'); return; }
      el.innerHTML = '';
      r.facilities.forEach(f=>{
        const d = document.createElement('div'); d.textContent = f.name + (f.notes? (' — '+f.notes): ''); el.appendChild(d);
        const o = document.createElement('option'); o.value = f.id; o.textContent = f.name; sel.appendChild(o);
      });
      if (r.facilities.length === 0) el.textContent = 'No facilities yet';
      if (sel.options.length>0) { sel.selectedIndex = 0; loadAssignedStructures(); }
    }

    document.getElementById('create-facility').addEventListener('click', async ()=>{
      const name = document.getElementById('new-facility-name').value.trim();
      const notes = document.getElementById('new-facility-notes').value.trim();
      if (!name) return alert('Name required');
      const r = await post('create_facility', {name, notes});
      if (!r.ok) return alert('Error: '+(r.error||'failed'));
      document.getElementById('new-facility-name').value=''; document.getElementById('new-facility-notes').value='';
      loadFacilities();
    });

    async function loadStructureOptions(){
      const r = await post('list_structure_options');
      const sel = document.getElementById('structure-options'); sel.innerHTML='';
      if (r.ok){ r.options.forEach(n=>{ const o=document.createElement('option'); o.value=n; o.textContent=n; sel.appendChild(o); }); }
    }

    async function quickAddStructure(name, typesArr, structCap, storageEntries){
      const fid = document.getElementById('select-facility').value; if (!fid) return alert('Select a facility first');
      const r = await post('add_structure',{facility_id: fid, structure_name: name, capacity: structCap, types: JSON.stringify(typesArr)});
      if (!r.ok) return alert('Failed to add structure: '+(r.error||'unknown'));
      const list = await post('list_structures',{facility_id: fid});
      if (!list.ok) return alert('Failed to refresh structures');
      let sid = 0; for (const s of list.structures){ if (s.structure_name === name) sid = Math.max(sid, s.id); }
      if (!sid) return loadAssignedStructures();
      if (Array.isArray(storageEntries)){
        for (const se of storageEntries){
          await post('add_storage_entry', Object.assign({structure_id: sid}, se));
        }
      }
      loadAssignedStructures();
    }

    function updateTypeDependentFields(){
      const checked = Array.from(document.querySelectorAll('input[name="structure-type-checkbox"]:checked')).map(i=>i.value);
      const liq = checked.indexOf('liquid') !== -1;
      document.getElementById('liquid-options').style.display = liq ? 'block' : 'none';
    }

    Array.from(document.querySelectorAll('input[name="structure-type-checkbox"]')).forEach(cb=> cb.addEventListener('change', updateTypeDependentFields));
    updateTypeDependentFields();

    async function loadAssignedStructures(){
      const sel = document.getElementById('select-facility');
      if (!sel) return; const fid = sel.value; if (!fid) return;
      const r = await post('list_structures', {facility_id: fid});
      const wrap = document.getElementById('assigned-structures'); wrap.innerHTML = '';
      if (!r.ok) return wrap.textContent = 'Error: '+(r.error||'failed');
      if (!r.structures.length) return wrap.textContent = 'No structures assigned';
        r.structures.forEach(s=>{
        const row = document.createElement('div'); row.className='structure-row';
        const info = document.createElement('div'); info.className='structure-info';
        const icon = document.createElement('div'); icon.className='icon-sample';
        if (s.icon){ try{ const path = String(s.icon).replace(/\\/g,'/'); icon.style.backgroundImage = 'url("'+encodeURI(path)+'")'; }catch(e){ } }
        info.appendChild(icon);
          const typesDisplay = (s.types && s.types.length) ? s.types.join(', ') : (s.type || '');
          const title = document.createElement('div');
          let totalSlotsSum = 0; let computedTotalCapacity = 0; let autoCount = 0; let perAutoSlots = null;
          if (s.storage && s.storage.length) {
            s.storage.forEach(st => {
              try{
                const stType = String(st.storage_type||'').toLowerCase();
                const baseCap = (st.capacity !== undefined && st.capacity !== null) ? Number(st.capacity) : 0;
                const sn = (st.slots !== undefined && st.slots !== null) ? Number(st.slots) : 0;
                if (stType === 'auto' && isFinite(sn) && sn > 0){ autoCount += 1; totalSlotsSum += sn; if (perAutoSlots === null) perAutoSlots = sn; else if (perAutoSlots !== sn) perAutoSlots = -1; computedTotalCapacity += (isFinite(baseCap) ? baseCap * sn : 0); } else { computedTotalCapacity += (isFinite(baseCap) ? baseCap : 0); }
              }catch(e){}
            });
          }
          let titleHtml = '<strong>'+s.structure_name+'</strong> — '+typesDisplay+' — total capacity: '+computedTotalCapacity;
          if (autoCount > 0){ if (perAutoSlots > 0){ titleHtml += ' — slots: ' + perAutoSlots; } else { titleHtml += ' — slots total: ' + totalSlotsSum + ' (' + autoCount + ' auto storages)'; } }
          title.innerHTML = titleHtml;
        info.appendChild(title);
        if (s.storage && s.storage.length) {
          const storWrap = document.createElement('div'); storWrap.style.marginTop='6px';
            const categoryMap = {};
            s.storage.forEach(st => {
              try{
                const kind = (st.kind && st.kind.length) ? st.kind : String(st.storage_type||'').toLowerCase();
                const baseCap = (st.capacity !== undefined && st.capacity !== null) ? Number(st.capacity) : 0;
                const sn = (st.slots !== undefined && st.slots !== null) ? Number(st.slots) : 0;
                const contrib = (String(st.storage_type||'').toLowerCase() === 'auto' && sn>0) ? (baseCap * sn) : baseCap;
                if (!categoryMap[kind]) categoryMap[kind] = 0;
                categoryMap[kind] += contrib;
              }catch(e){}
            });
            const breakdownEl = document.createElement('div'); breakdownEl.style.fontSize='12px'; breakdownEl.style.color='#333'; breakdownEl.style.marginBottom='6px';
            const cats = ['raw','liquids','materials','large','other'];
            const parts = [];
            cats.forEach(k=>{ if (categoryMap[k] && categoryMap[k] > 0) parts.push(k + ': ' + categoryMap[k]); });
            if (parts.length === 0){ breakdownEl.textContent = 'Breakdown: none'; } else { breakdownEl.textContent = 'Breakdown: ' + parts.join(' • '); }
            storWrap.appendChild(breakdownEl);
            const autos = s.storage.filter(st => String(st.storage_type||'').toLowerCase() === 'auto');
            const nonAutos = s.storage.filter(st => String(st.storage_type||'').toLowerCase() !== 'auto');
            if (autos.length > 0){
              const groups = {};
              autos.forEach(st => { const cap = (st.capacity !== undefined && st.capacity !== null) ? Number(st.capacity) : 0; const sn = (st.slots !== undefined && st.slots !== null) ? Number(st.slots) : 0; const key = cap + '_' + sn; if (!groups[key]) groups[key] = {cap: cap, slots: sn, items: []}; groups[key].items.push(st); });
              Object.keys(groups).forEach(k => { const g = groups[k]; if (g.slots && g.slots > 0){ for (let i=1;i<=g.slots;i++){ const div = document.createElement('div'); div.style.fontSize='12px'; div.style.color='#444'; div.textContent = 'auto — ' + g.cap + ' — slot ' + i; storWrap.appendChild(div); } } else { const div = document.createElement('div'); div.style.fontSize='12px'; div.style.color='#444'; div.textContent = 'auto — ' + g.cap; storWrap.appendChild(div); } });
            }
            nonAutos.forEach(st => { const capNum = (st.capacity !== null && st.capacity !== undefined) ? Number(st.capacity) : NaN; const capText = (isFinite(capNum) && capNum > 0) ? (' — ' + st.capacity + (st.unit ? (' ' + st.unit) : '')) : ''; const slotsNum = (st.slots !== undefined && st.slots !== null) ? Number(st.slots) : null; const repeat = (String(st.storage_type||'').toLowerCase() === 'auto' && slotsNum && slotsNum>0) ? slotsNum : 1; for (let r=0; r<repeat; r++){ const text = (st.storage_type || st.kind || '') + capText; const div = document.createElement('div'); div.style.fontSize='12px'; div.style.color='#444'; div.textContent = repeat>1 ? (text + ' — slot ' + (r+1)) : text; storWrap.appendChild(div); } });
          info.appendChild(storWrap);
        }
        row.appendChild(info);
        const btns = document.createElement('div');
        const del = document.createElement('button'); del.textContent='Delete'; del.className='btn ghost'; del.addEventListener('click', async ()=>{ if (!confirm('Delete structure?')) return; const rr = await post('delete_structure',{id:s.id}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
        const edit = document.createElement('button'); edit.textContent='Edit'; edit.className='btn ghost'; edit.addEventListener('click', ()=>{ openEditDialog(s); });
          btns.appendChild(edit); btns.appendChild(del);
          const manage = document.createElement('button'); manage.textContent='Manage Stock'; manage.className='btn positive'; manage.style.marginLeft='8px'; manage.addEventListener('click', ()=>{ const selEl = document.getElementById('select-facility'); const q = new URLSearchParams(); q.set('facility_id', s.facility_id || (selEl?selEl.value:'')); q.set('structure_id', s.id); q.set('structure_name', s.structure_name); window.open('stockpiles.php?'+q.toString(), '_blank'); });
          btns.appendChild(manage);
          const editStructureSlots = document.createElement('button'); editStructureSlots.textContent = 'Edit Slots'; editStructureSlots.className='btn ghost'; editStructureSlots.style.marginLeft='6px'; editStructureSlots.addEventListener('click', async ()=>{ const currentSlots = (s.storage && s.storage.length && s.storage[0].slots) ? Number(s.storage[0].slots) : 15; const v = prompt('Set slots for auto storages in this structure (integer)', String(currentSlots)); if (v === null) return; const nv = parseInt(v) || 0; const rr = await post('update_structure_slots', { structure_id: s.id, slots: nv }); if (!rr.ok) return alert(rr.error || 'failed'); loadAssignedStructures(); });
          btns.appendChild(editStructureSlots);
          const addSilo = document.createElement('button'); addSilo.textContent='Add Liquid Silo (5000L)'; addSilo.className='btn ghost'; addSilo.addEventListener('click', async ()=>{ const rr = await post('add_storage_entry',{structure_id: s.id, kind: 'Liquid Silo', capacity: 5000, unit: 'L'}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
          const addLiqTransfer = document.createElement('button'); addLiqTransfer.textContent='Add Liquid Transfer Station (500 items)'; addLiqTransfer.className='btn ghost'; addLiqTransfer.addEventListener('click', async ()=>{ const choice = prompt('Enter liquid type (Heavy oil, Petrol, E-oil, Water, Diesel)'); if (!choice) return; const volumes = { 'Heavy oil':30, 'Petrol':50, 'E-oil':30, 'Water':50, 'Diesel':100 }; const pv = volumes[choice] || 0; const rr = await post('add_storage_entry',{structure_id: s.id, kind: 'Liquid Transfer Station', capacity: 500, unit: 'items', allowed_item: choice, per_item_volume: pv}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
          const addResTransfer = document.createElement('button'); addResTransfer.textContent='Add Resource Transfer Station (15000)'; addResTransfer.className='btn ghost'; addResTransfer.addEventListener('click', async ()=>{ const rr = await post('add_storage_entry',{structure_id: s.id, kind: 'Resource Transfer Station', capacity: 15000, unit: 'units'}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
          const addMatTransfer = document.createElement('button'); addMatTransfer.textContent='Add Material Transfer Station (1000 items)'; addMatTransfer.className='btn ghost'; addMatTransfer.addEventListener('click', async ()=>{ const rr = await post('add_storage_entry',{structure_id: s.id, kind: 'Material Transfer Station', capacity: 1000, unit: 'items'}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
          const addSepLiquid = document.createElement('button'); addSepLiquid.textContent='Add Separate Liquid'; addSepLiquid.className='btn ghost'; addSepLiquid.style.marginLeft='6px'; addSepLiquid.addEventListener('click', async ()=>{ const defaultCapEl = document.getElementById('liquid-separate-capacity'); const defaultCap = defaultCapEl? (parseInt(defaultCapEl.value)||1000) : 1000; const choice = prompt('Enter liquid type (e.g. Water (L) or Heavy Oil (L))'); if (!choice) return; const capStr = prompt('Capacity in liters', String(defaultCap)); if (capStr===null) return; const cap = parseInt(capStr) || defaultCap; const rr = await post('add_storage_entry',{structure_id: s.id, kind: 'Separate Liquid', storage_type: 'Separate Liquid', capacity: cap, unit: 'L', allowed_item: choice}); if (rr.ok) loadAssignedStructures(); else alert(rr.error||'failed'); });
          btns.appendChild(addSilo); btns.appendChild(addLiqTransfer); btns.appendChild(addResTransfer); btns.appendChild(addMatTransfer);
          btns.appendChild(addSepLiquid);
        row.appendChild(btns);
        wrap.appendChild(row);
      });
    }

    function openEditDialog(s){
      const currentTypes = (s.types && s.types.length) ? s.types.join(', ') : (s.type || 'materials');
      const newTypes = prompt('Types (comma-separated)', currentTypes); if (newTypes===null) return;
      const newCap = prompt('Capacity', s.capacity || 32000); if (newCap===null) return;
      const newIcon = prompt('Icon path', s.icon || ''); if (newIcon===null) return;
      const typesArr = newTypes.split(',').map(x=>x.trim()).filter(x=>x);
      post('update_structure',{id:s.id, types: JSON.stringify(typesArr), capacity: parseInt(newCap)||32000, icon:newIcon}).then(r=>{ if (r.ok) loadAssignedStructures(); else alert(r.error||'failed'); });
    }

    const selectFacilityEl = document.getElementById('select-facility');
    if (selectFacilityEl) selectFacilityEl.addEventListener('change', loadAssignedStructures);
    const addStructBtn = document.getElementById('add-structure');
    if (addStructBtn) addStructBtn.addEventListener('click', async ()=>{
      const sel = document.getElementById('select-facility'); if (!sel) return alert('Select facility'); const fid = sel.value; if (!fid) return alert('Select facility');
      const choiceEl = document.getElementById('structure-options'); const customEl = document.getElementById('structure-custom');
      const choice = choiceEl? choiceEl.value : ''; const custom = customEl? customEl.value.trim() : '';
      const structure = custom || choice; const capEl = document.getElementById('structure-capacity'); const iconEl = document.getElementById('structure-icon');
      const capacity = capEl? (parseInt(capEl.value) || 32000) : 32000; const icon = iconEl? iconEl.value.trim() : '';
      const types = Array.from(document.querySelectorAll('input[name="structure-type-checkbox"]:checked')).map(o=>o.value);
      const liqSiloEl = document.getElementById('liquid-silo-capacity'); const liqSepEl = document.getElementById('liquid-separate-capacity');
      const liqSilo = liqSiloEl? (parseInt(liqSiloEl.value) || 0) : 0;
      const liqSep = liqSepEl? (parseInt(liqSepEl.value) || 0) : 0;
      const r = await post('add_structure',{facility_id: fid, structure_name: structure, types: JSON.stringify(types), capacity: capacity, icon: icon, liquid_silo_capacity: liqSilo, liquid_separate_capacity: liqSep});
      if (!r.ok) return alert('Error: '+(r.error||'failed'));
      document.getElementById('structure-custom').value=''; document.getElementById('structure-icon').value='';
      loadAssignedStructures();
    });

    const qAddSilo = document.getElementById('quick-add-liquid-silo'); if (qAddSilo) qAddSilo.addEventListener('click', async ()=>{ await quickAddStructure('Liquid Silo', ['liquid'], 32000, [{kind:'Liquid Silo', storage_type:'Liquid Silo', capacity:5000, unit:'L'}]); });
    const qAddLiq = document.getElementById('quick-add-liquid-transfer'); if (qAddLiq) qAddLiq.addEventListener('click', async ()=>{ await quickAddStructure('Liquid Transfer Station', ['liquid'], 500, [{kind:'Liquid Transfer Station', storage_type:'Liquid Transfer Station', capacity:500, unit:'items'}]); });
    const qAddRes = document.getElementById('quick-add-resource-transfer'); if (qAddRes) qAddRes.addEventListener('click', async ()=>{ await quickAddStructure('Resource Transfer Station', ['raw'], 15000, [{kind:'Resource Transfer Station', storage_type:'Resource Transfer Station', capacity:15000, unit:'units'}]); });
    const qAddMat = document.getElementById('quick-add-material-transfer'); if (qAddMat) qAddMat.addEventListener('click', async ()=>{ await quickAddStructure('Material Transfer Station', ['materials'], 1000, [{kind:'Material Transfer Station', storage_type:'Material Transfer Station', capacity:1000, unit:'items'}]); });

    document.getElementById('import-structures').addEventListener('click', async ()=>{
      const ok = confirm('Import unique structure names from db/merged.json and db/structures/*.json into the database? (will add entries with no facility assigned)');
      if (!ok) return;
      const r = await post('import_structures_from_json', {});
      if (!r.ok) return alert('Error: '+(r.error||'failed'));
      alert('Imported: '+r.inserted+' skipped: '+r.skipped+' total discovered: '+r.total);
      loadStructureOptions();
    });

    async function loadUsers(){
      const el = document.getElementById('users-list'); if (!el) return;
      el.innerHTML = 'Loading...';
      const r = await post('list_users');
      const bannerEl = document.getElementById('users-banner');
      const isOfficer = (window.CURRENT_USER && window.CURRENT_USER.role === 'Officer');
      if (bannerEl) bannerEl.style.display = isOfficer ? 'none' : 'block';
      if (!r.ok) { el.textContent = 'Error: ' + (r.error||'failed'); return; }
      el.innerHTML = '';
      if (!r.users || r.users.length === 0) { el.textContent = 'No users found'; return; }
      const roles = ['Member','Officer','Head of Facility','Head of MPF','Admin'];
        r.users.forEach(u => {
        const row = document.createElement('div'); row.style.display='flex'; row.style.alignItems='center'; row.style.gap='8px'; row.style.margin='6px 0';
        const name = document.createElement('div'); name.textContent = u.discord; name.style.minWidth='180px';
        const sel = document.createElement('select');
        roles.forEach(rn => { const o=document.createElement('option'); o.value=rn; o.textContent=rn; if (String(u.role)===String(rn)) o.selected=true; sel.appendChild(o); });
        const save = document.createElement('button'); save.textContent='Save'; save.className='btn primary'; save.addEventListener('click', async ()=>{ const newRole = sel.value; const res = await post('update_user_role', { id: u.id, role: newRole }); if (!res.ok) return alert('Failed: '+(res.error||'unknown')); save.textContent = 'Saved'; setTimeout(()=>save.textContent='Save', 1000); });
        if (!isOfficer) { sel.disabled = true; save.disabled = true; save.title = 'Only Officers can change roles'; save.style.opacity = '0.7'; }
        row.appendChild(name); row.appendChild(sel); row.appendChild(save);
        el.appendChild(row);
      });
    }
    document.getElementById('refresh-users').addEventListener('click', loadUsers);

    loadFacilities(); loadStructureOptions(); loadUsers();

    (function(){
      const tabs = Array.from(document.querySelectorAll('.officer-tab'));
      const sections = {
        facilities: document.getElementById('section-facilities'),
        structures: document.getElementById('section-structures'),
        users: document.getElementById('section-users')
      };
      function activate(target){
        tabs.forEach(t=>{ const ttarget = t.getAttribute('data-target'); const active = ttarget===target; t.classList.toggle('active', active); t.setAttribute('aria-selected', active ? 'true' : 'false'); });
        Object.keys(sections).forEach(k=>{ const el = sections[k]; if (!el) return; el.classList.toggle('active', k===target); });
      }
      tabs.forEach(t=> t.addEventListener('click', (e)=>{ const tgt = t.getAttribute('data-target'); if (!tgt) return; activate(tgt); }));
    })();
  </script>
</body>
</html>
