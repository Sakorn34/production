# Webhook Stock Out API

ใช้งาน endpoint นี้เพื่อตัด stock อะไหล่โดยอัตโนมัติจากระบบอื่น

## Endpoint

```
POST http://localhost/parts/api/webhook-stockout.php
```

## Request Format

ส่ง JSON POST request ด้วยข้อมูลต่อไปนี้:

```json
{
  "product_code": "P001",
  "quantity": 5,
  "purpose": "ผลิต"
}
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `product_code` | string | Yes | รหัสอะไหล่ (เช่น P001, P002) |
| `quantity` | integer | Yes | จำนวนที่ต้องการตัด stock (ต้อง > 0) |
| `purpose` | string | Yes | จุดประสงค์การใช้ (เช่น "ผลิต", "ซ่อม", "test") |

## Example Usage

### cURL

```bash
curl -X POST http://localhost/parts/api/webhook-stockout.php \
  -H "Content-Type: application/json" \
  -d '{"product_code":"P001","quantity":5,"purpose":"ผลิต"}'
```

### PHP

```php
$data = [
    'product_code' => 'P001',
    'quantity' => 5,
    'purpose' => 'ผลิต'
];

$ch = curl_init('http://localhost/parts/api/webhook-stockout.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$result = json_decode($response, true);

if ($result['success']) {
    echo "Stock out successful: " . $result['data']['doc_no'];
} else {
    echo "Error: " . $result['error'];
}
```

### Python

```python
import requests
import json

url = 'http://localhost/parts/api/webhook-stockout.php'
data = {
    'product_code': 'P001',
    'quantity': 5,
    'purpose': 'ผลิต'
}

response = requests.post(url, json=data)
result = response.json()

if result['success']:
    print(f"Stock out successful: {result['data']['doc_no']}")
else:
    print(f"Error: {result['error']}")
```

## Response Format

### Success Response (HTTP 200)

```json
{
  "success": true,
  "message": "Stock out via webhook successful",
  "data": {
    "product_code": "P001",
    "quantity": 5,
    "purpose": "ผลิต",
    "doc_no": "WEBHOOK-20260624-a1b2c3d4",
    "timestamp": "2026-06-24 14:30:45"
  },
  "error": null
}
```

### Error Response (HTTP 400/500)

```json
{
  "success": false,
  "message": "",
  "data": null,
  "error": "ไม่พบอะไหล่ รหัส: P999"
}
```

## Error Cases

| Error | HTTP Code | Description |
|-------|-----------|-------------|
| Invalid JSON | 400 | ข้อมูล JSON ไม่ถูกต้อง |
| Missing product_code | 400 | ไม่มีรหัสอะไหล่ |
| Invalid quantity | 400 | จำนวนต้อง > 0 |
| Missing purpose | 400 | ไม่มีจุดประสงค์ |
| Product not found | 400 | ไม่พบอะไหล่ |
| Insufficient stock | 400 | สต็อกไม่พอ |
| Database error | 500 | ข้อผิดพลาดจากฐานข้อมูล |

## Features

✅ Automatic doc_no generation (WEBHOOK-YYYYMMDD-xxxxx)  
✅ Stock validation before deduction  
✅ Product lookup by code  
✅ Automatic stock reduction  
✅ Transaction rollback on error  
✅ History logging (stored in stock_out table with "webhook" as issued_by)  
✅ JSON response format for easy integration

## History Tracking

การตัด stock ผ่าน webhook จะถูกบันทึกในตาราง `stock_out` ด้วย:
- `doc_no`: WEBHOOK-YYYYMMDD-xxxxx (รหัสอัตโนมัติ)
- `note`: เหมือนกับ `purpose` ที่ส่งมา (เช่น "ผลิต")
- `issued_by`: "webhook" (แสดงว่ามาจาก webhook)
- `created_at`: เวลาปัจจุบัน
