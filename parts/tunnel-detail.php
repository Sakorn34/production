<?php

// โหลดรหัสผ่านจาก .env หรือ config.php
require 'config.php'; // หรือฟังก์ชัน getEnvValue() ถ้าใช้ .env

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $pass = isset($_POST['password']) ? $_POST['password'] : '';

    if ($pass !== $DELETE_PASSWORD) {
        echo "<script>alert('❌ รหัสผ่านไม่ถูกต้อง'); window.history.back();</script>";
        exit;
    }

    // --- รหัสถูกต้องแล้ว ทำการลบ Tunnel ได้เลย ---
    // ตัวอย่าง: call API Cloudflare, delete DB, etc.
}


// tunnel-detail.php

$apiToken = $cloudflareApiToken;
$accountId = $cloudflareAccountId;

if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("กรุณาระบุ ?id=<TUNNEL_ID>");
}
$tunnelId = $_GET['id'];

function apiGet($url, $headers)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        die("cURL GET Error: $err");
    }
    curl_close($ch);
    return json_decode($resp, true);
}

function apiDelete($url, $headers)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['success' => false, 'error' => $err];
    }
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($resp, true);
    return ['http_code' => $httpCode, 'response' => $decoded];
}

$tunnelUrl = "https://api.cloudflare.com/client/v4/accounts/$accountId/cfd_tunnel/$tunnelId";
$configUrl = "https://api.cloudflare.com/client/v4/accounts/$accountId/cfd_tunnel/$tunnelId/configurations";

$headers = [
    "Authorization: Bearer $apiToken",
    "Content-Type: application/json"
];

$deleteMsg = null;
$deleteDebug = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {

    $deleteDebug = [];
    $deleteMsg = '';

    // --- 0. เตรียม headers (มีอยู่แล้วข้างบน แต่ให้แน่ใจ) ---
    // $headers = [ "Authorization: Bearer $apiToken", "Content-Type: application/json" ];

    // --- 1. ดึง config ของ tunnel เพื่อหา hostname (ในกรณีที่ยังไม่ได้ถูกเซ็ตก่อน) ---
    $dnsName = '-';
    try {
        $cfg = apiGet($configUrl, $headers);
        if (isset($cfg['result']['config']['ingress']) && is_array($cfg['result']['config']['ingress'])) {
            foreach ($cfg['result']['config']['ingress'] as $entry) {
                if (!empty($entry['hostname'])) {
                    $dnsName = $entry['hostname'];
                    break;
                }
            }
        }
    } catch (Exception $e) {
        $deleteDebug['cfg_error'] = $e->getMessage();
    }

    if (empty($dnsName) || $dnsName === '-') {
        // เก็บ debug แต่ไม่ die ทันที — เราจะพยายามลบทunnel และ DB ต่อไป
        $deleteDebug['dns_delete'] = ['error' => 'ไม่พบค่า hostname ใน config ของ tunnel'];
    } else {
        // --- 2. หา main domain และ zoneId ---
        $domainParts = explode('.', $dnsName);
        if (count($domainParts) < 2) {
            $deleteDebug['dns_delete'] = ['error' => 'hostname ไม่ถูกต้อง: ' . $dnsName];
        } else {
            $mainDomain = implode('.', array_slice($domainParts, -2)); // เช่น example.com
            $zoneListUrl = "https://api.cloudflare.com/client/v4/zones?name=" . urlencode($mainDomain);
            $zoneList = apiGet($zoneListUrl, $headers);

            if (empty($zoneList['result'][0]['id'])) {
                $deleteDebug['dns_delete'] = ['error' => "ไม่พบ Zone สำหรับ $mainDomain", 'raw' => $zoneList];
            } else {
                $zoneId = $zoneList['result'][0]['id'];

                // --- 3. ค้นหา DNS records ที่ชื่อ = $dnsName แล้วลบทั้งหมด ---
                $dnsListUrl = "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records?name=" . urlencode($dnsName);
                $dnsList = apiGet($dnsListUrl, $headers);

                if (empty($dnsList['result'])) {
                    $deleteDebug['dns_delete'] = ['note' => 'ไม่พบ DNS record สำหรับ ' . $dnsName, 'raw' => $dnsList];
                } else {
                    $deleteDebug['dns_delete'] = [];
                    foreach ($dnsList['result'] as $record) {
                        $dnsId = $record['id'];
                        $delDnsUrl = "https://api.cloudflare.com/client/v4/zones/$zoneId/dns_records/$dnsId";
                        $delDnsRes = apiDelete($delDnsUrl, $headers);
                        $deleteDebug['dns_delete'][] = $delDnsRes;
                    }
                }
            }
        }
    }

    // --- 4. ลบ Tunnel ออกจาก Cloudflare ---
    $delRes = apiDelete($tunnelUrl, $headers);
    $deleteDebug['tunnel_delete'] = $delRes;

    // --- 5. ลบข้อมูลจากฐานข้อมูล SQLite (db.db) ---
    $dbFile = __DIR__ . '/db.db';
    if (file_exists($dbFile)) {
        try {
            if (class_exists('SQLite3')) {
                $db = new SQLite3($dbFile);
                $stmt = $db->prepare("DELETE FROM tunnels WHERE tunnel_id = :tid");
                $stmt->bindValue(':tid', $tunnelId, SQLITE3_TEXT);
                $stmt->execute();
                $db->close();
                $deleteDebug['db_delete'] = ['success' => true];
            } elseif (class_exists('PDO')) {
                $pdo = new PDO('sqlite:' . $dbFile);
                $st = $pdo->prepare("DELETE FROM tunnels WHERE tunnel_id = :tid");
                $st->bindValue(':tid', $tunnelId, PDO::PARAM_STR);
                $st->execute();
                $deleteDebug['db_delete'] = ['success' => true];
            } else {
                $deleteDebug['db_delete'] = ['error' => 'ไม่มี SQLite3 หรือ PDO ใน PHP'];
            }
        } catch (Exception $e) {
            $deleteDebug['db_delete'] = ['error' => $e->getMessage()];
        }
    } else {
        $deleteDebug['db_delete'] = ['error' => 'ไม่พบไฟล์ฐานข้อมูล'];
    }

    // --- 6. สรุปผลการลบ: ถ้าลบ tunnel จาก Cloudflare สำเร็จ ให้ redirect ---
    // สมมติชื่อไฟล์หน้าหลักคือ all-tunnel.php

    if ($deleteDebug['db_delete']['success']) {
        // ✅ ลบสำเร็จ
        header("Location: index.php?deleted=1&site=" . urlencode($hostname));
        exit;
    } else {
        // ❌ มีปัญหา
        $errorMessage = isset($deleteDebug['error_message']) ? $deleteDebug['error_message'] : 'ไม่สามารถลบข้อมูลได้';
        header("Location: index.php?error=" . urlencode($errorMessage) . "&site=" . urlencode($hostname));
        exit;
    }
}


