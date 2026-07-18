<?php
$pageTitle = 'Webhook Test';
require_once __DIR__ . '/../includes/header.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productCode = trim($_POST['product_code'] ?? '');
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $purpose = trim($_POST['purpose'] ?? '');

    if ($productCode && $quantity > 0 && $purpose) {
        $data = [
            'product_code' => $productCode,
            'quantity' => $quantity,
            'purpose' => $purpose
        ];

        $ch = curl_init(url('/api/webhook-stockout.php'));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
    }
}

$products = $stock->getAllProducts();
?>

<div class="page-header">
    <h1>🧪 Webhook Test</h1>
    <p>ทดสอบส่งคำขอผ่าน Webhook API</p>
</div>

<div class="grid-2">
    <div class="card">
        <h2>ทดสอบ Webhook Stock Out</h2>
        <form method="POST">
            <div class="form-group">
                <label>เลือกอะไหล่</label>
                <select name="product_code" required>
                    <option value="">-- เลือกอะไหล่ --</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= e($p['code']) ?>">
                        [<?= e($p['code']) ?>] <?= e($p['name']) ?>
                        (คงเหลือ: <?= formatNumber($p['quantity']) ?> <?= e($p['unit']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>จำนวน</label>
                <input type="number" name="quantity" min="1" value="1" required>
            </div>
            <div class="form-group">
                <label>จุดประสงค์</label>
                <select name="purpose" required>
                    <option value="">-- เลือก --</option>
                    <option value="ผลิต">ผลิต</option>
                    <option value="ซ่อม">ซ่อม</option>
                    <option value="test">Test</option>
                    <option value="อื่น ๆ">อื่น ๆ</option>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">ส่ง Webhook</button>
        </form>
    </div>

    <?php if (isset($result)): ?>
    <div class="card">
        <h2>📋 ผลลัพธ์</h2>
        <div style="margin-bottom: 1rem;">
            <strong>HTTP Status:</strong> <?= $httpCode ?>
        </div>
        
        <?php if ($result['success']): ?>
            <div class="alert alert-success">
                <strong>✅ สำเร็จ!</strong><br>
                <?= e($result['message']) ?>
            </div>
            <div style="background:#f5f5f5; padding:1rem; border-radius:4px; font-size:0.9em;">
                <div><strong>Doc No:</strong> <code><?= e($result['data']['doc_no']) ?></code></div>
                <div><strong>Product:</strong> <?= e($result['data']['product_code']) ?></div>
                <div><strong>Quantity:</strong> <?= formatNumber($result['data']['quantity']) ?></div>
                <div><strong>Purpose:</strong> <?= e($result['data']['purpose']) ?></div>
                <div><strong>Timestamp:</strong> <?= e($result['data']['timestamp']) ?></div>
            </div>
        <?php else: ?>
            <div class="alert alert-danger">
                <strong>❌ ผิดพลาด!</strong><br>
                <?= e($result['error']) ?>
            </div>
        <?php endif; ?>

        <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #ddd; font-size:0.85em;">
            <strong>Raw Response:</strong><br>
            <code style="display:block; background:#f5f5f5; padding:0.5rem; overflow-x:auto;">
                <?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>
            </code>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="margin-top:2rem;">
    <h2>📖 API Documentation</h2>
    <p><strong>Endpoint:</strong> <code><?= url('/api/webhook-stockout.php') ?></code></p>
    <p><strong>Method:</strong> POST</p>
    <p><strong>Content-Type:</strong> application/json</p>

    <h3 style="margin-top:1.5rem;">Request Body:</h3>
    <code style="display:block; background:#f5f5f5; padding:1rem; border-radius:4px; overflow-x:auto;">
{
  "product_code": "P001",
  "quantity": 5,
  "purpose": "ผลิต"
}
    </code>

    <h3 style="margin-top:1.5rem;">cURL Example:</h3>
    <code style="display:block; background:#f5f5f5; padding:1rem; border-radius:4px; overflow-x:auto; font-size:0.85em;">
curl -X POST <?= url('/api/webhook-stockout.php') ?> \<br>
&nbsp;&nbsp;-H "Content-Type: application/json" \<br>
&nbsp;&nbsp;-d '{"product_code":"P001","quantity":5,"purpose":"ผลิต"}'
    </code>

    <div style="margin-top:1.5rem; padding:1rem; background:#fff3cd; border-radius:4px; font-size:0.9em;">
        <strong>📝 หมายเหตุ:</strong> ดู <a href="<?= url('/api/WEBHOOK_API.md') ?>" target="_blank">API Documentation</a> สำหรับรายละเอียดเพิ่มเติม
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
