<?php
/**
 * includes/vendor_import.php — นำเข้า supplier และ purchase_link จากแถว Excel/CSV
 *
 * วัตถุประสงค์: แปลงแถวจาก stockpartDB.xlsx (คอลัมน์ ID, Dealer, Link) เป็น payload UPDATE products
 * องค์ประกอบ: vendor_import_normalize_link(), vendor_import_build_updates(), vendor_import_apply()
 *
 * Flow:
 *   $rows = xlsx_read_rows($path);
 *   $plan = vendor_import_build_updates($rows, $db);
 *   $result = vendor_import_apply($plan['updates'], $db);
 */

/**
 * ทำความสะอาด URL สั่งซื้อ — ตัด fragment ที่ Excel แนบ (onedrive/internal ref)
 *
 * @param string $url
 * @return string|null
 */
function vendor_import_normalize_link(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    // Excel มักแนบ #https://d.docs.live.net/... ต่อท้าย
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
        $before = substr($url, 0, $hashPos);
        $after = substr($url, $hashPos + 1);
        if ($before !== '' && preg_match('#^https?://#i', $before)) {
            $url = $before;
        } elseif (preg_match('#^https?://#i', $after)) {
            // กรณี hash นำหน้า URL จริง (หายาก)
            $url = $after;
        }
    }

    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }

    if (strlen($url) > 500) {
        $url = substr($url, 0, 500);
    }

    return $url;
}

/**
 * ตรวจรหัสอะไหล่ Pxxxxx จากแถว import
 *
 * @param array<string,string> $row
 * @return string
 */
function vendor_import_product_code_from_row(array $row): string
{
    foreach (['ID', 'Code', 'code', 'id'] as $key) {
        $val = strtoupper(trim((string) ($row[$key] ?? '')));
        if (preg_match('/^P\d{3,}$/', $val)) {
            return $val;
        }
    }
    return '';
}

/**
 * สร้างแผน UPDATE จากแถว Excel โดย match products.code
 *
 * @param array<int,array<string,string>> $rows
 * @param PDO|null $db ถ้าระบุจะตรวจว่ามี product ใน DB
 * @return array{updates:array<int,array<string,mixed>>,missing:array<int,string>,skip_empty:int,skip_bad_code:int}
 */
function vendor_import_build_updates(array $rows, ?PDO $db = null): array
{
    $existing = [];
    if ($db instanceof PDO) {
        ensureProductColumns($db);
        $stmt = $db->query('SELECT code FROM products');
        while ($code = $stmt->fetchColumn()) {
            $existing[strtoupper((string) $code)] = true;
        }
    }

    $updates = [];
    $missing = [];
    $skipEmpty = 0;
    $skipBad = 0;
    $seen = [];

    foreach ($rows as $row) {
        $code = vendor_import_product_code_from_row($row);
        if ($code === '') {
            $skipBad++;
            continue;
        }

        $supplier = trim((string) ($row['Dealer'] ?? $row['supplier'] ?? $row['Supplier'] ?? ''));
        $linkRaw = trim((string) ($row['Link'] ?? $row['purchase_link'] ?? $row['Purchase Link'] ?? ''));
        $link = vendor_import_normalize_link($linkRaw);

        if ($supplier === '' && $link === null) {
            $skipEmpty++;
            continue;
        }

        if ($db instanceof PDO && !isset($existing[$code])) {
            $missing[] = $code;
            continue;
        }

        if (isset($seen[$code])) {
            // แถวซ้ำ — ใช้แถวหลัง (ข้อมูลใหม่กว่าในไฟล์)
            foreach ($updates as $idx => $u) {
                if ($u['code'] === $code) {
                    unset($updates[$idx]);
                    break;
                }
            }
        }

        $seen[$code] = true;
        $updates[] = [
            'code' => $code,
            'supplier' => $supplier !== '' ? mb_substr($supplier, 0, 200) : null,
            'purchase_link' => $link,
        ];
    }

    return [
        'updates' => array_values($updates),
        'missing' => array_values(array_unique($missing)),
        'skip_empty' => $skipEmpty,
        'skip_bad_code' => $skipBad,
    ];
}

/**
 * บันทึก supplier / purchase_link ลง products
 *
 * @param array<int,array<string,mixed>> $updates จาก vendor_import_build_updates
 * @param PDO $db
 * @param bool $onlyFillEmpty ถ้า true จะไม่ทับค่า supplier/link ที่มีอยู่แล้ว
 * @return array{updated:int,skipped_existing:int,errors:array<int,string>}
 */
function vendor_import_apply(array $updates, PDO $db, bool $onlyFillEmpty = false): array
{
    ensureProductColumns($db);

    $updated = 0;
    $skipped = 0;
    $errors = [];

    $select = $db->prepare('SELECT id, supplier, purchase_link FROM products WHERE code = ? LIMIT 1');
    $update = $db->prepare('UPDATE products SET supplier = ?, purchase_link = ? WHERE id = ?');

    $db->beginTransaction();
    try {
        foreach ($updates as $item) {
            $code = (string) ($item['code'] ?? '');
            $select->execute([$code]);
            $product = $select->fetch();
            if (!$product) {
                $errors[] = $code . ': ไม่พบใน products';
                continue;
            }

            $newSupplier = $item['supplier'] ?? null;
            $newLink = $item['purchase_link'] ?? null;

            if ($onlyFillEmpty) {
                if ($newSupplier === null && !empty($product['supplier'])) {
                    $newSupplier = $product['supplier'];
                }
                if ($newLink === null && !empty($product['purchase_link'])) {
                    $newLink = $product['purchase_link'];
                }
                if ($newSupplier === ($product['supplier'] ?: null) && $newLink === ($product['purchase_link'] ?: null)) {
                    $skipped++;
                    continue;
                }
            }

            $update->execute([
                $newSupplier,
                $newLink,
                (int) $product['id'],
            ]);
            $updated++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'updated' => $updated,
        'skipped_existing' => $skipped,
        'errors' => $errors,
    ];
}
