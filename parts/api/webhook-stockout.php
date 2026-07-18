<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/StockService.php';

$response = [
    'success' => false,
    'message' => '',
    'data' => null,
    'error' => null
];

try {
    // Get raw input
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new InvalidArgumentException('Invalid JSON input');
    }

    // Validate required fields
    $productCode = trim($input['product_code'] ?? '');
    $quantity = (int) ($input['quantity'] ?? 0);
    $purpose = trim($input['purpose'] ?? '');

    if (!$productCode) {
        throw new InvalidArgumentException('Missing field: product_code');
    }
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Invalid field: quantity must be > 0');
    }
    if (!$purpose) {
        throw new InvalidArgumentException('Missing field: purpose');
    }

    // Process webhook
    $db = getDB();
    $stock = new StockService($db);

    $docNo = $stock->stockOutByWebhook($productCode, $quantity, $purpose);

    $response['success'] = true;
    $response['message'] = "Stock out via webhook successful";
    $response['data'] = [
        'product_code' => $productCode,
        'quantity' => $quantity,
        'purpose' => $purpose,
        'doc_no' => $docNo,
        'timestamp' => date('Y-m-d H:i:s')
    ];

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    $response['error'] = $e->getMessage();
} catch (Exception $e) {
    http_response_code(500);
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
