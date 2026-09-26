<?php
/**
 * share.php — ทะเบียนสินค้า ( stock): ดู · เพิ่ม · แก้ไข · ลบ · import CSV · sync จากระบบ
 * ข้อมูลรายการสินค้าเก็บที่  stock ที่เดียว (ตกลง 2026-07-13 — เลิกใช้ shared_assets แล้ว)
 * ห้าม ALTER เพิ่มฟิลด์ในตาราง stock — เค้าโครงเป็นของระบบสต๊อกอะไหล่ของทีม
 */
require __DIR__ . '/config.php';
require __DIR__ . '/includes/layout.php';
require_login();
$DB = dbStock();

if (isset($_GET['template']) && $_GET['template'] === 'basic') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_import_template.csv"');
    echo "\xEF\xBB\xBFtimestamp,serial_number,create_name\n";
    echo "15/03/2024 10:30,BP23021294,สมชาย\n";
    echo "2024-03-16 14:00:00,BP23021295,สมหญิง\n";
    exit;
}

/** พาร์สวันที่จาก AppSheet เช่น "7/09/2026 16:25 น." หรือ "12/31/2025 9:37:00 AM" → 'Y-m-d H:i:s' หรือ null */
function share_parse_dt($s, $fmt) {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*(AM|PM|น\.)?)?#iu', $s, $m)) return null;
    list(, $a, $b, $y) = $m;
    $mo = $fmt === 'dmy' ? (int)$b : (int)$a;
    $d  = $fmt === 'dmy' ? (int)$a : (int)$b;
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31) return null;
    $h = isset($m[4]) ? (int)$m[4] : 0; $i = isset($m[5]) ? (int)$m[5] : 0; $sec = isset($m[6]) ? (int)$m[6] : 0;
    $ap = strtoupper($m[7] ?? '');
    if ($ap === 'PM' && $h < 12) $h += 12;
    if ($ap === 'AM' && $h === 12) $h = 0;
    return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $y, $mo, $d, $h, $i, $sec);
}
/** ตรวจรูปแบบวันที่ทั้งไฟล์: เลขตำแหน่งแรก >12 = DD/MM, ตำแหน่งสอง >12 = MM/DD (ห้าม hardcode — ดู import_legacy.php) */
function share_detect_fmt($values) {
    foreach ($values as $v) {
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/\d{4}#', trim((string)$v), $m)) continue;
        if ((int)$m[1] > 12) return 'dmy';
        if ((int)$m[2] > 12) return 'mdy';
    }
    return 'mdy'; // ไม่มีค่าชี้ขาด → ใช้ค่า default ของ AppSheet
}
/** เลขชุดบันทึกถัดไปของตาราง stock */
function stock_next_id() {
    $r = dbStock()->query("SELECT COALESCE(MAX(id),0)+1 m FROM stock");
    return $r ? (int)$r->fetch_assoc()['m'] : 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['act'] ?? '';
    $adminActs = ['sync', 'sync_all', 'refresh_meta', 'import_basic', 'import', 'active_from_status'];
    if (in_array($act, $adminActs, true) && !settings_admin_unlocked()) {
        flash_set('ต้องเข้าหน้าหลังบ้านก่อน', 'err');
        header('Location: ' . BASE_URL . '/share.php');
        exit;
    }

    if ($act === 'add' || $act === 'edit') {
        $sn = trim($_POST['serial_number'] ?? '');
        if ($sn === '') { flash_set('ต้องกรอก Serial Number', 'err'); header('Location: ' . BASE_URL . '/share.php'); exit; }
        $ts = trim($_POST['timestamp'] ?? '');
        $ts = $ts !== '' ? str_replace('T', ' ', $ts) . (strlen($ts) === 16 ? ':00' : '') : null;
        $model  = trim($_POST['model'] ?? '');
        $cname  = trim($_POST['create_name'] ?? '');
        $setup  = trim($_POST['setup_id'] ?? '');
        $setup  = $setup !== '' ? (int)$setup : null;
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        $batch  = (int)($_POST['batch_id'] ?? 0);
        if ($cname === '') { flash_set('ต้องกรอกชื่อผู้บันทึก — ตารางนี้ห้ามมีค่าว่าง', 'err'); header('Location: ' . BASE_URL . '/share.php'); exit; }

        if ($act === 'add') {
            $dup = qr('SELECT serial_number FROM stock WHERE serial_number=?', 's', [$sn], $DB)->fetch_assoc();
            if ($dup) flash_set("Serial \"$sn\" มีอยู่แล้วในตาราง", 'err');
            else {
                if ($batch <= 0) $batch = stock_next_id();
                q('INSERT INTO  stock (`timestamp`, serial_number, model, id, create_name, setup_id, active)
                   VALUES (?,?,?,?,?,?,?)', 'sssisii', [$ts, $sn, $model, $batch, $cname, $setup, $active], $DB);
                flash_set("เพิ่มรายการ $sn แล้ว");
            }
        } else {
            $oldSn = trim($_POST['old_serial'] ?? $sn);
            if ($sn !== $oldSn && qr('SELECT serial_number FROM stock WHERE serial_number=?', 's', [$sn], $DB)->fetch_assoc()) {
                flash_set("Serial \"$sn\" ซ้ำกับรายการอื่น", 'err');
            } else {
                q('UPDATE  stock SET `timestamp`=?, serial_number=?, model=?, id=?, create_name=?, setup_id=?, active=? WHERE serial_number=?',
                  'sssisiis', [$ts, $sn, $model, $batch, $cname, $setup, $active, $oldSn], $DB);
                flash_set("บันทึกการแก้ไข $sn แล้ว");
            }
        }
    } elseif ($act === 'delete') {
        $sn = trim($_POST['serial_number'] ?? '');
        if ($sn !== '') {
            q('DELETE FROM stock WHERE serial_number=?', 's', [$sn], $DB);
            flash_set("ลบรายการ $sn แล้ว");
        }
    } elseif ($act === 'delete_bulk') {
        $sns = array_values(array_filter(array_map(function ($sn) {
            $sn = trim((string)$sn);
            return ($sn !== '' && mb_strlen($sn) <= 80) ? $sn : null;
        }, (array)($_POST['serial_numbers'] ?? []))));
        $deleted = 0;
        if ($sns) {
            // ลบทีเดียวด้วย IN (...) แทน execute ทีละแถวในลูป (เดิมเป็น N+1 round trip)
            $placeholders = implode(',', array_fill(0, count($sns), '?'));
            $st = $DB->prepare("DELETE FROM stock WHERE serial_number IN ($placeholders)");
            if ($st) {
                $st->bind_param(str_repeat('s', count($sns)), ...$sns);
                $st->execute();
                $deleted = $st->affected_rows;
            }
        }
        flash_set($deleted > 0 ? "ลบ $deleted รายการที่เลือกแล้ว" : 'ไม่มีรายการถูกลบ', $deleted > 0 ? 'ok' : 'err');
    } elseif ($act === 'bulk_update') {
        // ตั้งค่า active และ/หรือ timestamp ให้หลายรายการที่ติ๊กเลือกพร้อมกัน
        $sns = array_values(array_filter(array_map(function ($sn) {
            $sn = trim((string)$sn);
            return ($sn !== '' && mb_strlen($sn) <= 80) ? $sn : null;
        }, (array)($_POST['serial_numbers'] ?? []))));

        $set = []; $types2 = ''; $params2 = []; $desc = [];
        $setActive = $_POST['set_active'] ?? '';
        if ($setActive === '0' || $setActive === '1') {
            $set[] = 'active = ?';
            $types2 .= 'i';
            $params2[] = (int)$setActive;
            $desc[] = 'active = ' . $setActive;
        }
        $tsMode = $_POST['set_ts_mode'] ?? '';
        if ($tsMode === 'now') {
            $set[] = '`timestamp` = NOW()';
            $desc[] = 'เวลา = ตอนนี้';
        } elseif ($tsMode === 'custom') {
            $tsVal = trim((string)($_POST['ts_value'] ?? ''));
            $tsVal = $tsVal !== '' ? str_replace('T', ' ', $tsVal) . (strlen($tsVal) === 16 ? ':00' : '') : '';
            $dt = $tsVal !== '' ? date_create($tsVal) : false;
            if (!$dt) {
                flash_set('รูปแบบวันเวลาไม่ถูกต้อง — เลือกวันเวลาก่อนกดตั้งเวลา', 'err');
                header('Location: ' . BASE_URL . '/share.php' . (isset($_POST['back']) && $_POST['back'] !== '' ? '?' . $_POST['back'] : ''));
                exit;
            }
            $set[] = '`timestamp` = ?';
            $types2 .= 's';
            $params2[] = $dt->format('Y-m-d H:i:s');
            $desc[] = 'เวลา = ' . $dt->format('d/m/Y H:i');
        }

        $updated = 0;
        if ($sns && $set) {
            $placeholders = implode(',', array_fill(0, count($sns), '?'));
            $st = $DB->prepare('UPDATE stock SET ' . implode(', ', $set) . " WHERE serial_number IN ($placeholders)");
            if ($st) {
                $types2 .= str_repeat('s', count($sns));
                $params2 = array_merge($params2, $sns);
                $st->bind_param($types2, ...$params2);
                $st->execute();
                $updated = $st->affected_rows;
            }
        }
        if (!$set) {
            flash_set('ไม่ได้เลือกว่าจะตั้งค่าอะไร', 'err');
        } else {
            flash_set('ตั้ง ' . implode(' · ', $desc) . ' ให้ ' . count($sns) . ' รายการที่เลือกแล้ว (เปลี่ยนจริง ' . $updated . ')');
        }
    } elseif ($act === 'sync') {
        // ดึงเครื่องในระบบที่ยังไม่มีในตาราง stock — active ตามสถานะ (เครื่องใหม่เท่านั้นที่นับเป็นสต๊อก)
        $max = stock_next_id() - 1;
        $DB->query("INSERT IGNORE INTO  stock (`timestamp`, serial_number, model, id, create_name, setup_id, active)
            SELECT COALESCE(pr.last_dt, a.produced_at), a.asset_code, p.name,
                   $max + DENSE_RANK() OVER (ORDER BY COALESCE(pr.last_dt, a.produced_at), p.name, COALESCE(pr.last_made_by,'')),
                   COALESCE(pr.last_made_by, ''), NULL, IF(a.status = 'new', 1, 0)
            FROM assets a JOIN products p ON p.id = a.product_id
            LEFT JOIN (SELECT asset_id,
                              MAX(recorded_at) last_dt,
                              SUBSTRING_INDEX(GROUP_CONCAT(made_by ORDER BY recorded_at DESC, id DESC SEPARATOR '||'), '||', 1) last_made_by
                       FROM production_records WHERE made_by IS NOT NULL AND TRIM(made_by)<>'' GROUP BY asset_id) pr ON pr.asset_id = a.id");
        flash_set('ดึงจากระบบแล้ว — เพิ่มใหม่ ' . $DB->affected_rows . ' รายการ');
    } elseif ($act === 'active_from_status') {
        $r = stock_active_apply();
        flash_set('ตั้ง Active ตามสถานะเครื่องแล้ว — เป็น 1 (เครื่องใหม่) ' . number_format($r['to1'])
            . ' · เป็น 0 (เบิกใช้งานแล้ว) ' . number_format($r['to0'])
            . ' · เปลี่ยนจริง ' . number_format($r['changed']) . ' รายการ');
    } elseif ($act === 'refresh_meta') {
        $n = share_refresh_meta_from_production();
        flash_set('อัปเดตรุ่น/เวลา/ผู้ผลิตจากระบบผลิตแล้ว — แก้ไข ' . number_format($n) . ' รายการ');
    } elseif ($act === 'sync_all') {
        $r = share_sync_all_from_production();
        flash_set('ซิงก์ครบแล้ว — เพิ่ม ' . number_format($r['added']) . ' · อัปเดต ' . number_format($r['updated_meta'])
            . ' · เหลือไม่ครบ ' . number_format($r['incomplete_remaining']));
    } elseif ($act === 'import_basic') {
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            flash_set('อัปโหลดไฟล์ไม่สำเร็จ — เลือกไฟล์ .csv แล้วลองใหม่', 'err');
        } else {
            $ext = strtolower(pathinfo($_FILES['csv']['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                flash_set('รองรับเฉพาะไฟล์ .csv', 'err');
            } else {
                $result = share_import_basic_csv($_FILES['csv']['tmp_name']);
                if (!empty($result['error'])) {
                    flash_set($result['error'], 'err');
                } else {
                    $msg = 'Import แล้ว: เพิ่ม ' . number_format($result['added'])
                        . ' · อัปเดต ' . number_format($result['updated'])
                        . ' · เติมจากระบบผลิต ' . number_format($result['filled_from_production'])
                        . ' · ข้าม ' . number_format($result['skipped']);
                    if ($result['not_in_production'] > 0) {
                        $msg .= ' · ไม่พบในระบบผลิต ' . number_format($result['not_in_production']);
                    }
                    if (!empty($result['date_fmt'])) {
                        $msg .= ' (รูปแบบวันที่: ' . ($result['date_fmt'] === 'dmy' ? 'วัน/เดือน' : 'เดือน/วัน') . ')';
                    }
                    flash_set($msg);
                }
            }
        }
    } elseif ($act === 'import') {
        // import CSV จาก AppSheet: คอลัมน์ timestamp,serial_number,model,id,create_name,setup_id,active
        if (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
            flash_set('อัปโหลดไฟล์ไม่สำเร็จ — เลือกไฟล์ .csv แล้วลองใหม่', 'err');
        } else {
            $fh = fopen($_FILES['csv']['tmp_name'], 'r');
            $head = fgetcsv($fh);
            if ($head && isset($head[0])) $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]); // ตัด BOM
            $col = array_flip(array_map(function ($c) { return strtolower(trim($c)); }, (array)$head));
            if (!isset($col['serial_number'])) {
                flash_set('ไฟล์ไม่ถูกต้อง — ต้องมีคอลัมน์ serial_number (หัวตารางแบบ AppSheet ViewData)', 'err');
            } else {
                $rows = [];
                while (($r = fgetcsv($fh)) !== false) $rows[] = $r;
                $tsIdx = $col['timestamp'] ?? null;
                $fmt = share_detect_fmt($tsIdx === null ? [] : array_column($rows, $tsIdx));
                $batch = stock_next_id();
                $add = 0; $skip = 0;
                // wrap ทั้ง import เป็น 1 transaction — เดิม autocommit ทีละแถว (N+1 round trip + fail กลางทางเหลือข้อมูลครึ่งเดียว)
                $DB->begin_transaction();
                $ins = $DB->prepare('INSERT IGNORE INTO  stock (`timestamp`, serial_number, model, id, create_name, setup_id, active)
                   VALUES (?,?,?,?,?,?,?)');
                foreach ($rows as $r) {
                    $sn = trim($r[$col['serial_number']] ?? '');
                    if ($sn === '') continue;
                    $get = function ($name) use ($col, $r) { return isset($col[$name], $r[$col[$name]]) ? trim($r[$col[$name]]) : ''; };
                    // active ตามไฟล์: Y/1 = นับเป็น stock, N/0 = ไม่นับ (ความหมายเดียวกับระบบ AppSheet เดิม)
                    $actRaw = strtoupper($get('active'));
                    $actVal = ($actRaw === 'N' || $actRaw === '0') ? 0 : 1;
                    $setup  = $get('setup_id');
                    $setup  = $setup !== '' ? (int)$setup : null;
                    $ts = $tsIdx !== null ? share_parse_dt($r[$tsIdx] ?? '', $fmt) : null;
                    $model_ = $get('model'); $cname = $get('create_name');
                    $ins->bind_param('sssisii', $ts, $sn, $model_, $batch, $cname, $setup, $actVal);
                    $ins->execute();
                    if ($ins->affected_rows > 0) $add++; else $skip++;
                }
                $DB->commit();
                flash_set("Import แล้ว: เพิ่มใหม่ $add รายการ · ข้าม $skip รายการที่มี serial อยู่แล้ว (รูปแบบวันที่: " . ($fmt === 'dmy' ? 'วัน/เดือน' : 'เดือน/วัน') . ')');
            }
            fclose($fh);
        }
    }
    $page = (isset($_POST['redirect_page']) && $_POST['redirect_page'] === 'share_admin') ? 'share_admin.php' : 'share.php';
    $back = isset($_POST['back']) && $_POST['back'] !== '' ? '?' . $_POST['back'] : '';
    header('Location: ' . BASE_URL . '/' . $page . $back); exit;
}

// ---------- สถิติภาพรวม ----------
$stat = $DB->query("SELECT COUNT(*) c, SUM(active=1) a1, SUM(active=0) a0,
                            SUM(create_name='' OR create_name IS NULL) nn,
                            SUM(`timestamp` IS NULL) nt,
                            SUM(`timestamp` IS NULL OR model='' OR model IS NULL OR create_name='' OR create_name IS NULL) inc
                     FROM stock")->fetch_assoc();

// ---------- Active ตรงกับสถานะเครื่องหรือยัง ----------
// นับทุกครั้งที่เปิดหน้า: อ่าน 2 ตารางแบบ scan ทั้งตาราง ใช้เวลาไม่ถึงวินาทีที่ขนาดข้อมูลจริง
try {
    $activeDiff = stock_active_diff();
} catch (Throwable $e) {
    error_log('[share.php stock_active_diff] ' . $e->getMessage());
    $activeDiff = null;
}

// ---------- รายการ + ฟิลเตอร์ ----------
$search = trim($_GET['q'] ?? '');
$model  = $_GET['model'] ?? '';
$flt    = $_GET['f'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 50;
$sort   = $_GET['sort'] ?? 'time_code';
$sortSql = [
    // ค่า timestamp ใน stock sync จาก MAX(production_records.recorded_at) แล้ว — เรียงแบบเดียวกับ assets.php
    'time_code' => '(`timestamp` IS NULL), `timestamp` DESC, serial_number DESC',
    'date_desc' => '(`timestamp` IS NULL), `timestamp` DESC, serial_number DESC',
    'date_asc'  => '(`timestamp` IS NULL), `timestamp` ASC, serial_number ASC',
    'code'      => 'serial_number DESC',
    'recent'    => 'id DESC, serial_number DESC',
];
if (!isset($sortSql[$sort])) {
    $sort = 'time_code';
}

$where = []; $types = ''; $params = [];
if ($search !== '') {
    $where[] = '(serial_number LIKE ? OR model LIKE ? OR create_name LIKE ?)';
    $types .= 'sss';
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}
if ($model !== '') {
    $where[] = 'model = ?';
    $types .= 's';
    $params[] = $model;
}
switch ($flt) {
    case 'active1':    $where[] = 'active = 1'; break;
    case 'active0':    $where[] = 'active = 0'; break;
    case 'incomplete': $where[] = "(`timestamp` IS NULL OR model='' OR model IS NULL OR create_name='' OR create_name IS NULL)"; break;
    case 'no_name':    $where[] = "(create_name='' OR create_name IS NULL)"; break;
    case 'no_ts':      $where[] = '`timestamp` IS NULL'; break;
}
$w = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = qr("SELECT COUNT(*) c FROM stock $w", $types, $params, $DB)->fetch_assoc()['c'];
$pages = max(1, (int)ceil($totalRows / $per));
$page  = min($page, $pages);
$off = ($page - 1) * $per;
$rows = qr("SELECT * FROM stock $w ORDER BY {$sortSql[$sort]} LIMIT $per OFFSET $off", $types, $params, $DB);
$modelList = $DB->query("SELECT DISTINCT model FROM stock WHERE model IS NOT NULL AND model<>'' ORDER BY model");
$qs = http_build_query(array_filter(['q' => $search, 'model' => $model, 'f' => $flt, 'sort' => $sort !== 'time_code' ? $sort : null, 'page' => $page > 1 ? $page : null]));
$nextId = stock_next_id();

/**
 * ป้าย Active — สีมาจากพาเลตสถานะกลางเท่านั้น (shared/ui_status_palette.php)
 *
 * @param int $v
 * @return string
 */
function stock_active_badge(int $v): string
{
    $key = $v === 1 ? 'new' : 'retired';
    return '<span class="badge" style="' . h(status_badge_style($key)) . '">' . ($v === 1 ? '1' : '0') . '</span>';
}

function stflink($f, $v, $cur, $qsKeep) {
    // ต้องมีคลาส btn ด้วย — btn-sm คุมแค่ความสูง/ระยะขอบใน/ขนาดอักษร
    // มุมโค้ง เส้นขอบ และการจัดวางอยู่ใน .btn ถ้าไม่ใส่ ชิปจะกลายเป็นกล่องเหลี่ยมหลุดธีม
    $on = $cur === $f;
    $u = '?' . http_build_query(array_filter(array_merge($qsKeep, ['f' => $f])));
    return '<a href="' . h($u) . '" class="btn btn-sm btn-line stock-chip' . ($on ? ' is-on' : '') . '"'
         . ($on ? ' aria-current="page"' : '') . '>' . $v . '</a>';
}
$qsKeep = ['q' => $search, 'model' => $model, 'sort' => $sort !== 'time_code' ? $sort : null];

page_header('ทะเบียนสินค้า (stock)');
?>
<p class="muted stock-intro">
  ทะเบียนสินค้า <code>stock</code> ·
  <?= stock_active_badge(1) ?> เครื่องใหม่ที่ยังอยู่ในสต๊อก ·
  <?= stock_active_badge(0) ?> เบิกใช้งานแล้ว (ขาย · เช่า · สำรอง · เสื่อมสภาพ · สูญหาย · ไม่มีสถานะ)
  <?php if (settings_admin_unlocked()) { ?>
  · <a href="<?= BASE_URL ?>/share_admin.php" class="muted"><?= ui_icon_html('settings', 13, 'h-svg') ?> เครื่องมือหลังบ้าน (Import / Sync)</a>
  <?php } ?>
</p>

<!-- สถิติ -->
<div class="stock-stats">
  <div class="stock-stat-card">
    <div class="stock-stat-num"><?= number_format($stat['c']) ?></div>
    <div class="muted stock-stat-lbl">ทั้งหมด</div>
  </div>
  <div class="stock-stat-card">
    <div class="stock-stat-num" style="color:<?= h(status_palette_entry('new')['fg']) ?>"><?= number_format($stat['a1']) ?></div>
    <div class="muted stock-stat-lbl">active 1 · อยู่ในสต๊อก</div>
  </div>
  <div class="stock-stat-card">
    <div class="stock-stat-num" style="color:<?= h(status_palette_entry('retired')['fg']) ?>"><?= number_format($stat['a0']) ?></div>
    <div class="muted stock-stat-lbl">active 0 · เบิกใช้งานแล้ว</div>
  </div>
  <div class="stock-stat-card">
    <div class="stock-stat-num" style="color:<?= (int)$stat['inc'] ? h(status_palette_entry('lost')['fg']) : h(status_palette_entry('new')['fg']) ?>"><?= number_format($stat['inc']) ?></div>
    <div class="muted stock-stat-lbl">ข้อมูลไม่ครบ<?= (int)$stat['inc'] === 0 ? ' ' . ui_icon_html('check', 13) : '' ?></div>
  </div>
</div>

<?php if ($activeDiff !== null && $activeDiff['diff'] > 0) { ?>
<div class="stock-fix">
  <div class="stock-fix-txt">
    <b><?= number_format($activeDiff['diff']) ?> รายการ</b> มีค่า Active ไม่ตรงกับสถานะเครื่องในระบบผลิต
    <div class="muted stock-fix-sub">
      ต้องเป็น 1 (เครื่องใหม่) <?= number_format($activeDiff['to1']) ?> ·
      ต้องเป็น 0 (เบิกใช้งานแล้ว) <?= number_format($activeDiff['to0']) ?><?php
      if ($activeDiff['no_asset'] > 0) { ?> · ไม่พบ serial ในระบบผลิต <?= number_format($activeDiff['no_asset']) ?><?php } ?>
      · แก้แล้วจะเหลือ active 1 = <?= number_format($activeDiff['a1_after']) ?> รายการ
      (เครื่องใหม่ในระบบผลิตมี <?= number_format($activeDiff['new_assets']) ?> เครื่อง<?php
        if ($activeDiff['new_assets'] > $activeDiff['a1_after']) {
            echo ' · อีก ' . number_format($activeDiff['new_assets'] - $activeDiff['a1_after']) . ' เครื่องยังไม่มีในทะเบียนนี้';
        } ?>)
    </div>
  </div>
  <?php if (settings_admin_unlocked()) { ?>
  <form method="post" onsubmit="return confirm('ตั้ง Active ของทะเบียนสินค้าใหม่ตามสถานะเครื่อง?\n\nเป็น 1 จำนวน <?= number_format($activeDiff['to1']) ?> รายการ\nเป็น 0 จำนวน <?= number_format($activeDiff['to0']) ?> รายการ\n\nหลังแก้ active 1 จะเหลือ <?= number_format($activeDiff['a1_after']) ?> รายการ')">
    <?= csrf_field() ?><input type="hidden" name="act" value="active_from_status"><input type="hidden" name="back" value="<?= h($qs) ?>">
    <button type="submit" class="btn btn-sm btn-with-icon"><?= ui_btn_label('refresh', 'ตั้ง Active ตามสถานะเครื่อง') ?></button>
  </form>
  <?php } else { ?>
  <span class="muted stock-fix-sub">แก้ได้ที่หน้าหลังบ้าน</span>
  <?php } ?>
</div>
<?php } ?>

<div class="stock-actions">
  <details class="panel stock-add-panel">
    <summary><?= ui_icon_html('plus', 14, 'h-svg') ?> เพิ่มรายการ</summary>
    <form method="post" class="stock-add-form">
      <?= csrf_field() ?><input type="hidden" name="act" value="add"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <div><label>Serial Number *</label><input type="text" name="serial_number" required></div>
      <div><label>รุ่น/Model</label><input type="text" name="model" list="model-list"></div>
      <div><label>วันเวลาผลิต</label><input type="datetime-local" name="timestamp"></div>
      <div><label>ผู้บันทึก *</label><input type="text" name="create_name" required></div>
      <div><label>id ชุด (ว่าง = <?= $nextId ?>)</label><input type="number" name="batch_id" min="1"></div>
      <div><label>Setup ID</label><input type="number" name="setup_id"></div>
      <div><label>Active</label>
        <select name="active"><option value="1">1 — นับเป็น stock</option><option value="0">0 — ไม่นับ</option></select></div>
      <div class="stock-add-submit"><button type="submit">บันทึก</button></div>
    </form>
  </details>
</div>

<datalist id="model-list"><?php while ($m = $modelList->fetch_assoc()) { ?><option value="<?= h($m['model']) ?>"><?php } ?></datalist>

<div class="stock-toolbar">
<form method="get" class="filter stock-search-form">
  <input type="text" name="q" data-scan="submit" value="<?= h($search) ?>" placeholder="ค้นหา serial / รุ่น / ผู้บันทึก" style="min-width:230px">
  <select name="model">
    <option value="">— ทุกรุ่น —</option>
    <?php $modelList->data_seek(0); while ($m = $modelList->fetch_assoc()) { ?>
      <option value="<?= h($m['model']) ?>" <?= $model === $m['model'] ? 'selected' : '' ?>><?= h($m['model']) ?></option>
    <?php } ?>
  </select>
  <select name="sort" onchange="this.form.submit()">
    <option value="time_code" <?= $sort === 'time_code' ? 'selected' : '' ?>>เวลาบันทึกล่าสุด+รหัสเครื่องมากสุด</option>
    <option value="date_desc" <?= $sort === 'date_desc' ? 'selected' : '' ?>>วันที่ผลิต ใหม่ → เก่า</option>
    <option value="date_asc" <?= $sort === 'date_asc' ? 'selected' : '' ?>>วันที่ผลิต เก่า → ใหม่</option>
    <option value="code" <?= $sort === 'code' ? 'selected' : '' ?>>เรียงตาม Serial Number</option>
    <option value="recent" <?= $sort === 'recent' ? 'selected' : '' ?>>ชุดบันทึกล่าสุด (id)</option>
  </select>
  <input type="hidden" name="f" value="<?= h($flt) ?>">
  <button type="submit">ค้นหา</button>
  <?php if ($search !== '' || $model !== '' || $flt !== '' || $sort !== 'time_code') { ?><a class="btn btn-line" href="<?= BASE_URL ?>/share.php">ล้าง</a><?php } ?>
</form>
</div>

<div class="stock-chips">
  <?= stflink('',           'ทั้งหมด (' . number_format($stat['c']) . ')', $flt, $qsKeep) ?>
  <?= stflink('active1',    'active 1 (' . number_format($stat['a1']) . ')', $flt, $qsKeep) ?>
  <?= stflink('active0',    'active 0 (' . number_format($stat['a0']) . ')', $flt, $qsKeep) ?>
  <?= stflink('incomplete', ui_icon_html('alert', 13, 'h-svg') . ' ข้อมูลไม่ครบ (' . number_format($stat['inc']) . ')', $flt, $qsKeep) ?>
  <?= stflink('no_name',    'ไม่มีชื่อผู้บันทึก (' . number_format($stat['nn']) . ')', $flt, $qsKeep) ?>
  <?= stflink('no_ts',      'ไม่มีเวลา (' . number_format($stat['nt']) . ')', $flt, $qsKeep) ?>
</div>

<div class="stock-list-meta muted">
  ติ๊กเลือกแล้วลบได้หลายรายการ (เฉพาะหน้านี้)
</div>

<div class="stock-bulk-bar" id="stock-bulk-bar" hidden>
  <span>เลือกแล้ว <b id="stock-bulk-count">0</b> รายการ</span>
  <span class="stock-bulk-group">
    <span class="stock-bulk-glbl">Active:</span>
    <button type="button" class="btn btn-sm btn-line" id="stock-bulk-active1" disabled>ตั้งเป็น 1</button>
    <button type="button" class="btn btn-sm btn-line" id="stock-bulk-active0" disabled>ตั้งเป็น 0</button>
  </span>
  <span class="stock-bulk-group">
    <span class="stock-bulk-glbl">เวลา:</span>
    <button type="button" class="btn btn-sm btn-line" id="stock-bulk-tsnow" disabled>= ตอนนี้</button>
    <input type="datetime-local" id="stock-bulk-tsval" class="stock-bulk-ts">
    <button type="button" class="btn btn-sm btn-line" id="stock-bulk-tsset" disabled>ตั้งตามที่เลือก</button>
  </span>
  <span class="stock-bulk-group">
    <button type="button" class="btn btn-sm btn-danger btn-with-icon" id="stock-bulk-delete" disabled><?= ui_btn_label('trash', 'ลบที่เลือก') ?></button>
    <button type="button" class="btn btn-sm btn-line" id="stock-bulk-clear">ยกเลิกการเลือก</button>
  </span>
</div>

<form method="post" id="stock-bulk-form" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="delete_bulk" id="stock-bulk-act">
  <input type="hidden" name="back" value="<?= h($qs) ?>">
  <input type="hidden" name="set_active" value="" id="stock-bulk-set-active">
  <input type="hidden" name="set_ts_mode" value="" id="stock-bulk-set-tsmode">
  <input type="hidden" name="ts_value" value="" id="stock-bulk-set-tsval">
  <div id="stock-bulk-hidden"></div>
</form>

<div class="table-wrap table-wrap-fold">
<table class="list" id="stock-table">
  <?php // data-pri = ลำดับความสำคัญของคอลัมน์ (shared/ui_table.css)
        // 1 เห็นทุกความกว้าง · 2 ยุบลงบรรทัดรองที่ < 900px · 3 ซ่อนที่ < 1100px ?>
  <thead>
  <tr>
    <th data-pri="1" style="width:36px; text-align:center"><input type="checkbox" id="stock-pick-all" title="เลือกทั้งหมดในหน้านี้"></th>
    <th data-pri="2">วันเวลา</th><th data-pri="1">Serial Number</th><th data-pri="2">รุ่น/Model</th>
    <th data-pri="3" style="width:60px">id ชุด</th><th data-pri="3">ผู้บันทึก</th><th data-pri="3">Setup ID</th>
    <th data-pri="1">Active</th><th data-pri="1" class="stock-act-col">จัดการ</th>
  </tr>
  </thead>
  <tbody>
  <?php while ($r = $rows->fetch_assoc()) {
      $inc = $r['timestamp'] === null || $r['model'] === '' || $r['model'] === null || $r['create_name'] === '' || $r['create_name'] === null; ?>
  <tr<?= $inc ? ' class="stock-row-inc"' : '' ?> data-serial="<?= h($r['serial_number']) ?>">
    <td data-pri="1" style="text-align:center"><input type="checkbox" class="stock-pick" value="<?= h($r['serial_number']) ?>" aria-label="เลือก <?= h($r['serial_number']) ?>"></td>
    <td data-pri="2" data-nowrap><?= $r['timestamp'] ? h(date('d/m/Y H:i', strtotime($r['timestamp']))) : '<span class="muted">-</span>' ?></td>
    <td data-pri="1">
      <b><?= h($r['serial_number']) ?></b>
      <?php // บรรทัดรอง — โผล่เองเมื่อคอลัมน์ระดับ 2 ถูกยุบที่จอแคบ ?>
      <span class="cell-sub"><?= h($r['model'] ?: '-') ?><?= $r['timestamp'] ? ' · ' . h(date('d/m/Y', strtotime($r['timestamp']))) : '' ?></span>
    </td>
    <td data-pri="2"><?= h($r['model'] ?: '-') ?></td>
    <td data-pri="3"><?= h($r['id']) ?></td>
    <td data-pri="3"><?= $r['create_name'] !== '' ? h($r['create_name']) : '<span class="muted">-</span>' ?></td>
    <td data-pri="3"><?= $r['setup_id'] !== null ? h($r['setup_id']) : '<span class="muted">-</span>' ?></td>
    <td data-pri="1"><?= stock_active_badge((int)$r['active']) ?></td>
    <td data-pri="1">
      <div class="stock-rowact">
      <details class="stock-edit">
        <summary class="btn btn-sm btn-line">แก้ไข</summary>
        <form method="post" class="stock-edit-form">
          <?= csrf_field() ?><input type="hidden" name="act" value="edit"><input type="hidden" name="old_serial" value="<?= h($r['serial_number']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
          <input type="datetime-local" name="timestamp" value="<?= $r['timestamp'] ? h(date('Y-m-d\TH:i', strtotime($r['timestamp']))) : '' ?>">
          <input type="text" name="serial_number" value="<?= h($r['serial_number']) ?>" required placeholder="Serial Number">
          <input type="text" name="model" value="<?= h($r['model'] ?? '') ?>" list="model-list" placeholder="รุ่น/Model">
          <input type="number" name="batch_id" value="<?= h($r['id']) ?>" min="1" placeholder="id ชุดบันทึก">
          <input type="text" name="create_name" value="<?= h($r['create_name'] ?? '') ?>" required placeholder="ผู้บันทึก (ห้ามว่าง)">
          <input type="number" name="setup_id" value="<?= h($r['setup_id'] ?? '') ?>" placeholder="Setup ID">
          <select name="active"><option value="1" <?= (int)$r['active'] === 1 ? 'selected' : '' ?>>Active: 1 — นับเป็น stock</option><option value="0" <?= (int)$r['active'] === 0 ? 'selected' : '' ?>>Active: 0 — ไม่นับ</option></select>
          <button class="btn-sm" type="submit"><?= ui_btn_label('save', 'บันทึก', 13) ?></button>
        </form>
      </details>
      <form method="post" onsubmit="return confirm('ลบรายการ <?= h($r['serial_number']) ?> ออกจากตาราง stock ?')">
        <?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="serial_number" value="<?= h($r['serial_number']) ?>"><input type="hidden" name="back" value="<?= h($qs) ?>">
        <button type="submit" class="btn btn-sm btn-line stock-del">ลบ</button>
      </form>
      </div>
    </td>
  </tr>
  <?php } ?>
  </tbody>
</table>
</div>

<style>
.stock-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px; }
@media (max-width:720px) { .stock-stats { grid-template-columns:repeat(2,1fr); } }
.stock-stat-card { background:var(--surface,#fff); border:1px solid var(--border,#dde3ec); border-radius:10px; padding:12px 8px; text-align:center; }
.stock-stat-num { font-size:calc(22px * var(--font-scale,1)); font-weight:700; color:var(--primary); line-height:1.2; }
.stock-stat-lbl { font-size:calc(11px * var(--font-scale,1)); margin-top:2px; }
.stock-intro { margin-bottom:12px; line-height:1.9; }
.stock-fix { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
  margin:0 0 14px; padding:11px 14px; border:1px solid var(--border,#dde3ec); border-left:3px solid var(--primary);
  border-radius:10px; background:var(--surface,#fff); }
.stock-fix-txt { min-width:0; }
.stock-fix-sub { font-size:calc(12px * var(--font-scale,1)); margin-top:3px; line-height:1.7; }
.stock-actions { margin-bottom:12px; }
.stock-add-panel { padding:10px 14px; }
.stock-add-panel summary { cursor:pointer; font-weight:600; color:var(--primary); }
.stock-add-form { margin-top:12px; display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; align-items:end; }
.stock-add-form label { display:block; font-size:12px; margin-bottom:2px; }
.stock-add-form input, .stock-add-form select { width:100%; }
.stock-add-submit { grid-column:1 / -1; }
.stock-add-submit button { min-width:120px; }
.stock-toolbar { margin-bottom:10px; }
.stock-search-form { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.stock-chips { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 10px; }
/* ชิปที่เลือกอยู่ — โทนเดียวกับแท็บบน Dashboard และหน้าคู่มือ */
.stock-chip.is-on { background:var(--primary-soft,#fce7f3); color:var(--primary); font-weight:600;
  box-shadow:inset 0 0 0 1.5px var(--primary); }
.stock-chip.is-on:hover { background:var(--primary-soft,#fce7f3); }
.stock-list-meta { font-size:13px; margin:0 0 10px; }
.stock-bulk-bar { display:flex; align-items:center; gap:14px; flex-wrap:wrap;
  margin:0 0 10px; padding:10px 14px; background:var(--surface-2,#faf9fd); border:1px solid var(--border,#dde3ec);
  border-left:3px solid var(--primary); border-radius:8px; }
.stock-bulk-bar[hidden] { display:none !important; }
.stock-bulk-group { display:inline-flex; align-items:center; gap:6px; flex-wrap:wrap;
  padding-left:14px; border-left:1px solid var(--border,#dde3ec); }
.stock-bulk-glbl { font-size:calc(12.5px * var(--font-scale,1)); color:var(--text-muted,#6b6480); font-weight:600; }
.stock-bulk-ts { padding:4px 6px; border:1px solid var(--border,#dde3ec); border-radius:6px; font-family:inherit; font-size:13px; }
#stock-table .stock-pick { width:16px; height:16px; cursor:pointer; accent-color:var(--primary); }
#stock-table tr.stock-picked td { background:var(--surface-2,#faf9fd) !important; }
#stock-table tr.stock-row-inc td { background:var(--surface-2,#faf9fd); }
#stock-table tr.stock-row-inc td:first-child { box-shadow:inset 3px 0 0 var(--danger,#c0392b); }
/* จัดการ — ปุ่มอยู่บรรทัดเดียวกัน ไม่ซ้อนกันจนแถวสูง */
.stock-act-col { width:132px; }
#stock-table td:last-child, #stock-table th:last-child { padding-left:10px; padding-right:10px; }
.stock-rowact { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.stock-rowact .btn-sm { padding-left:9px; padding-right:9px; }
.stock-rowact > form { display:inline-flex; margin:0; }
.stock-edit > summary { list-style:none; cursor:pointer; display:inline-block; }
.stock-edit > summary::-webkit-details-marker { display:none; }
/* ฟอร์มแก้ไขต้องไม่กินที่ตอนยังไม่กาง — display:grid ตายตัวทำให้ details ที่ปิดอยู่ยังจองความสูง
   (ของเดิมเขียน display:grid ไว้ใน style ตรง ๆ แถวเลยสูงเกือบ 90px ทั้งที่ยังไม่ได้กดแก้ไข) */
.stock-edit:not([open]) > .stock-edit-form { display:none; }
.stock-edit[open] > .stock-edit-form { margin-top:8px; display:grid; gap:6px; min-width:220px; }
.stock-del { color:var(--danger,#c0392b); border-color:var(--danger,#c0392b); }
.stock-del:hover { background:var(--danger,#c0392b); color:#fff; }
</style>
<script>
(function(){
  var allCb = document.getElementById('stock-pick-all');
  var bar = document.getElementById('stock-bulk-bar');
  var cntEl = document.getElementById('stock-bulk-count');
  var delBtn = document.getElementById('stock-bulk-delete');
  var clrBtn = document.getElementById('stock-bulk-clear');
  var form = document.getElementById('stock-bulk-form');
  var hidden = document.getElementById('stock-bulk-hidden');

  function picks(){ return Array.prototype.slice.call(document.querySelectorAll('.stock-pick:checked')); }
  function syncRowHighlight(){
    document.querySelectorAll('#stock-table tbody tr, #stock-table tr[data-serial]').forEach(function(tr){
      var cb = tr.querySelector('.stock-pick');
      if (cb) tr.classList.toggle('stock-picked', cb.checked);
    });
  }
  var actionBtns = ['stock-bulk-active1', 'stock-bulk-active0', 'stock-bulk-tsnow', 'stock-bulk-tsset']
    .map(function(id){ return document.getElementById(id); });
  function refresh(){
    var picksArr = picks();
    var n = picksArr.length;
    var total = document.querySelectorAll('.stock-pick').length;
    if (bar) bar.hidden = n === 0;
    if (cntEl) cntEl.textContent = String(n);
    if (delBtn) delBtn.disabled = n === 0;
    actionBtns.forEach(function(b){ if (b) b.disabled = n === 0; });
    if (allCb) allCb.indeterminate = n > 0 && n < total;
    if (allCb) allCb.checked = total > 0 && n === total;
    syncRowHighlight();
  }
  if (allCb) allCb.addEventListener('change', function(){
    document.querySelectorAll('.stock-pick').forEach(function(cb){ cb.checked = allCb.checked; });
    refresh();
  });
  document.addEventListener('change', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('stock-pick')) refresh();
  });
  if (clrBtn) clrBtn.addEventListener('click', function(){
    document.querySelectorAll('.stock-pick').forEach(function(cb){ cb.checked = false; });
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    refresh();
  });
  function fillSelected(){
    hidden.innerHTML = '';
    picks().forEach(function(cb){
      var inp = document.createElement('input');
      inp.type = 'hidden';
      inp.name = 'serial_numbers[]';
      inp.value = cb.value;
      hidden.appendChild(inp);
    });
  }
  /** ส่งฟอร์ม bulk: act = delete_bulk | bulk_update พร้อมค่าที่จะตั้ง */
  function submitBulk(act, setActive, tsMode, tsValue){
    if (!form || !hidden) return;
    fillSelected();
    document.getElementById('stock-bulk-act').value = act;
    document.getElementById('stock-bulk-set-active').value = setActive || '';
    document.getElementById('stock-bulk-set-tsmode').value = tsMode || '';
    document.getElementById('stock-bulk-set-tsval').value = tsValue || '';
    form.submit();
  }
  if (delBtn) delBtn.addEventListener('click', function(){
    var n = picks().length;
    if (!n) return;
    if (!confirm('ลบ ' + n + ' รายการที่เลือกออกจากตาราง stock ?')) return;
    submitBulk('delete_bulk');
  });
  function bindSet(id, msg, setActive, tsMode, needTs){
    var b = document.getElementById(id);
    if (!b) return;
    b.addEventListener('click', function(){
      var n = picks().length;
      if (!n) return;
      var tsValue = '';
      if (needTs) {
        tsValue = document.getElementById('stock-bulk-tsval').value;
        if (!tsValue) { alert('เลือกวันเวลาในช่องก่อน แล้วค่อยกด "ตั้งตามที่เลือก"'); return; }
      }
      if (!confirm('ตั้ง ' + msg + (needTs ? ' (' + tsValue.replace('T', ' ') + ')' : '') + ' ให้ ' + n + ' รายการที่เลือก ?')) return;
      submitBulk('bulk_update', setActive, tsMode, tsValue);
    });
  }
  bindSet('stock-bulk-active1', 'Active = 1', '1', '', false);
  bindSet('stock-bulk-active0', 'Active = 0', '0', '', false);
  bindSet('stock-bulk-tsnow',   'เวลา = ตอนนี้', '', 'now', false);
  bindSet('stock-bulk-tsset',   'เวลา', '', 'custom', true);
  refresh();
})();
</script>

<?php
$pagerBase = BASE_URL . '/share.php?' . http_build_query(array_filter(['q' => $search, 'model' => $model, 'f' => $flt, 'sort' => $sort !== 'time_code' ? $sort : null]));
echo page_pager_html($page, $pages, $per, $totalRows, function ($n) use ($pagerBase) {
    return $pagerBase . ($pagerBase[strlen($pagerBase) - 1] === '?' ? '' : '&') . 'page=' . $n;
}, 'รายการ');
?>
<?php page_footer();
