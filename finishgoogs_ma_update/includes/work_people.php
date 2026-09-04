<?php
/**
 * includes/work_people.php — ทะเบียน "คนทำงาน" + ผูกบัญชี LINE รายคน
 * ────────────────────────────────────────────────────────────────────────────────
 * ชื่อคนที่บันทึกงานกระจายอยู่ใน 3 ฐาน คนละคอลัมน์ และไม่ตรงกับตาราง users
 * (พบ 30 ชื่อ แต่มีบัญชีล็อกอินแค่ 9) ไฟล์นี้เป็นตัวกลางที่รวมชื่อพวกนั้นให้เป็น
 * "คน" หนึ่งคน แล้วผูกกับ LINE user id เพื่อส่งสรุปงานรายเดือนเข้าไลน์ส่วนตัว
 *
 * ตารางอยู่ใน biton_production เท่านั้น — ระบบซ่อม/เช่าอ่านอย่างเดียว ไม่แตะ schema
 * ────────────────────────────────────────────────────────────────────────────────
 */

/** ชื่อที่ไม่ใช่คน — cron กับ legacy import เขียนไว้ ไม่ต้องมีในทะเบียน */
function work_people_ignored_names(): array
{
    return ['system', 'admin', '-', 'null'];
}

/**
 * ทำชื่อให้เทียบกันได้ — พบ "Aui" กับ "AUI" เป็นคนเดียวกันในข้อมูลจริง
 *
 * @param  string $name
 * @return string ชื่อที่ normalize แล้ว ('' = ใช้ไม่ได้)
 */
function work_people_norm(string $name): string
{
    $n = trim(preg_replace('/\s+/u', ' ', $name));
    if ($n === '') {
        return '';
    }
    $lower = mb_strtolower($n, 'UTF-8');
    return in_array($lower, work_people_ignored_names(), true) ? '' : $lower;
}

/**
 * แยกชื่อจากค่าดิบ 1 ช่อง — production_records.made_by เก็บได้หลายคนคั่นด้วย comma
 * ("Ice,Tom" มี 2,600 แถว) ถ้าไม่แยกก่อนนับ คนที่ทำงานร่วมจะหายจากสรุปทั้งคู่
 *
 * @param  string|null $raw
 * @return array<int,string> ชื่อดิบที่ตัดช่องว่างแล้ว (ยังไม่ normalize)
 */