$tunnelData = apiGet($tunnelUrl, $headers);
$configData = apiGet($configUrl, $headers);

if (!isset($tunnelData['success']) || $tunnelData['success'] !== true) {
    die("ไม่สามารถดึงข้อมูล tunnel ได้");
}

$t = $tunnelData['result'];
$name = htmlspecialchars($t['name']);
$status = isset($t['status']) ? $t['status'] : '';
$connections = isset($t['connections']) ? $t['connections'] : [];
$connsInactiveAt = isset($t['conns_inactive_at']) ? $t['conns_inactive_at'] : null;
$connsActiveAt = isset($t['conns_active_at']) ? $t['conns_active_at'] : null;

if (!empty($connsInactiveAt)) {
    $dt1 = new DateTime($connsInactiveAt);
    $now = new DateTime();
    $diff = $now->diff($dt1);
    $daysAgo = $diff->days;
    $offlineDisplay = $dt1->format('Y-m-d H:i:s') . " ($daysAgo วันแล้ว)";
} else {
    $offlineDisplay = 'ยังออนไลน์อยู่';
}

$hostname = '-';
if (isset($configData['result']['config']['ingress']) && is_array($configData['result']['config']['ingress'])) {
    foreach ($configData['result']['config']['ingress'] as $entry) {
        if (isset($entry['hostname'])) {
            $hostname = $entry['hostname'];
            break;
        }
    }
}

/* ✅ ดึง token จากไฟล์ db.db */
/* ====== อ่าน token จาก db.db (รองรับ SQLite3 หรือ PDO SQLite เป็น fallback) ====== */
$tokenValue = '-';
$dbFile = __DIR__ . '/db.db'; // ปรับ path ถ้าจำเป็น

