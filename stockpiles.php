<?php
require_once __DIR__ . '/database.php';

function jsonResp($data){ header('Content-Type: application/json'); echo json_encode($data); exit; }

// Detect liquids used by a named structure. Prefer DB recipes/inputs when available,
// otherwise fall back to scanning merged JSON or per-structure JSON files.
function detect_structure_liquids($structureName){
  $found = [];
  if (!$structureName) return [];
  try{
    // Prefer DB recipes when available
    $rows = dbQueryAll('SELECT id FROM recipes WHERE structure = ?', [$structureName]);
    if ($rows){
      foreach ($rows as $rr){
        $rid = intval($rr['id']);
        $ins = dbQueryAll('SELECT DISTINCT name FROM inputs WHERE recipe_id = ?', [$rid]);
        if ($ins){
          foreach ($ins as $ii) if (!empty($ii['name'])) {
            $iname = $ii['name'];
            if (is_string($iname) && (stripos($iname,'(l)')!==false || stripos($iname,'liquid')!==false || stripos($iname,'oil')!==false || stripos($iname,'water')!==false)) $found[] = $iname;
          }
        }
      }
    }
  }catch(Exception $e){ /* ignore DB errors and fallback to JSON */ }

  // If nothing found in DB, scan JSON merged and per-structure files
  if (empty($found)){
    $mergedPath = realpath(__DIR__ . '/../db/merged.json');
    if ($mergedPath && file_exists($mergedPath)){
      $txt = @file_get_contents($mergedPath);
      $data = $txt ? json_decode($txt, true) : null;
      $walker = function($node) use (&$walker, $structureName, &$found){
        if (!is_array($node)) return;
        if (isset($node['structure']) && strcasecmp(trim($node['structure']), trim($structureName))===0 && isset($node['input'])){
          $inputs = $node['input'];
          if (is_array($inputs)){
            foreach ($inputs as $k=>$v){ $iname = is_int($k) ? $v : $k; if (is_string($iname) && (stripos($iname,'(l)')!==false || stripos($iname,'liquid')!==false || stripos($iname,'oil')!==false || stripos($iname,'water')!==false)) $found[] = $iname; }
          }
          return;
        }
        foreach ($node as $child) if (is_array($child)) $walker($child);
      };
      if (is_array($data)) $walker($data);
    }

    if (empty($found)){
      $structDir = realpath(__DIR__ . '/../db/structures');
      if ($structDir && is_dir($structDir)){
        foreach (glob($structDir . DIRECTORY_SEPARATOR . '*.json') as $jf){
          $txt = @file_get_contents($jf); if ($txt===false) continue;
          $data = json_decode($txt, true); if (!is_array($data)) continue;
          $walker = function($node) use (&$walker, $structureName, &$found){
            if (!is_array($node)) return;
            if (isset($node['structure']) && strcasecmp(trim($node['structure']), trim($structureName))===0 && isset($node['input'])){
              $inputs = $node['input'];
              if (is_array($inputs)){
                foreach ($inputs as $k=>$v){ $iname = is_int($k) ? $v : $k; if (is_string($iname) && (stripos($iname,'(l)')!==false || stripos($iname,'liquid')!==false || stripos($iname,'oil')!==false || stripos($iname,'water')!==false)) $found[] = $iname; }
              }
              return;
            }
            if (isset($node['usage']) && is_array($node['usage'])){ foreach ($node['usage'] as $u) if (is_array($u) && isset($u['structure']) && strcasecmp(trim($u['structure']), trim($structureName))===0 && isset($u['input'])){ $inputs = $u['input']; if (is_array($inputs)) foreach ($inputs as $k=>$v){ $iname = is_int($k)?$v:$k; if (is_string($iname) && (stripos($iname,'(l)')!==false || stripos($iname,'liquid')!==false || stripos($iname,'oil')!==false || stripos($iname,'water')!==false)) $found[] = $iname; } return; } }
            foreach ($node as $child) if (is_array($child)) $walker($child);
          };
          $walker($data);
          if (!empty($found)) break;
        }
      }
    }
  }

  return array_values(array_unique($found));
}

function detect_structure_uses_liquid($structureName){
  $r = detect_structure_liquids($structureName);
  return is_array($r) && count($r) > 0;
}