function work_people_split($raw): array
{
    $out = [];
    foreach (preg_split('/\s*[,;\/]\s*/u', (string) $raw) as $part) {
        $p = trim($part);
        if ($p !== '' && work_people_norm($p) !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * สร้างตารางถ้ายังไม่มี
 *
 * ไม่ได้เรียกจาก config.php เพราะหน้าอื่นทั้งระบบไม่ต้องใช้ — ให้เฉพาะหน้ารายงาน/cron/
 * webhook ที่ include ไฟล์นี้เป็นคนเรียก จะได้ไม่ต้องเช็ค schema ทุก request ทั้งแอป
 *
 * @return void
 */
function work_people_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->query(
            "CREATE TABLE IF NOT EXISTS work_people (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                display_name VARCHAR(100) NOT NULL,
                line_user_id VARCHAR(64) DEFAULT NULL,
                line_display_name VARCHAR(100) DEFAULT NULL,
                link_code CHAR(8) DEFAULT NULL,
                link_code_expires_at DATETIME DEFAULT NULL,
                user_id BIGINT UNSIGNED DEFAULT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                notify_enabled TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_line_user (line_user_id),
                KEY idx_link_code (link_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        // เผื่อตารางถูกสร้างไว้ก่อนจะมีช่องเลือกผู้รับ — เติมคอลัมน์ให้ ไม่ต้องลบตารางทิ้ง
        $col = db()->query("SHOW COLUMNS FROM work_people LIKE 'notify_enabled'");
        if ($col && $col->num_rows === 0) {
            db()->query('ALTER TABLE work_people ADD notify_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active');
        }
        // โทเคนเปิดหน้าสรุปงานของตัวเอง — คนทำงานส่วนใหญ่ไม่มีบัญชีในระบบ (30 ชื่อ มี
        // บัญชีแค่ 9) กดปุ่มในไลน์แล้วเจอหน้า login ก็เท่ากับดูไม่ได้ จึงให้ลิงก์พกโทเคน
        // ประจำตัวไปเอง เปิดได้เฉพาะสรุปงานของคนคนนั้น และเพิกถอนได้รายคน
        $col = db()->query("SHOW COLUMNS FROM work_people LIKE 'view_token'");
        if ($col && $col->num_rows === 0) {
            db()->query('ALTER TABLE work_people ADD view_token CHAR(32) DEFAULT NULL AFTER notify_enabled');
            db()->query('ALTER TABLE work_people ADD UNIQUE KEY uq_view_token (view_token)');
        }
        // userId ของ LINE ผูกกับ channel ไม่ใช่กับคน — ที่ได้มาจาก bot ตัวหนึ่งใช้ส่งด้วย
        // bot อีกตัวไม่ได้ ต้องจำไว้ว่า userId นี้มาจาก bot ไหน ไม่งั้นวันที่ย้าย bot
        // จะกลายเป็นส่งไม่ออกเงียบ ๆ โดยหน้าจอยังขึ้นว่า "ผูกแล้ว"
        $col = db()->query("SHOW COLUMNS FROM work_people LIKE 'line_user_bot'");
        if ($col && $col->num_rows === 0) {
            db()->query("ALTER TABLE work_people ADD line_user_bot VARCHAR(10) DEFAULT NULL AFTER line_display_name");
            // ที่ผูกไว้ก่อนหน้านี้มาจาก bot ตัวจริงทั้งหมด (webhook เดิมรับแต่ตัวนั้น)
            db()->query("UPDATE work_people SET line_user_bot='main' WHERE line_user_id IS NOT NULL AND line_user_bot IS NULL");
        }
        // alias เป็น PK กันชื่อเดียวไปผูกสองคน — เก็บเป็นตัวพิมพ์เล็กที่ normalize แล้ว
        db()->query(
            "CREATE TABLE IF NOT EXISTS work_person_aliases (
                alias VARCHAR(100) NOT NULL,
                alias_raw VARCHAR(100) NOT NULL,
                person_id INT UNSIGNED NOT NULL,
                PRIMARY KEY (alias),
                KEY idx_person (person_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_ensure_schema] ' . $e->getMessage());
    }
}

/**
 * ชื่อคนทั้งหมดที่โผล่ในข้อมูลงานของทั้ง 3 ระบบ พร้อมจำนวนแถวที่พบ
 *
 * ใช้ตอน sync ทะเบียน — ระบบซ่อม/เช่าต่อไม่ได้ก็ข้ามไป ไม่ทำให้ทั้งหน้าล้ม
 *
 * @return array<string,array{raw:string,hits:int}> key = ชื่อที่ normalize แล้ว
 */
function work_people_scan_names(): array
{
    $found = [];
    $add = function ($raw, int $hits) use (&$found) {
        foreach (work_people_split($raw) as $name) {
            $key = work_people_norm($name);
            if ($key === '') {
                continue;
            }
            if (!isset($found[$key])) {
                $found[$key] = ['raw' => $name, 'hits' => 0];
            }
            $found[$key]['hits'] += $hits;
        }
    };

    foreach (work_summary_sources_production() as $src) {
        $sql = "SELECT `{$src['actor']}` v, COUNT(*) c FROM `{$src['table']}`
                WHERE `{$src['actor']}` IS NOT NULL AND TRIM(`{$src['actor']}`) <> ''"
             . (isset($src['where']) ? ' AND ' . $src['where'] : '')
             . " GROUP BY v";
        try {
            $res = db()->query($sql);
            while ($row = $res->fetch_assoc()) {
                $add($row['v'], (int) $row['c']);
            }
        } catch (\mysqli_sql_exception $e) {
            error_log('[work_people_scan_names] ' . $e->getMessage());
        }
    }

    foreach (work_summary_sources_maintenance() as $src) {
        $rows = work_summary_external_group(dbMaintenance(), $src['table'], $src['actor']);
        foreach ($rows as $v => $c) {
            $add($v, $c);
        }
    }
    foreach (work_summary_sources_leasing() as $src) {
        $rows = work_summary_external_group(dbLeasing(), $src['table'], $src['actor']);
        foreach ($rows as $v => $c) {
            $add($v, $c);
        }
    }

    uasort($found, function ($a, $b) {
        return $b['hits'] <=> $a['hits'];
    });
    return $found;
}

/**
 * แผนที่ alias → person_id สำหรับ resolve เร็ว ๆ ตอนรวมยอด
 *
 * @return array<string,int>
 */
function work_people_alias_map(): array
{
    work_people_ensure_schema();
    $map = [];
    try {
        $res = db()->query('SELECT alias, person_id FROM work_person_aliases');
        while ($row = $res->fetch_assoc()) {
            $map[(string) $row['alias']] = (int) $row['person_id'];
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_alias_map] ' . $e->getMessage());
    }
    return $map;
}

/**
 * รายชื่อคนในทะเบียน
 *
 * @param  bool $activeOnly
 * @return array<int,array<string,mixed>>
 */
function work_people_all(bool $activeOnly = false): array
{
    work_people_ensure_schema();
    $sql = 'SELECT p.*, (SELECT GROUP_CONCAT(a.alias_raw ORDER BY a.alias_raw SEPARATOR ", ")
                         FROM work_person_aliases a WHERE a.person_id = p.id) aliases
            FROM work_people p';
    if ($activeOnly) {
        $sql .= ' WHERE p.is_active = 1';
    }
    $sql .= ' ORDER BY p.display_name';
    $out = [];
    try {
        $res = db()->query($sql);
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_all] ' . $e->getMessage());
    }
    return $out;
}

/**
 * เพิ่มคนใหม่เข้าทะเบียนสำหรับชื่อที่ยังไม่เคยเห็น (ชื่อละ 1 คน) — คนดูแลค่อยไปรวม
 * ชื่อที่เป็นคนเดียวกันทีหลังด้วย work_people_merge()
 *
 * @return array{added:int,total:int,names:array<int,string>}
 */
function work_people_sync_directory(): array
{
    work_people_ensure_schema();
    $known = work_people_alias_map();
    $found = work_people_scan_names();
    $added = [];
    foreach ($found as $key => $info) {
        if (isset($known[$key])) {
            continue;
        }
        try {
            q('INSERT INTO work_people (display_name) VALUES (?)', 's', [$info['raw']]);
            $pid = (int) db()->insert_id;
            q(
                'INSERT INTO work_person_aliases (alias, alias_raw, person_id) VALUES (?,?,?)',
                'ssi',
                [$key, $info['raw'], $pid]
            );
            $added[] = $info['raw'];
        } catch (\mysqli_sql_exception $e) {
            error_log('[work_people_sync_directory] ' . $e->getMessage());
        }
    }
    return ['added' => count($added), 'total' => count($found), 'names' => $added];
}

/**
 * ยุบคนสองคนให้เหลือคนเดียว (เช่น AUI → Aui) — ย้าย alias ทั้งหมดไปคนปลายทาง
 *
 * @param  int $fromId
 * @param  int $intoId
 * @return bool
 */
function work_people_merge(int $fromId, int $intoId): bool
{
    work_people_ensure_schema();
    if ($fromId <= 0 || $intoId <= 0 || $fromId === $intoId) {
        return false;
    }
    try {
        q('UPDATE work_person_aliases SET person_id=? WHERE person_id=?', 'ii', [$intoId, $fromId]);
        // LINE id ของคนที่ถูกยุบ ถ้าปลายทางยังไม่มีก็ย้ายตามไปด้วย
        $src = qr('SELECT line_user_id, line_display_name FROM work_people WHERE id=?', 'i', [$fromId])->fetch_assoc();
        $dst = qr('SELECT line_user_id FROM work_people WHERE id=?', 'i', [$intoId])->fetch_assoc();
        if ($src && !empty($src['line_user_id']) && (!$dst || empty($dst['line_user_id']))) {
            q('UPDATE work_people SET line_user_id=NULL WHERE id=?', 'i', [$fromId]);
            q(
                'UPDATE work_people SET line_user_id=?, line_display_name=? WHERE id=?',
                'ssi',
                [$src['line_user_id'], $src['line_display_name'], $intoId]
            );
        }
        q('DELETE FROM work_people WHERE id=?', 'i', [$fromId]);
        return true;
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_merge] ' . $e->getMessage());
        return false;
    }
}

/**
 * โทเคนเปิดหน้าสรุปงานของคนนี้ — ไม่มีก็สร้างให้ครั้งแรกที่เรียก
 *
 * @param  int  $personId
 * @param  bool $regenerate true = ออกใหม่ ลิงก์เก่าใช้ไม่ได้ทันที
 * @return string '' ถ้าออกไม่สำเร็จ
 */
function work_people_view_token(int $personId, bool $regenerate = false): string
{
    work_people_ensure_schema();
    try {
        if (!$regenerate) {
            $row = qr('SELECT view_token FROM work_people WHERE id=?', 'i', [$personId])->fetch_assoc();
            if ($row && !empty($row['view_token'])) {
                return (string) $row['view_token'];
            }
        }
        $token = bin2hex(random_bytes(16));
        q('UPDATE work_people SET view_token=? WHERE id=?', 'si', [$token, $personId]);
        return $token;
    } catch (\Throwable $e) {
        error_log('[work_people_view_token] ' . $e->getMessage());
        return '';
    }
}

/**
 * หาคนจากโทเคนในลิงก์
 *
 * @param  string $token
 * @return array<string,mixed>|null
 */
function work_people_by_view_token(string $token): ?array
{
    $token = trim($token);
    // กันโทเคนเปล่าไปแมตช์แถวที่ view_token ยังว่าง
    if ($token === '' || !preg_match('/^[0-9a-f]{32}$/', $token)) {
        return null;
    }
    work_people_ensure_schema();
    try {
        $row = qr('SELECT * FROM work_people WHERE view_token=? LIMIT 1', 's', [$token])->fetch_assoc();
        return $row ?: null;
    } catch (\Throwable $e) {
        error_log('[work_people_by_view_token] ' . $e->getMessage());
        return null;
    }
}

/**
 * ออกรหัสผูกบัญชี — คนเอาไปทักบอตเพื่อบอกว่า LINE ตัวเองคืออันไหน
 *
 * ไม่ใช้ตัวอักษรที่อ่านสับสน (0/O, 1/I/l) เพราะคนต้องพิมพ์เองในแชท
 *
 * @param  int $personId
 * @param  int $ttlMinutes
 * @return string|null รหัส 8 ตัว
 */
function work_people_issue_link_code(int $personId, int $ttlMinutes = 60): ?string
{
    work_people_ensure_schema();
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    try {
        for ($try = 0; $try < 5; $try++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $dup = qr('SELECT id FROM work_people WHERE link_code=? LIMIT 1', 's', [$code])->fetch_assoc();
            if ($dup) {
                continue;
            }
            q(
                'UPDATE work_people SET link_code=?, link_code_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?',
                'sii',
                [$code, $ttlMinutes, $personId]
            );
            return $code;
        }
    } catch (\Throwable $e) {
        error_log('[work_people_issue_link_code] ' . $e->getMessage());
    }
    return null;
}

/**
 * ใช้รหัสผูกบัญชี — เรียกจาก webhook ตอนมีคนทักรหัสมา
 *
 * @param  string $code
 * @param  string $lineUserId
 * @param  string $lineDisplayName
 * @return array{ok:bool,person?:array<string,mixed>,error?:string}
 */
function work_people_consume_link_code(string $code, string $lineUserId, string $lineDisplayName = '', string $bot = 'main'): array
{
    work_people_ensure_schema();
    $code = strtoupper(trim($code));
    $lineUserId = trim($lineUserId);
    if ($code === '' || $lineUserId === '') {
        return ['ok' => false, 'error' => 'ข้อมูลไม่ครบ'];
    }
    try {
        $row = qr(
            'SELECT id, display_name, link_code_expires_at FROM work_people
             WHERE link_code=? LIMIT 1',
            's',
            [$code]
        )->fetch_assoc();
        if (!$row) {
            return ['ok' => false, 'error' => 'ไม่พบรหัสนี้'];
        }
        if (!empty($row['link_code_expires_at']) && strtotime($row['link_code_expires_at']) < time()) {
            return ['ok' => false, 'error' => 'รหัสหมดอายุแล้ว'];
        }
        // LINE เดียวผูกได้คนเดียว — ถ้าเคยผูกกับคนอื่นไว้ ต้องถอดออกก่อน ไม่งั้น UNIQUE ชน
        q('UPDATE work_people SET line_user_id=NULL, line_display_name=NULL WHERE line_user_id=? AND id<>?',
          'si', [$lineUserId, (int) $row['id']]);
        q(
            'UPDATE work_people SET line_user_id=?, line_display_name=?, line_user_bot=?,
                    link_code=NULL, link_code_expires_at=NULL
             WHERE id=?',
            'sssi',
            [$lineUserId, $lineDisplayName, $bot, (int) $row['id']]
        );
        return ['ok' => true, 'person' => $row];
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_consume_link_code] ' . $e->getMessage());
        return ['ok' => false, 'error' => 'บันทึกไม่สำเร็จ'];
    }
}