function getTokenFromDb($dbFile, $tunnelId)
{
    if (!file_exists($dbFile)) {
        return array('error' => "ไฟล์ฐานข้อมูลไม่พบ: $dbFile");
    }

    // 1) ใช้ SQLite3
    if (class_exists('SQLite3')) {
        try {
            $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
            // ดึงทั้ง token และ user_by
            $stmt = $db->prepare('SELECT token, user_by FROM tunnels WHERE tunnel_id = :tid LIMIT 1');
            $stmt->bindValue(':tid', $tunnelId, SQLITE3_TEXT);
            $res = $stmt->execute();
            $row = $res->fetchArray(SQLITE3_ASSOC);
            $db->close();

            if ($row) {
                return array(
                    'token' => isset($row['token']) ? $row['token'] : null,
                    'user_by' => isset($row['user_by']) ? $row['user_by'] : null
                );
            }
            return array('token' => null, 'user_by' => null);
        } catch (Exception $e) {
            return array('error' => 'SQLite3 error: ' . $e->getMessage());
        }
    }

    // 2) ใช้ PDO
    if (class_exists('PDO')) {
        try {
            $pdo = new PDO('sqlite:' . $dbFile);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $sql = 'SELECT token, user_by FROM tunnels WHERE tunnel_id = :tid LIMIT 1';
            $st = $pdo->prepare($sql);
            $st->bindValue(':tid', $tunnelId, PDO::PARAM_STR);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return array(
                    'token' => isset($row['token']) ? $row['token'] : null,
                    'user_by' => isset($row['user_by']) ? $row['user_by'] : null
                );
            }
            return array('token' => null, 'user_by' => null);
        } catch (Exception $e) {
            return array('error' => 'PDO (sqlite) error: ' . $e->getMessage());
        }
    }

    // ไม่มี SQLite3 หรือ PDO
    return array('error' => 'ไม่มี SQLite3 และไม่มี PDO (หรือ PDO SQLite driver) ใน PHP นี้');
}


// เรียกฟังก์ชันแล้วเตรียมค่าแสดงผล
$resToken = getTokenFromDb($dbFile, $tunnelId);

if (isset($resToken['error'])) {
    // เกิดข้อผิดพลาดในการอ่าน DB
    $tokenValue = 'Error: ' . htmlspecialchars($resToken['error']);
} else {
    if ($resToken['token'] === null) {
        $tokenValue = 'รายการนี้สร้างก่อนมีระบบ ยังไม่มีข้อมูล...'; // ไม่พบ token สำหรับ tunnel_id นี้
    } else {
        $tokenValue = htmlspecialchars($resToken['token']);
    }

    //-----------

    if ($resToken['user_by'] === null) {
        $user_by = 'รายการนี้สร้างก่อนมีระบบ ยังไม่มีข้อมูล...'; // ไม่พบ token สำหรับ tunnel_id นี้
    } else {
        $user_by = '🧑 '.htmlspecialchars($resToken['user_by']);
    }
}


