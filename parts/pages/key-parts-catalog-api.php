<?php
/**
 * pages/key-parts-catalog-api.php — API จัดการรายการอะไหล่สั่งต่างประเทศ (JSON)
 *
 * ค้นหาชื่อจาก inventory · บันทึก/ลบรายการใน site_settings
 * ใช้จาก modal บน key-parts-order.php (ต้อง login parts + CSRF บน POST)
 *
 * GET  ?action=search&q=
 * GET  ?action=catalog
 * POST action=save | remove (csrf, JSON body หรือ form)
 */

require_once __DIR__ . '/../includes/parts_bootstrap.php';
require_once __DIR__ . '/../includes/key_parts_order.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * ส่ง JSON แล้วจบ request
 *
 * @param array<string,mixed> $payload
 * @param int                 $code
 * @return void
 */
function kpo_catalog_api_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ตรวจ CSRF สำหรับ POST
 *
 * @return void
 */
function kpo_catalog_api_csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    if ($sent === '' && isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $sent = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    $sess = (string) ($_SESSION['csrf'] ?? '');
    if ($sent === '' || $sess === '' || !hash_equals($sess, $sent)) {
        kpo_catalog_api_json(['ok' => false, 'error' => 'โทเคนไม่ถูกต้อง'], 403);
    }
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$action = trim((string) ($_REQUEST['action'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'search') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 1) {
            kpo_catalog_api_json(['ok' => true, 'items' => [], 'inv_connected' => parts_inventory_db() !== null]);
        }
        $items = key_parts_order_search_inventory_catalog($q, 25);
        kpo_catalog_api_json([
            'ok'            => true,
            'items'         => $items,
            'inv_connected' => parts_inventory_db() !== null,
            'inv_error'     => parts_inventory_db_error(),
        ]);
    }

    if ($action === 'catalog') {
        kpo_catalog_api_json([
            'ok'    => true,
            'items' => key_parts_order_catalog_editor_rows(),
            'max'   => KEY_PARTS_ORDER_CATALOG_MAX,
        ]);
    }

    kpo_catalog_api_json(['ok' => false, 'error' => 'คำสั่งไม่รู้จัก'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    kpo_catalog_api_csrf_check();

    if ($action === 'save') {
        $raw = (string) ($_POST['items_json'] ?? '');
        if ($raw === '' && isset($_POST['items']) && is_array($_POST['items'])) {
            $rows = $_POST['items'];
        } else {
            $rows = json_decode($raw, true);
            if (!is_array($rows)) {
                kpo_catalog_api_json(['ok' => false, 'error' => 'รูปแบบรายการไม่ถูกต้อง'], 400);
            }
        }
        $result = key_parts_order_save_catalog_setting($rows);
        if (!$result['ok']) {
            kpo_catalog_api_json(['ok' => false, 'error' => $result['error']], 400);
        }
        kpo_catalog_api_json([
            'ok'    => true,
            'items' => key_parts_order_catalog_editor_rows(),
        ]);
    }

    if ($action === 'remove') {
        $removeKey = trim((string) ($_POST['key'] ?? ''));
        if ($removeKey === '') {
            kpo_catalog_api_json(['ok' => false, 'error' => 'ไม่ระบุ key'], 400);
        }
        $rows = [];
        foreach (key_parts_order_catalog_def() as $def) {
            if ((string) $def['key'] === $removeKey) {
                continue;
            }
            $rows[] = $def;
        }
        if ($rows === []) {
            kpo_catalog_api_json(['ok' => false, 'error' => 'ต้องเหลืออย่างน้อย 1 รายการ'], 400);
        }
        $result = key_parts_order_save_catalog_setting($rows);
        if (!$result['ok']) {
            kpo_catalog_api_json(['ok' => false, 'error' => $result['error']], 400);
        }
        kpo_catalog_api_json(['ok' => true, 'items' => key_parts_order_catalog_editor_rows()]);
    }

    kpo_catalog_api_json(['ok' => false, 'error' => 'คำสั่งไม่รู้จัก'], 400);
}

kpo_catalog_api_json(['ok' => false, 'error' => 'Method not allowed'], 405);