/**
 * เปิด/ปิดการส่งแจ้งเตือนของคนหนึ่ง
 *
 * แยกจาก is_active ตั้งใจ — is_active คือ "ยังนับงานคนนี้อยู่ไหม" (ยังต้องขึ้นในรายงาน
 * ย้อนหลังแม้คนลาออกไปแล้ว) ส่วน notify_enabled คือ "ส่งไลน์ถึงคนนี้ไหม" คนละคำถามกัน
 *
 * @param  int  $personId
 * @param  bool $on
 * @return void
 */
function work_people_set_notify(int $personId, bool $on): void
{
    work_people_ensure_schema();
    try {
        q('UPDATE work_people SET notify_enabled=? WHERE id=?', 'ii', [$on ? 1 : 0, $personId]);
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_set_notify] ' . $e->getMessage());
    }
}

/**
 * คนที่จะได้รับสรุปจริง ๆ — ต้องครบ 3 อย่าง: เปิดส่ง + ยังใช้งาน + ผูก LINE แล้ว
 *
 * @return array<int,array<string,mixed>>
 */
function work_people_recipients(string $bot = 'main'): array
{
    work_people_ensure_schema();
    $out = [];
    try {
        // เอาเฉพาะคนที่ผูกไว้กับ bot ตัวที่จะใช้ส่ง — userId จาก bot อื่นส่งไปก็ไม่ถึง
        $res = qr(
            'SELECT id, display_name, line_user_id, line_display_name FROM work_people
             WHERE notify_enabled=1 AND is_active=1
               AND line_user_id IS NOT NULL AND TRIM(line_user_id) <> ""
               AND COALESCE(line_user_bot, "main") = ?
             ORDER BY display_name',
            's',
            [$bot]
        );
        while ($row = $res->fetch_assoc()) {
            $out[] = $row;
        }
    } catch (\Throwable $e) {
        error_log('[work_people_recipients] ' . $e->getMessage());
    }
    return $out;
}

/**
 * ถอดการผูก LINE ของคนหนึ่ง
 *
 * @param  int $personId
 * @return void
 */
function work_people_unlink(int $personId): void
{
    work_people_ensure_schema();
    try {
        q('UPDATE work_people SET line_user_id=NULL, line_display_name=NULL WHERE id=?', 'i', [$personId]);
    } catch (\mysqli_sql_exception $e) {
        error_log('[work_people_unlink] ' . $e->getMessage());
    }
}