?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8">
    <title>รายละเอียด Tunnel - <?= $name ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            padding: 30px;
            background: #f8f9fa;
        }

        .btn-delete {
            background-color: #e74c3c;
            color: white;
        }

        .btn-delete:hover {
            background-color: #c0392b;
        }

        .small-pre {
            background: #f1f1f1;
            padding: 10px;
            border: 1px solid #ccc;
            white-space: pre-wrap;
        }

        .token-box {
            background: #f8f9fa;
            border: 1px solid #ccc;
            padding: 6px 10px;
            border-radius: 5px;
            font-family: monospace;
            white-space: pre-wrap;
            /* ✅ อนุญาตให้ตัดบรรทัด */
            word-break: break-all;
            /* ✅ ตัดคำตรงไหนก็ได้ */
            overflow-wrap: anywhere;
            /* ✅ ป้องกันการล้น */
            max-width: 100%;
            display: inline-block;
            vertical-align: middle;
        }


        .copy-btn {
            /* margin-left: 8px; */
            margin-top: 4px;
            background-color: #0d6efd;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
        }

        .copy-btn:hover {
            background-color: #09377bff;
        }

        .copy-btn:active {
            transform: scale(0.95);
        }

        .btn-sm {
            margin-top: 4px;
            background-color: #7d7d7dff;
            color: white;
            border: none;
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 14px;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-sm:hover {
            background-color: #4b4b4bff;
        }
    </style>


</head>

<body>
    <div class="container">
        <a href="index.php" class="btn btn-secondary mb-3">← กลับ</a>

        <?php if ($deleteMsg): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($deleteMsg) ?></div>
        <?php endif; ?>

        <style>
            .text-success {
                background-color: #c1ffc4ff;
                font-weight: bold;
                padding: 5px;
                border-radius: 4px;
            }

            .text-danger {
                background-color: #ffc1c1ff;
                font-weight: bold;
                padding: 5px;
                border-radius: 4px;
            }

            .text-warning {
                font-weight: bold;
                background-color: #fff2c9ff;
                padding: 5px;
                border-radius: 4px;
            }
        </style>

        <div class="card mb-4">
            <div class="card-header" style="background-color: #6d6d6dff; color: white; padding: 15px;">รายละเอียด : <strong style="color: #ffea00ff; font-weight: bold;"><?= $name ?></strong></div>
            <div class="card-body">
                <table class="table">
                    <tr>
                        <th>หน่วยงาน</th>
                        <td><?= $name ?></td>
                    </tr>
                    <tr>
                        <th>Domain</th>
                        <td>
                            <?php if (!empty($hostname) && $hostname !== '-'): ?>
                                <a href="https://<?= htmlspecialchars($hostname) ?>"
                                    target="_blank"
                                    class="btn btn-sm btn-primary">
                                    🌐 <?= htmlspecialchars($hostname) ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th>Status</th>
                        <td>
                            <?php
                            if ($status === 'inactive') {
                                echo '<span class="text-warning">ยังไม่ได้ติดตั้ง</span>';
                            } elseif (!empty($connections)) {
                                echo '<span class="text-success">Online</span>';
                            } else {
                                echo '<span class="text-danger">Offline</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Offline ล่าสุด</th>
                        <td><?= $offlineDisplay ?></td>
                    </tr>
                    <tr>
                        <th>ออนไลน์ล่าสุด</th>
                        <td><?= $connsActiveAt ? (new DateTime($connsActiveAt))->format('Y-m-d H:i:s') : '-' ?></td>
                    </tr>
                    <tr>
                    <tr>
                        <th>Token</th>
                        <td>
                            <div class="token-box" id="tokenBox"><?= $tokenValue ?></div>
                            <br>
                            <button type="button" class="copy-btn" onclick="copyToken()">📋 Copy</button>
                        </td>
                    </tr>

                    <tr>
                        <th>สร้างโดย</th>
                        <td>
                            <div class="token-box" style="color: #454545ff; font-weight: bold; background-color: #ffdaa2ff;" id="tokenBox"><?= $user_by ?></div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <script>
            function copyToken() {
                const tokenText = document.getElementById("tokenBox").textContent.trim();
                if (!tokenText) return alert("ไม่มี token ให้คัดลอก");
                navigator.clipboard.writeText(tokenText)
                    .then(() => alert("✅ คัดลอก token แล้ว!"))
                    .catch(err => alert("เกิดข้อผิดพลาด: " + err));
            }
        </script>


        <!-- ปุ่มลบหลัก -->
        <button type="button" class="btn btn-delete" data-bs-toggle="modal" data-bs-target="#deleteModal">
            🗑️ ลบ Tunnel นี้
        </button>

        <!-- Modal ป้อนรหัสผ่าน -->
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post">
                        <input type="hidden" name="action" value="delete">
                        <div class="mb-3">
                            <label for="deletePassword" class="form-label">กรุณาใส่รหัสผ่านก่อนลบ:</label>
                            <input type="password" id="deletePassword" name="password" class="form-control" placeholder="ใส่รหัสผ่านที่นี่" required>
                        </div>
                        <button type="submit" class="btn btn-danger">ยืนยันลบ</button>
                    </form>
                </div>
            </div>
        </div>

        <script>
            function checkPasswordBeforeDelete() {
                // การตรวจรหัสผ่านจริงทำที่ฝั่งเซิร์ฟเวอร์ (ดูส่วนบนของไฟล์นี้)
                // ฝั่ง client เช็คแค่ว่ากรอกอะไรมาบ้างเพื่อ UX เท่านั้น ไม่ใช่ด่านความปลอดภัย
                const pass = document.getElementById('deletePassword').value.trim();
                if (pass === '') {
                    alert('⚠️ กรุณาใส่รหัสผ่านก่อน');
                    return false;
                }
                return true;
            }
        </script>

        <?php if ($deleteDebug): ?>
            <div class="mt-4">
                <h5>Debug Response:</h5>
                <div class="small-pre"><?= htmlspecialchars(json_encode($deleteDebug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></div>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>