// Server-side mirror of JS categorizeName() to classify items into categories
function php_categorize($name){
  if (!$name) return 'other';
  $n = strtolower(trim($name));
  $excludeList = ['facility materials ix','facility materials x','facility materials xi','naval turbine comps','components_min','components_max','basic materials'];
  // add other items that should never be treated as raw resources
  $excludeList = array_merge($excludeList, ['heavy assault rifle crate (colonial)','heavy assault rifle crate (warden)','power mw','sniper rifle crate']);
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

// Default slots mapping used by stockpiles code (keep in sync with admin defaults)
function php_default_slots($category, $storage_type = null, $kind = null){
  $cat = strtolower(trim((string)$category));
  $stype = strtolower(trim((string)$storage_type));
  $k = strtolower(trim((string)$kind));
  if (strpos($stype,'separate') !== false || strpos($k,'separate') !== false) return 5;
  if ($cat === 'raw') return 7;
  if ($cat === 'materials') return 4;
  if ($cat === 'liquids' || $cat === 'liquid') return 15;
  return 15;
}

// ensure stock table exists
try{
  $pdo = getDatabaseConnection();
  $pdo->exec("CREATE TABLE IF NOT EXISTS facility_storage_stock (id INTEGER PRIMARY KEY AUTOINCREMENT, storage_id INTEGER, item VARCHAR(255), quantity DOUBLE, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
}catch(Exception $e){ }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])){
  $action = $_POST['action'];
  
    if ($action === 'list_facilities'){
      $rows = dbQueryAll('SELECT id,name FROM facilities ORDER BY name');
      jsonResp(['ok'=>true,'facilities'=>$rows]);
    }

    if ($action === 'list_storage_for_facility'){
      $facility_id = intval(isset($_POST['facility_id'])?$_POST['facility_id']:0);
      if (!$facility_id) jsonResp(['ok'=>false,'error'=>'facility_id required']);

      // fetch structures once
      $structuresRows = dbQueryAll('SELECT id,structure_name,capacity,icon FROM facility_structures WHERE facility_id = ? ORDER BY structure_name', [$facility_id]);
      $structures = [];
      $structureIndex = [];
      if ($structuresRows){
        foreach ($structuresRows as $si => $s) {
          try{
            $liquids = detect_structure_liquids($s['structure_name']);
            if (is_array($liquids) && count($liquids) > 0){
              // create a 32K stockpile (if not present)
              $exists = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND storage_type = ?', [$s['id'], 'structure_stockpile']);
              if (!$exists) {
                dbExecute('INSERT INTO facility_storage (structure_id, storage_type, capacity, unit, allowed_item, per_item_volume, kind, slots) VALUES (?,?,?,?,?,?,?,?)', [$s['id'], 'structure_stockpile', 32000, '', null, null, 'materials', null]);
              }

              $createdSpecific = false;
              // For each detected liquid input, ensure a storage exists for that liquid (allowed_item)
              foreach ($liquids as $liq){
                $liqTrim = trim($liq);
                if ($liqTrim === '') continue;
                // try to find an existing storage with matching allowed_item (case-insensitive) and unit = 'l'
                $existsL = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND unit = ? AND allowed_item IS NOT NULL AND lower(allowed_item) = lower(?)', [$s['id'], 'l', $liqTrim]);
                if (!$existsL){
                  // pick a deterministic storage_type name based on liquid to avoid collisions
                  $stype = 'liquid_' . substr(md5($liqTrim), 0, 8);
                  dbExecute('INSERT INTO facility_storage (structure_id, storage_type, capacity, unit, allowed_item, per_item_volume, kind, slots) VALUES (?,?,?,?,?,?,?,?)', [$s['id'], $stype, 1000, 'l', $liqTrim, null, 'liquids', null]);
                  $createdSpecific = true;
                } else {
                  $createdSpecific = true;
                }
              }

              // if no specific liquid storages exist, ensure a generic extra_liquid storage exists
              if (!$createdSpecific){
                $anyLiquidStorages = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND unit = ?', [$s['id'], 'l']);
                if (!$anyLiquidStorages){
                  $exists2 = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND storage_type = ?', [$s['id'], 'extra_liquid']);
                  if (!$exists2) {
                    dbExecute('INSERT INTO facility_storage (structure_id, storage_type, capacity, unit, allowed_item, per_item_volume, kind, slots) VALUES (?,?,?,?,?,?,?,?)', [$s['id'], 'extra_liquid', 1000, 'l', null, null, 'liquids', null]);
                  }
                }
              }
            }
          }catch(Exception $e){ /* ignore creation errors to keep listing resilient */ }
        }
      }

      // Ensure storages exist for structures that use liquids
      foreach ($structuresRows as $si => $s) {
        try{
          if (detect_structure_uses_liquid($s['structure_name'])){
            // create a 32K stockpile (if not present)
            $exists = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND storage_type = ?', [$s['id'], 'structure_stockpile']);
            if (!$exists) {
              dbExecute('INSERT INTO facility_storage (structure_id, storage_type, capacity, unit, allowed_item, per_item_volume, kind, slots) VALUES (?,?,?,?,?,?,?,?)', [$s['id'], 'structure_stockpile', 32000, '', null, null, 'materials', null]);
            }
            // create an extra 1000L liquid storage if missing
            $exists2 = dbQueryOne('SELECT id FROM facility_storage WHERE structure_id = ? AND storage_type = ?', [$s['id'], 'extra_liquid']);
            if (!$exists2) {
              dbExecute('INSERT INTO facility_storage (structure_id, storage_type, capacity, unit, allowed_item, per_item_volume, kind, slots) VALUES (?,?,?,?,?,?,?,?)', [$s['id'], 'extra_liquid', 1000, 'l', null, null, 'liquids', null]);
            }
          }
        }catch(Exception $e){ /* ignore creation errors to keep listing resilient */ }
      }

      // Shared Materials handling removed per request: do not auto-create or include a 'Shared Materials' structure here.

      // fetch all storages for facility (map to structures)
      $allStorages = dbQueryAll('SELECT fs.id, fs.structure_id, fs.storage_type, fs.capacity, fs.unit, fs.allowed_item, fs.per_item_volume, fs.kind, fs.slots FROM facility_storage fs JOIN facility_structures fstr ON fs.structure_id = fstr.id WHERE fstr.facility_id = ?', [$facility_id]);
      if ($allStorages){
        foreach ($allStorages as $st){
          $sid = intval($st['structure_id']);
          if (!isset($structureIndex[$sid])){
            // structure may exist but not in our initial list (defensive)
            $structures[] = ['id'=>$sid,'structure_name'=>'','capacity'=>0,'icon'=>'','storage'=>[], 'total_used_count'=>0,'total_used_l'=>0,'total_capacity_count'=>0,'total_capacity_l'=>0];
            $structureIndex[$sid] = count($structures)-1;
          }
          $idx = $structureIndex[$sid];
          $structures[$idx]['storage'][] = [
            'id'=>intval($st['id']),
            'storage_type'=>$st['storage_type'],
            'capacity'=>floatval($st['capacity']?:0),
            'unit'=>$st['unit']?:'',
            'allowed_item'=>$st['allowed_item']?:null,
            'per_item_volume'=>$st['per_item_volume']?:null,
            'kind'=>$st['kind']?:null,
            'stocks'=>[],
            'used_count'=>0,'used_l'=>0
          ];
        }
      }

      // prepare category totals (include large and other)
      $categoryTotals = [
        'raw'=>['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0],
        'liquids'=>['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0],
        'materials'=>['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0],
        'large'=>['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0],
        'other'=>['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0]
      ];

      // for each storage, fetch stocks and compute used/capacity in both count and liters
      foreach ($structuresRows as $si => $s){
        foreach ($s['storage'] as $sti => $st){
          $stocks = dbQueryAll('SELECT item, SUM(quantity) as qty FROM facility_storage_stock WHERE storage_id = ? GROUP BY item', [$st['id']]);
          $used_count = 0.0; $used_l = 0.0; $per_vol = floatval($st['per_item_volume']?:0); $unit = strtolower(trim($st['unit']?:''));
          if ($stocks){
            foreach ($stocks as $sk){
              $q = floatval($sk['qty']);
              if ($unit === 'l' || stripos($sk['item'], '(l)') !== false){
                $used_l += $q;
                if ($per_vol>0) $used_count += ($q / $per_vol);
              } else {
                $used_count += $q;
                if ($per_vol>0) $used_l += ($q * $per_vol);
              }
            }
          }
          $structures[$si]['storage'][$sti]['stocks'] = $stocks ?: [];
          $structures[$si]['storage'][$sti]['used_count'] = $used_count;
          $structures[$si]['storage'][$sti]['used_l'] = $used_l;
          // determine effective capacity: if slots present and this storage is raw/materials/large,
          // each slot represents 32000 capacity units; otherwise use explicit capacity field.
          $slots = intval(isset($st['slots']) ? $st['slots'] : 0);
          // determine initial category for this storage (may be refined below for inference)
          $scat = php_categorize($st['allowed_item']?:'');
          if (($st['allowed_item'] === null || $st['allowed_item'] === '' ) || $scat === 'other'){
            $kindLower = strtolower(trim($st['kind']?:''));
            $stypeLower = strtolower(trim($st['storage_type']?:''));
            if (strpos($kindLower,'liquid') !== false || strpos($stypeLower,'liquid') !== false || strpos($kindLower,'separate_liquid') !== false) $scat = 'liquids';
            elseif (strpos($kindLower,'material') !== false || strpos($stypeLower,'material') !== false || strpos($kindLower,'shared') !== false) $scat = 'materials';
            elseif (strpos($kindLower,'large') !== false || strpos($stypeLower,'large') !== false) $scat = 'large';
          }
          // effective numeric capacity value to use for further calculations
          $capValue = floatval($st['capacity']?:0);
          if ($slots > 0 && in_array($scat, ['raw','materials','large'])){
            $capValue = $slots * 32000.0;
          }
          // compute and expose per-storage capacity in both counts and liters using $capValue
          $storage_cap_count = 0.0; $storage_cap_l = 0.0;
          if ($unit === 'l'){
            $storage_cap_l += $capValue;
            if ($per_vol>0) $storage_cap_count += ($capValue / $per_vol);
          } else {
            $storage_cap_count += $capValue;
            if ($per_vol>0) $storage_cap_l += ($capValue * $per_vol);
          }
          $structures[$si]['storage'][$sti]['capacity_count'] = $storage_cap_count;
          $structures[$si]['storage'][$sti]['capacity_l'] = $storage_cap_l;

          // accumulate structure totals
          // accumulate totals separately for liquids vs non-liquids
          if ($scat === 'liquids'){
            // liquids tracked in liters
            $structures[$si]['liquid_used_l'] += $used_l;
            // capacity in liters
            if ($unit === 'l'){
              $structures[$si]['liquid_capacity_l'] += $capValue;
              if ($per_vol>0) $structures[$si]['separate_capacity_count'] += ($capValue / $per_vol); // only add count if convertible
            } else {
              if ($per_vol>0) $structures[$si]['liquid_capacity_l'] += ($capValue * $per_vol);
            }
            // also keep grand totals
            $structures[$si]['total_used_l'] += $used_l;
            if ($unit === 'l'){
              $structures[$si]['total_capacity_l'] += $capValue;
              if ($per_vol>0) $structures[$si]['total_capacity_count'] += ($capValue / $per_vol);
            } else {
              $structures[$si]['total_capacity_count'] += $capValue;
              if ($per_vol>0) $structures[$si]['total_capacity_l'] += ($capValue * $per_vol);
            }
          } else {
            // non-liquid storages -> count towards the structure's separate totals
            $structures[$si]['separate_used_count'] += $used_count;
            $structures[$si]['separate_used_l'] += $used_l;
            if ($unit === 'l'){
              $structures[$si]['separate_capacity_l'] += $capValue;
              if ($per_vol>0) $structures[$si]['separate_capacity_count'] += ($capValue / $per_vol);
            } else {
              $structures[$si]['separate_capacity_count'] += $capValue;
              if ($per_vol>0) $structures[$si]['separate_capacity_l'] += ($capValue * $per_vol);
            }
            // also keep grand totals
            $structures[$si]['total_used_count'] += $used_count;
            if ($unit === 'l'){
              $structures[$si]['total_capacity_l'] += $capValue;
              if ($per_vol>0) $structures[$si]['total_capacity_count'] += ($capValue / $per_vol);
            } else {
              $structures[$si]['total_capacity_count'] += $capValue;
              if ($per_vol>0) $structures[$si]['total_capacity_l'] += ($capValue * $per_vol);
            }
          }

          // add to category totals based on allowed_item/kind
          $scat = php_categorize($st['allowed_item']?:'');
          // if allowed_item is empty or categorization returned 'other', try to infer from storage kind/storage_type
          if (($st['allowed_item'] === null || $st['allowed_item'] === '' ) || $scat === 'other'){
            $kindLower = strtolower(trim($st['kind']?:''));
            $stypeLower = strtolower(trim($st['storage_type']?:''));
            if (strpos($kindLower,'liquid') !== false || strpos($stypeLower,'liquid') !== false || strpos($kindLower,'separate_liquid') !== false) $scat = 'liquids';
            elseif (strpos($kindLower,'material') !== false || strpos($stypeLower,'material') !== false || strpos($kindLower,'shared') !== false) $scat = 'materials';
            elseif (strpos($kindLower,'large') !== false || strpos($stypeLower,'large') !== false) $scat = 'large';
            else if ($scat === 'other') {
              // leave as 'other' if we cannot infer
            }
          }
          // ensure category exists
          if (!isset($categoryTotals[$scat])) $categoryTotals[$scat] = ['capacity_count'=>0.0,'used_count'=>0.0,'capacity_l'=>0.0,'used_l'=>0.0];
          // capacity
          if (floatval($st['per_item_volume']?:0) > 0){
            $per = floatval($st['per_item_volume']);
            if ($unit === 'l'){
              $categoryTotals[$scat]['capacity_l'] += $capValue;
              $categoryTotals[$scat]['capacity_count'] += ($capValue / $per);
            } else {
              $categoryTotals[$scat]['capacity_count'] += $capValue;
              $categoryTotals[$scat]['capacity_l'] += ($capValue * $per);
            }
          } else {
            if ($unit === 'l') $categoryTotals[$scat]['capacity_l'] += $capValue; else $categoryTotals[$scat]['capacity_count'] += $capValue;
          }
          // used
          $categoryTotals[$scat]['used_count'] += $used_count;
          $categoryTotals[$scat]['used_l'] += $used_l;
        }
      }

      // build shared materials aggregate (all facility stocks grouped by item)
      $allStocks = dbQueryAll("SELECT item, SUM(quantity) as qty FROM facility_storage_stock fss JOIN facility_storage fs ON fss.storage_id = fs.id JOIN facility_structures fstr ON fs.structure_id = fstr.id WHERE fstr.facility_id = ? GROUP BY item", [$facility_id]);
      $sharedStocks = [];
      $sharedUsed = 0.0;
      if ($allStocks){
        foreach ($allStocks as $r){
          $cat = php_categorize($r['item']);
          if ($cat === 'materials'){
            $sharedStocks[] = ['item'=>$r['item'],'qty'=>$r['qty']];
            $sharedUsed += floatval($r['qty']);
          }
        }
      }

      // Shared materials aggregation removed: we no longer expose a combined 'Shared Materials' structure here.

      jsonResp(['ok'=>true,'structures'=>$structures,'category_totals'=>$categoryTotals]);
    }
    if ($action === 'add_stock'){
      $storage_id = intval(isset($_POST['storage_id'])?$_POST['storage_id']:0);
      $item = trim(isset($_POST['item'])?$_POST['item']: '');
      $quantity = floatval(isset($_POST['quantity'])?$_POST['quantity']:0);
      if (!$storage_id || $item==='' || $quantity<=0) jsonResp(['ok'=>false,'error'=>'storage_id, item and positive quantity required']);
      // check existing row for same storage/item
      $ex = dbQueryOne('SELECT id, quantity FROM facility_storage_stock WHERE storage_id = ? AND item = ?', [$storage_id, $item]);
      if ($ex && isset($ex['id'])){
        $newq = floatval($ex['quantity']) + $quantity;
        dbExecute('UPDATE facility_storage_stock SET quantity = ? WHERE id = ?', [$newq, $ex['id']]);
      } else {
        dbExecute('INSERT INTO facility_storage_stock (storage_id,item,quantity) VALUES (?,?,?)', [$storage_id, $item, $quantity]);
      }
      jsonResp(['ok'=>true]);
    }
    if ($action === 'set_stock'){
      $storage_id = intval(isset($_POST['storage_id'])?$_POST['storage_id']:0);
      $item = trim(isset($_POST['item'])?$_POST['item']: '');
      $quantity = floatval(isset($_POST['quantity'])?$_POST['quantity']:0);
      if (!$storage_id || $item==='') jsonResp(['ok'=>false,'error'=>'storage_id and item required']);
      // set to provided quantity (delete if zero)
      $ex = dbQueryOne('SELECT id FROM facility_storage_stock WHERE storage_id = ? AND item = ?', [$storage_id, $item]);
      if ($quantity <= 0){ if ($ex && isset($ex['id'])) dbExecute('DELETE FROM facility_storage_stock WHERE id = ?', [$ex['id']]); }
      else {
        if ($ex && isset($ex['id'])) dbExecute('UPDATE facility_storage_stock SET quantity = ? WHERE id = ?', [$quantity, $ex['id']]);
        else dbExecute('INSERT INTO facility_storage_stock (storage_id,item,quantity) VALUES (?,?,?)', [$storage_id, $item, $quantity]);
      }
      jsonResp(['ok'=>true]);
    }
    if ($action === 'clear_stock'){
      $storage_id = intval(isset($_POST['storage_id'])?$_POST['storage_id']:0);
      if (!$storage_id) jsonResp(['ok'=>false,'error'=>'storage_id required']);
      dbExecute('DELETE FROM facility_storage_stock WHERE storage_id = ?', [$storage_id]);
      jsonResp(['ok'=>true]);
    }
    if ($action === 'list_all_items'){
      // gather items from storage stock and recipes inputs (if present)
      $items = [];
      try{
        $rows = dbQueryAll('SELECT DISTINCT item FROM facility_storage_stock');
        if ($rows) foreach ($rows as $r) if (!empty($r['item'])) $items[trim($r['item'])]=true;
      }catch(Exception $e){ }
      try{
        $rows2 = dbQueryAll('SELECT DISTINCT name FROM inputs');
        if ($rows2) foreach ($rows2 as $r) if (!empty($r['name'])) $items[trim($r['name'])]=true;
      }catch(Exception $e){ }
      $list = array_values(array_keys($items)); sort($list, SORT_STRING | SORT_FLAG_CASE);
      jsonResp(['ok'=>true,'items'=>$list]);
    }
    // unknown action fallback (outside specific action handlers)
    jsonResp(['ok'=>false,'error'=>'unknown action']);
  }

// Render UI
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>HUSS Stockpiles</title>
  <link rel="stylesheet" href="css/calculator.css">
  <link rel="stylesheet" href="css/index.css">
  <style>
    body{font-family:Segoe UI,Arial,Helvetica,sans-serif;margin:16px}
    .panel{background:#fafafa;border:1px solid #e0e0e0;padding:12px;margin-bottom:12px;border-radius:6px}
    input,select,button{padding:6px;margin-top:4px}
    .stock-item{font-size:13px;color:#333;margin-right:8px}
    .storage-row{padding:8px;border-top:1px dashed #e6e6e6;margin-top:8px}
    .bar{background:#eee;height:14px;border-radius:7px;overflow:hidden;margin:6px 0}
    .bar-fill{height:100%;background:linear-gradient(90deg,#4caf50,#2e7d32);width:0%;transition:width .3s}
    .bar-negative .bar-fill{background:linear-gradient(90deg,#f57c00,#e65100)}
    h3{margin:0 0 8px 0}
    .storage-row strong{display:inline-block;width:160px}
  /* Summary panel styling for category totals */
  #category-totals-wrap{margin-bottom:12px}
  #category-totals{display:flex;gap:12px;align-items:stretch}
  .summary-card{background:linear-gradient(180deg,#ffffff,#f7fbf7);border:1px solid #e6efe6;border-radius:8px;padding:10px 12px;flex:1;display:flex;align-items:center;gap:12px;box-shadow:0 1px 0 rgba(0,0,0,0.02)}
  .summary-icon{font-size:28px;width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:#f0f7f0}
  .summary-body{flex:1}
  .summary-title{font-size:13px;color:#333;margin-bottom:4px}
  .summary-value{font-size:20px;font-weight:700;color:#1b5e20}
  .summary-sub{font-size:12px;color:#666;margin-top:4px}
  </style>
</head>
<body>
  <main class="site-main">
  <div class="navbar">
    <a class="nav-link" href="index.php">Home</a>
    <a class="nav-link" href="Calculator.php">Calculator</a>
    <a class="nav-link" href="stockpiles.php">Stockpiles</a>
    <a class="nav-link" href="officer.php">Officer</a>
  </div>
  <h2>Stockpiles</h2>
  <div class="panel">
    <label>Select facility</label>
    <select id="facility-select"></select>
    <button id="mapping-toggle" style="margin-left:8px;padding:6px">Mapping</button>
    <div id="mapping-editor" style="display:none;margin-top:8px;border:1px solid #ddd;padding:8px;border-radius:6px;background:#fff">
      <div style="font-size:13px;margin-bottom:6px">Category seed mapping (server field → category)</div>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><label style="width:180px">separate_capacity_count</label><select data-field="separate_capacity_count" class="map-select"></select></div>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><label style="width:180px">separate_capacity_l</label><select data-field="separate_capacity_l" class="map-select"></select></div>
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px"><label style="width:180px">liquid_capacity_l</label><select data-field="liquid_capacity_l" class="map-select"></select></div>
      <div style="display:flex;gap:8px"><button id="mapping-save">Save</button><button id="mapping-reset" style="margin-left:6px">Reset</button><button id="mapping-close" style="margin-left:6px">Close</button></div>
    </div>
  </div>

  <div id="items-panel" class="panel">
    <div id="category-totals"></div>
    <div id="category-totals-wrap" style="display:flex;gap:12px;margin-top:12px">
      <div style="flex:1">
        <h4>Rawresources</h4>
        <div id="cat-raw"></div>
      </div>
      <div style="flex:1">
        <h4>Liquids</h4>
        <div id="cat-liquids"></div>
      </div>
      <div style="flex:1">
        <h4>Materials</h4>
        <div id="cat-materials"></div>
      </div>
    </div>
  </div>

  <div id="structures-wrap"></div>

  <script>
    async function post(action,data={}){
      const f=new FormData(); f.append('action',action); for(const k in data) f.append(k,data[k]);
      const r=await fetch('stockpiles.php',{method:'POST',body:f}); return await r.json();
    }

    const urlParams = new URLSearchParams(window.location.search);
    // Default mapping: server fields -> client category keys
    const DEFAULT_categorySeedMap = {
      'separate_capacity_count': 'materials',
      'separate_capacity_l': 'materials',
      'liquid_capacity_l': 'liquids'
    };
    // Load mapping from localStorage if present, otherwise use defaults
    function loadCategorySeedMap(){
      try{
        const raw = localStorage.getItem('pet_categorySeedMap');
        if (raw) return JSON.parse(raw);
      }catch(e){}
      return Object.assign({}, DEFAULT_categorySeedMap);
    }
    function saveCategorySeedMap(map){ try{ localStorage.setItem('pet_categorySeedMap', JSON.stringify(map)); }catch(e){} }
    // current mapping used by seeding logic
    let categorySeedMap = loadCategorySeedMap();

    // UI helpers for mapping editor
    function buildMappingEditor(){
      const categories = ['raw','liquids','materials','large','other'];
      const selects = document.querySelectorAll('.map-select');
      selects.forEach(sel => {
        // populate options
        sel.innerHTML = '';
        categories.forEach(c => { const o = document.createElement('option'); o.value = c; o.textContent = c; sel.appendChild(o); });
        const field = sel.getAttribute('data-field');
        if (categorySeedMap[field]) sel.value = categorySeedMap[field];
      });
    }
    function openMappingEditor(){ document.getElementById('mapping-editor').style.display = 'block'; buildMappingEditor(); }
    function closeMappingEditor(){ document.getElementById('mapping-editor').style.display = 'none'; }
    function applyMappingEditor(){
      const selects = document.querySelectorAll('.map-select');
      selects.forEach(sel => { const field = sel.getAttribute('data-field'); categorySeedMap[field] = sel.value; });
      saveCategorySeedMap(categorySeedMap);
      // re-render structures if present
      try{ loadStructures(); }catch(e){}
    }
    document.addEventListener('DOMContentLoaded', ()=>{
      const btn = document.getElementById('mapping-toggle');
      if (btn){ btn.addEventListener('click', ()=>{ const ed = document.getElementById('mapping-editor'); if (ed.style.display==='none') openMappingEditor(); else closeMappingEditor(); }); }
      const saveBtn = document.getElementById('mapping-save'); if (saveBtn) saveBtn.addEventListener('click', ()=>{ applyMappingEditor(); closeMappingEditor(); });
      const resetBtn = document.getElementById('mapping-reset'); if (resetBtn) resetBtn.addEventListener('click', ()=>{ categorySeedMap = Object.assign({}, DEFAULT_categorySeedMap); saveCategorySeedMap(categorySeedMap); buildMappingEditor(); applyMappingEditor(); });
      const closeBtn = document.getElementById('mapping-close'); if (closeBtn) closeBtn.addEventListener('click', ()=> closeMappingEditor());
    });
    async function loadFacilities(){
      const r = await post('list_facilities');
      const sel = document.getElementById('facility-select'); sel.innerHTML='';
      if (!r.ok) return sel.innerHTML='<option>error</option>';
      r.facilities.forEach(f=>{ const o=document.createElement('option'); o.value=f.id; o.textContent=f.name; sel.appendChild(o); });
      // if facility_id provided in URL, select it; otherwise default to first
      const fidParam = urlParams.get('facility_id');
      if (fidParam) {
        const opt = Array.from(sel.options).find(o=>o.value === fidParam);
        if (opt) sel.value = fidParam;
      }
      if (sel.options.length>0) { loadStructures(); loadItems(); }
    }

    document.getElementById('facility-select').addEventListener('change', ()=>{ loadStructures(); loadItems(); });

    // categorize item name using explicit lists (user-supplied Rawresources)
    function categorizeName(name){
      if (!name) return 'other';
      const n = name.trim().toLowerCase();
      // explicit exclusion list (do not show these items)
      const excludeList = [
        'facility materials ix', 'facility materials x', 'facility materials xi',
        'naval turbine comps', 'components_min', 'components_max', 'basic materials',
        'heavy assault rifle crate (colonial)', 'heavy assault rifle crate (warden)', 'power mw', 'sniper rifle crate'
      ];
      // explicit Rawresources list provided by user
      const rawList = [
        'aluminum',
        'coal',
        'components',
        'copper',
        'damaged components',
        'iron',
        'rare metal',
        'salvage',
        'sulfur',
        'wreckage'
      ];
      // explicit Materials list provided by user
      const materialsList = [
        'construction materials',
        'explosive powder',
        'gravel',
        'heavy explosive powder',
        'refined materials',
        'assembly materials i',
        'assembly materials ii',
        'assembly materials iii',
        'assembly materials iv',
        'assembly materials v',
        'concrete materials',
        'pipe',
        'processed construction materials',
        'rare alloys',
        'steel construction materials',
        'thermal shielding',
        'unstable substances',
        'barbed wire',
        'metal beam',
        'sandbag'
      ];
      if (excludeList.indexOf(n) !== -1) return 'other';
      if (rawList.indexOf(n) !== -1) return 'raw';
      if (materialsList.indexOf(n) !== -1) return 'materials';
      // liquids detection
      if (n.indexOf('(l)') !== -1 || n.indexOf('liquid') !== -1 || n.indexOf('oil') !== -1 || n.indexOf('water') !== -1) return 'liquids';
      // large materials detection -> classify as materials
      if (n.indexOf('large') !== -1) return 'materials';
      // materials detection (fallback)
      if (n.indexOf('materials') !== -1 || n.indexOf('alloy') !== -1 || n.indexOf('components') !== -1 || n.indexOf('mat') !== -1) return 'materials';
      // otherwise mark as 'other' and do not display in category lists
      return 'other';
    }

    // store latest totals from server so item lists can show inline totals
    let lastCategoryTotals = null;

    function renderItems(items){
      const aRaw = document.getElementById('cat-raw');
      const aLiquids = document.getElementById('cat-liquids');
      const aMaterials = document.getElementById('cat-materials');
      [aRaw,aLiquids,aMaterials].forEach(d=>d.innerHTML='');

      // Category totals are shown in the single `#category-totals` block above.
      // This function intentionally does not render per-column totals here to avoid duplication.

      items.forEach(it => {
        const cat = categorizeName(it);
        const btn = document.createElement('button'); btn.textContent = it; btn.style.margin='4px'; btn.style.padding='6px'; btn.style.fontSize='12px'; btn.addEventListener('click', ()=> itemClicked(it));
        if (cat === 'liquids') aLiquids.appendChild(btn);
        else if (cat === 'materials') aMaterials.appendChild(btn);
        else aRaw.appendChild(btn);
      });
    }

    async function loadItems(){
      const r = await post('list_all_items');
      if (!r.ok) return; renderItems(r.items || []);
    }

    function renderCategoryTotals(ct){
      const wrap = document.getElementById('category-totals'); wrap.innerHTML='';
      if (!ct) return wrap.textContent='No data';
      const map = {'raw':'Rawresources','liquids':'Liquids','materials':'Materials','large':'LargeMaterials','other':'Other'};
      const icons = {'raw':'🪨','liquids':'💧','materials':'🧱','large':'📦','other':'🔸'};
      // create a card for each category; always include 'raw' even when zero
      let appended = 0;
      for (const k of ['raw','liquids','materials','large','other']){
        const data = ct[k] || {capacity_count:0,used_count:0,capacity_l:0,used_l:0};
        const capCount = Number(data.capacity_count)||0; const usedCount = Number(data.used_count)||0;
        const capL = Number(data.capacity_l)||0; const usedL = Number(data.used_l)||0;
        // Always show raw and liquids category cards (even if all zero). For other categories, skip empty ones.
        if (k !== 'raw' && k !== 'liquids' && capCount === 0 && usedCount === 0 && capL === 0 && usedL === 0) continue;
        appended++;
        const card = document.createElement('div'); card.className='summary-card';
        const icon = document.createElement('div'); icon.className='summary-icon'; icon.textContent = icons[k] || '•'; card.appendChild(icon);
        const body = document.createElement('div'); body.className='summary-body';
        const title = document.createElement('div'); title.className='summary-title'; title.textContent = map[k]; body.appendChild(title);
        // primary value and percent
        let primaryText = '';
        let pct = 0;
        if (capL>0){ primaryText = usedL + ' L / ' + capL + ' L'; pct = Math.min(100, Math.round((usedL/capL)*100)); }
        else { primaryText = usedCount + ' / ' + capCount; pct = capCount>0? Math.min(100, Math.round((usedCount/capCount)*100)) : (usedCount>0?100:0); }
        const value = document.createElement('div'); value.className='summary-value'; value.textContent = primaryText; body.appendChild(value);
        const sub = document.createElement('div'); sub.className='summary-sub'; sub.textContent = pct + '% used'; body.appendChild(sub);
        card.appendChild(body);
        wrap.appendChild(card);
      }
      if (!appended) wrap.textContent = 'No category totals to display';
    }

    function itemClicked(name){
      const input = document.querySelector('input[placeholder^="Item name"]');
      if (input){ input.value = name; input.focus(); }
      else alert('No add-item input found on page.');
    }

    async function loadStructures(){
      const fid = document.getElementById('facility-select').value; if (!fid) return;
      const r = await post('list_storage_for_facility',{facility_id: fid});
      // store returned category totals for inline rendering and reload items
      if (r && r.category_totals) {
        lastCategoryTotals = r.category_totals;
        try{ renderCategoryTotals(r.category_totals); }catch(e){ /* ignore */ }
      }
      const wrap = document.getElementById('structures-wrap'); wrap.innerHTML='';
      // refresh item lists so totals show inline
      loadItems();
      if (!r.ok) return wrap.textContent = 'Error: '+(r.error||'failed');
      // compute and display facility-level totals (sum of category totals)
      const facilityTotalsWrapId = 'facility-total-capacity';
      let facilityTotalsWrap = document.getElementById(facilityTotalsWrapId);
      if (!facilityTotalsWrap){ facilityTotalsWrap = document.createElement('div'); facilityTotalsWrap.id = facilityTotalsWrapId; facilityTotalsWrap.style.margin = '8px 0 12px 0'; document.getElementById('structures-wrap').appendChild(facilityTotalsWrap); }
      // compute totals from category totals
      let facilityTotalCount = 0; let facilityTotalL = 0;
      try{
        if (r.category_totals){ for (const k of Object.keys(r.category_totals)){ facilityTotalCount += Number(r.category_totals[k].capacity_count||0); facilityTotalL += Number(r.category_totals[k].capacity_l||0); } }
      }catch(e){ }
      if (facilityTotalL>0){ facilityTotalsWrap.textContent = 'Facility total capacity: ' + facilityTotalL + ' L'; }
      else { facilityTotalsWrap.textContent = 'Facility total capacity: ' + facilityTotalCount; }

      r.structures.forEach(s=>{
        const panel = document.createElement('div'); panel.className='panel';
        // identify for deep-linking
        panel.dataset.structureId = s.id;
        panel.dataset.structureName = s.structure_name;
        // show structure total capacity (sum of its storages). prefer liters if present
          let structCapText = '';
        try{
          // Prefer non-liquid separate capacity for the structure's separate stockpile count
          const sepCount = Number(s.separate_capacity_count || 0); const sepL = Number(s.separate_capacity_l || 0);
          const liqL = Number(s.liquid_capacity_l || 0);
          if (sepL>0) structCapText = sepL + ' L'; else structCapText = sepCount;
          if (liqL>0) structCapText += ' (+ ' + liqL + ' L liquids)';
        }catch(e){ structCapText = s.capacity || '' }
        const h = document.createElement('h3'); h.textContent = s.structure_name + ' — capacity: ' + structCapText; panel.appendChild(h);
        if (s.storage && s.storage.length){
          // compute category breakdown like admin.php (capacity contribution respects slots)
          // Build a category map seeded from server-calculated separate and liquid totals
          const categoryMap = {};
          try{
            // Use the configurable mapping to seed categoryMap from server-provided fields
            for (const serverField in categorySeedMap){
              const cat = categorySeedMap[serverField];
              const val = Number(s[serverField] || 0);
              if (val > 0){ categoryMap[cat] = (categoryMap[cat] || 0) + val; }
            }
          }catch(e){}

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
          const breakdownEl = document.createElement('div'); breakdownEl.style.marginTop='6px'; breakdownEl.style.fontSize='13px'; breakdownEl.style.color='#333';
          const cats = ['raw','liquids','materials','large','other']; const parts = [];
          cats.forEach(k=>{ if (categoryMap[k] && categoryMap[k] > 0) parts.push(k + ': ' + categoryMap[k]); });
          breakdownEl.textContent = parts.length? ('Breakdown: ' + parts.join(' • ')) : 'Breakdown: none';
          panel.appendChild(breakdownEl);

          // Helper: detect if a storage entry represents liquids
          const isLiquidStorage = (st) => {
            try{
              const unit = (st.unit || '').toString().toLowerCase();
              const ai = (st.allowed_item || '').toString().toLowerCase();
              const stype = (st.storage_type || '').toString().toLowerCase();
              if (unit === 'l') return true;
              if (ai.indexOf('(l)') !== -1 || ai.indexOf('liquid') !== -1 || ai.indexOf('oil') !== -1 || ai.indexOf('water') !== -1) return true;
              if (stype.indexOf('liquid') !== -1) return true;
            }catch(e){}
            return false;
          };

          // Group auto storages by identical (capacity, slots) and render the slot lines once per group
          const autos = s.storage.filter(st => String(st.storage_type||'').toLowerCase() === 'auto');
          const nonAutos = s.storage.filter(st => String(st.storage_type||'').toLowerCase() !== 'auto');
          // find non-auto liquid storages used by this structure
          const nonAutoLiquidStorages = nonAutos.filter(isLiquidStorage);
          const liquidNames = Array.from(new Set(nonAutoLiquidStorages.map(st => (st.allowed_item? st.allowed_item : (st.storage_type||st.kind||'Liquid')))));
          if (autos.length > 0){
            const groups = {};
            autos.forEach(st => {
              const cap = (st.capacity !== undefined && st.capacity !== null) ? Number(st.capacity) : 0;
              const sn = (st.slots !== undefined && st.slots !== null) ? Number(st.slots) : 0;
              const key = cap + '_' + sn;
              if (!groups[key]) groups[key] = {cap: cap, slots: sn, items: []};
              groups[key].items.push(st);
            });
            // for each group render the repeated slot lines once
            Object.keys(groups).forEach(k => {
              const g = groups[k];
              if (g.slots && g.slots > 0){
                for (let i=1;i<=g.slots;i++){
                  const sr = document.createElement('div'); sr.className='storage-row';
                  const title = document.createElement('div'); title.innerHTML = '<strong>auto</strong> — ' + g.cap + ' — slot ' + i;
                  sr.appendChild(title);
                  panel.appendChild(sr);
                }
              } else {
                const sr = document.createElement('div'); sr.className='storage-row';
                const title = document.createElement('div'); title.innerHTML = '<strong>auto</strong> — ' + g.cap;
                sr.appendChild(title);
                panel.appendChild(sr);
              }
              // after the slot block, render a compact list of the items that map to this group with their used quantities
              const itemsList = document.createElement('div'); itemsList.style.marginLeft='8px'; itemsList.style.fontSize='12px'; itemsList.style.color='#444';
              g.items.forEach(st => {
                const used = (st.used_count && Number(st.used_count)>0) ? (Number(st.used_count)) : (st.used_l? (Number(st.used_l) + ' L') : 0);
                const nameText = (st.allowed_item? st.allowed_item : (st.storage_type||st.kind||'item'));
                const txt = nameText + (used? (' — ' + used) : '');
                const li = document.createElement('div'); li.style.display = 'flex'; li.style.alignItems = 'center'; li.style.gap = '8px';
                const span = document.createElement('span'); span.textContent = txt; span.style.flex = '1'; li.appendChild(span);
                const addBtn = document.createElement('button'); addBtn.textContent = 'Add'; addBtn.style.marginLeft = '6px';
                addBtn.addEventListener('click', async ()=>{
                  const q = prompt('Quantity to add for "' + nameText + '"', '1'); if (q === null) return; const qty = parseFloat(q) || 0; if (qty <= 0) return alert('Enter a positive quantity');
                  const rr = await post('add_stock', { storage_id: st.id, item: nameText, quantity: qty }); if (!rr.ok) return alert(rr.error || 'failed'); loadStructures();
                });
                li.appendChild(addBtn);
                itemsList.appendChild(li);
              });
              panel.appendChild(itemsList);
              // Also list any liquid items used by this structure in the auto group
              if (liquidNames.length) {
                const liquidsList = document.createElement('div'); liquidsList.style.marginLeft = '8px'; liquidsList.style.fontSize='12px'; liquidsList.style.color='#2a6f97';
                liquidNames.forEach(ln => {
                  const li = document.createElement('div'); li.style.display = 'flex'; li.style.alignItems = 'center'; li.style.gap = '8px';
                  const span = document.createElement('span'); span.textContent = ln; span.style.flex = '1'; li.appendChild(span);
                  const addBtn = document.createElement('button'); addBtn.textContent = 'Add'; addBtn.style.marginLeft = '6px';
                  addBtn.addEventListener('click', async ()=>{
                    // pick a storage id for this liquid: prefer the first matching non-auto liquid storage
                    let targetId = null;
                    if (nonAutoLiquidStorages.length === 1) targetId = nonAutoLiquidStorages[0].id;
                    else if (nonAutoLiquidStorages.length > 1) {
                      const ids = nonAutoLiquidStorages.map(x => x.id + ':' + (x.allowed_item || x.storage_type || 'liquid')).join('\n');
                      const pick = prompt('Multiple liquid storages detected. Enter the storage id to add to:\n' + ids);
                      if (!pick) return; targetId = parseInt(pick) || null;
                    }
                    if (!targetId) return alert('No liquid storage available to add to.');
                    const q = prompt('Quantity to add for "' + ln + '"', '1'); if (q === null) return; const qty = parseFloat(q) || 0; if (qty <= 0) return alert('Enter a positive quantity');
                    const rr = await post('add_stock', { storage_id: targetId, item: ln, quantity: qty }); if (!rr.ok) return alert(rr.error || 'failed'); loadStructures();
                  });
                  li.appendChild(addBtn);
                  liquidsList.appendChild(li);
                });
                panel.appendChild(liquidsList);
              }
            });
          }

          // Recipe-based detection: ask the API whether this structure uses liquids and render an '(extra liquid)' slot (1000 L) when true
          (async function(){
            try{
              const resp = await fetch('php/api.php?structure_uses_liquid=1&structure_name=' + encodeURIComponent(s.structure_name));
              const data = await resp.json();
              if (data && data.uses_liquid) {
                const extraRow = document.createElement('div'); extraRow.className = 'storage-row';
                const title = document.createElement('div'); title.innerHTML = '<strong>(extra liquid)</strong> — adds ' + (data.extra_l || 1000) + ' L';
                extraRow.appendChild(title);
                const controls = document.createElement('div'); controls.style.marginTop = '8px';
                const inQty = document.createElement('input'); inQty.type = 'number'; inQty.placeholder = 'Quantity (L)'; inQty.style.width = '120px'; inQty.value = String(data.extra_l || 1000);
                const sel = document.createElement('select'); sel.style.marginLeft = '8px';
                // populate select with available liquid storages
                if (nonAutoLiquidStorages.length) {
                  nonAutoLiquidStorages.forEach(ls => { const o = document.createElement('option'); o.value = ls.id; o.textContent = (ls.allowed_item || ls.storage_type || ('Storage '+ls.id)); sel.appendChild(o); });
                } else {
                  const o = document.createElement('option'); o.value = ''; o.textContent = 'No liquid storages'; sel.appendChild(o);
                }
                const btnAddExtra = document.createElement('button'); btnAddExtra.textContent = 'Add Extra'; btnAddExtra.style.marginLeft = '8px';
                btnAddExtra.addEventListener('click', async ()=>{
                  const sid = parseInt(sel.value) || null; if (!sid) return alert('Select a liquid storage'); const q = parseFloat(inQty.value) || 0; if (q<=0) return alert('Enter a positive quantity');
                  // use the selected storage id and add as 'Extra Liquid'
                  const rr = await post('add_stock', { storage_id: sid, item: '(Extra Liquid)', quantity: q }); if (!rr.ok) return alert(rr.error || 'failed'); loadStructures();
                });
                controls.appendChild(inQty); controls.appendChild(sel); controls.appendChild(btnAddExtra);
                extraRow.appendChild(controls);
                panel.appendChild(extraRow);
              }
            }catch(e){ /* ignore API failure gracefully */ }
          })();

          // render non-auto storages as full rows (with bars, item lists and add/clear controls)
          nonAutos.forEach(st=>{
            const sr = document.createElement('div'); sr.className='storage-row';
            const title = document.createElement('div'); title.innerHTML = '<strong>'+ (st.storage_type||st.kind||'Storage') + '</strong> — capacity: '+(st.capacity||'') + (st.unit?(' '+st.unit):''); sr.appendChild(title);
            // used bar and stats
            const cap = parseFloat(st.capacity) || 0;
            const perVol = parseFloat(st.per_item_volume) || 0;
            const usedCount = parseFloat(st.used_count) || 0;
            const usedL = parseFloat(st.used_l) || 0;
            let displayUsed = 0; let displayUnit = st.unit? String(st.unit) : '';
            let pct = 0;
            if (displayUnit.toLowerCase() === 'l'){
              displayUsed = usedL;
              const capL = cap;
              pct = capL>0? Math.min(100, Math.round((displayUsed/capL)*100)) : 0;
            } else {
              if (usedCount > 0){ displayUsed = usedCount; pct = cap>0? Math.min(100, Math.round((displayUsed/cap)*100)) : 0; }
              else if (usedL > 0){ if (perVol > 0){ displayUsed = usedL / perVol; pct = cap>0? Math.min(100, Math.round((displayUsed/cap)*100)) : 0; displayUnit = st.unit || 'items'; } else { displayUsed = usedL; displayUnit = 'L'; pct = 0; } }
              else { displayUsed = 0; pct = 0; }
            }
            const bar = document.createElement('div'); bar.className='bar'; const fill = document.createElement('div'); fill.className='bar-fill'; fill.style.width = pct + '%'; bar.appendChild(fill); sr.appendChild(bar);
            const stats = document.createElement('div'); stats.style.marginTop='6px'; stats.innerHTML = 'Used: '+Number(Math.round(displayUsed*100)/100) + (displayUnit?(' '+displayUnit):'') + ' ('+pct+'%)'; sr.appendChild(stats);
            if (st.stocks && st.stocks.length){ const stockLine = document.createElement('div'); stockLine.style.marginTop='6px'; st.stocks.forEach(it=>{ const span = document.createElement('span'); span.className='stock-item'; span.textContent = it.item + ': ' + it.qty; stockLine.appendChild(span); }); sr.appendChild(stockLine); }
            // add/clear controls
            const fdiv = document.createElement('div'); fdiv.style.marginTop='8px';
            const inName = document.createElement('input'); inName.placeholder='Item name (e.g. Steel Construction Materials)'; inName.style.width='40%';
            const inQty = document.createElement('input'); inQty.type='number'; inQty.placeholder='Quantity'; inQty.style.width='100px'; inQty.value='1';
            const btnAdd = document.createElement('button'); btnAdd.textContent='Add'; btnAdd.addEventListener('click', async ()=>{ const name = inName.value.trim(); const q = parseFloat(inQty.value)||0; if (!name || q<=0) return alert('Enter item and quantity'); const rr = await post('add_stock',{storage_id: st.id, item: name, quantity: q}); if (!rr.ok) return alert(rr.error||'failed'); loadStructures(); });
            const btnClear = document.createElement('button'); btnClear.textContent='Clear All'; btnClear.style.marginLeft='8px'; btnClear.addEventListener('click', async ()=>{ if (!confirm('Clear all stock in this storage?')) return; const rr = await post('clear_stock',{storage_id: st.id}); if (!rr.ok) return alert(rr.error||'failed'); loadStructures(); });
            fdiv.appendChild(inName); fdiv.appendChild(inQty); fdiv.appendChild(btnAdd); fdiv.appendChild(btnClear);
            sr.appendChild(fdiv);
            panel.appendChild(sr);
          });
        } else {
          const none = document.createElement('div'); none.textContent = 'No storage entries for this structure'; panel.appendChild(none);
        }
        wrap.appendChild(panel);
        // if URL contained structure_id or name, scroll to it after render
        const targetId = urlParams.get('structure_id');
        const targetName = urlParams.get('structure_name');
        if (targetId && String(s.id) === String(targetId)) { setTimeout(()=> panel.scrollIntoView({behavior:'smooth'}), 100); }
        if (targetName && targetName === s.structure_name) { setTimeout(()=> panel.scrollIntoView({behavior:'smooth'}), 100); }
      });
    }

    loadFacilities();
  </script>
  </main>
  <footer class="site-footer">
    Created by <strong>Hypha</strong> — <a href="https://cv.tiebocroons.be" target="_blank" rel="noopener noreferrer">cv.tiebocroons.be</a>
  </footer>
</body>
</html>
