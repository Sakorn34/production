<?php

class StockService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getDashboardStats(): array
    {
        $stats = [];

        $stats['total_products'] = (int) $this->db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $stats['total_quantity'] = (int) $this->db->query('SELECT COALESCE(SUM(quantity), 0) FROM products')->fetchColumn();
        $stats['low_stock'] = (int) $this->db->query('SELECT COUNT(*) FROM products WHERE quantity <= min_stock')->fetchColumn();
        $stats['total_sets'] = (int) $this->db->query('SELECT COUNT(*) FROM sets')->fetchColumn();
        $stats['today_out'] = (int) $this->db->query(
            "SELECT COUNT(*) FROM stock_out WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();
        $stats['today_in'] = (int) $this->db->query(
            "SELECT COUNT(*) FROM stock_in WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();

        return $stats;
    }

    public function getAllProducts(): array
    {
        return $this->db->query(
            'SELECT * FROM products ORDER BY name'
        )->fetchAll();
    }

    public function getProduct(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * ตั้งสถานะการใช้งานอะไหล่ (ใช้งานอยู่ / ยกเลิกใช้งาน)
     *
     * @param int  $productId รหัส products.id
     * @param bool $active    true = ใช้งานอยู่, false = ยกเลิกใช้งาน
     * @return void
     */
    public function setProductActive(int $productId, bool $active): void
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('รหัสอะไหล่ไม่ถูกต้อง');
        }
        $stmt = $this->db->prepare('UPDATE products SET is_active = ? WHERE id = ?');
        $stmt->execute([$active ? 1 : 0, $productId]);
        if ($stmt->rowCount() === 0) {
            $check = $this->getProduct($productId);
            if (!$check) {
                throw new InvalidArgumentException('ไม่พบอะไหล่ที่จะอัปเดต');
            }
        }
    }

    public function getProductStockOutHistory(int $productId, ?int $limit = 20): array
    {
        $sql = "
            SELECT so.id, so.doc_no, so.note, so.issued_by, so.created_at,
                   soi.quantity, so.set_id, s.code AS set_code, s.name AS set_name
            FROM stock_out_items soi
            JOIN stock_out so ON so.id = soi.stock_out_id
            LEFT JOIN sets s ON s.id = so.set_id
            WHERE soi.product_id = ?
            ORDER BY so.created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    /**
     * ประวัติรับเข้า/เบิกออกของอะไหล่หนึ่งรายการ เรียงจากล่าสุด
     *
     * @param int      $productId รหัส products.id
     * @param int|null $limit     จำกัดจำนวนแถว (null = ไม่จำกัด)
     * @return array<int,array<string,mixed>>
     */
    public function getProductMovementHistory(int $productId, ?int $limit = 80): array
    {
        if ($productId <= 0) {
            return [];
        }

        $sql = "
            (SELECT 'in' AS move_type, si.id AS ref_id, si.quantity, si.note,
                    si.received_by AS actor, si.created_at,
                    NULL AS doc_no, NULL AS asset_code, NULL AS stock_out_id,
                    NULL AS set_id, NULL AS set_code, NULL AS set_name
             FROM stock_in si
             WHERE si.product_id = ?)
            UNION ALL
            (SELECT 'out' AS move_type, soi.id AS ref_id, soi.quantity, so.note,
                    so.issued_by AS actor, so.created_at,
                    so.doc_no, so.asset_code, so.id AS stock_out_id,
                    so.set_id, s.code AS set_code, s.name AS set_name
             FROM stock_out_items soi
             JOIN stock_out so ON so.id = soi.stock_out_id
             LEFT JOIN sets s ON s.id = so.set_id
             WHERE soi.product_id = ?)
            ORDER BY created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$productId, $productId]);
        return $stmt->fetchAll();
    }

    public function getLowStockProducts(): array
    {
        return $this->db->query(
            'SELECT * FROM products WHERE quantity <= min_stock ORDER BY quantity ASC'
        )->fetchAll();
    }

    /**
     * รายการอะไหล่ที่มีคงเหลือในคลัง สำหรับสรุปมูลค่าสิ้นปี
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStockValuationRows(): array
    {
        return $this->db->query(
            'SELECT id, code, name, unit, quantity, price FROM products WHERE quantity > 0 ORDER BY name'
        )->fetchAll();
    }

    /**
     * อัปเดตราคาต่อหน่วยของอะไหล่
     *
     * @param int        $productId
     * @param float|null $price
     * @return void
     */
    public function updateProductPrice(int $productId, float $price): void
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('รหัสอะไหล่ไม่ถูกต้อง');
        }
        if ($price < 0) {
            throw new InvalidArgumentException('ราคาต้องไม่ติดลบ');
        }
        $stmt = $this->db->prepare('UPDATE products SET price = ? WHERE id = ?');
        $stmt->execute([round($price, 2), $productId]);
    }

    /**
     * อัปเดตรายละเอียดอะไหล่ (ชื่อ หน่วย ขั้นต่ำ ราคา ผู้จำหน่าย ลิงก์)
     *
     * @param int $productId
     * @param array{name:string,unit:string,min_stock:int,price:float,supplier:?string,purchase_link:?string} $data
     * @return void
     */
    public function updateProductDetails(int $productId, array $data): void
    {
        if ($productId <= 0) {
            throw new InvalidArgumentException('รหัสอะไหล่ไม่ถูกต้อง');
        }
        $name = trim((string) ($data['name'] ?? ''));
        $unit = trim((string) ($data['unit'] ?? ''));
        if ($name === '' || $unit === '') {
            throw new InvalidArgumentException('ชื่อและหน่วยต้องไม่ว่าง');
        }
        $minStock = (int) ($data['min_stock'] ?? 0);
        if ($minStock < 0) {
            throw new InvalidArgumentException('สต็อกขั้นต่ำต้องไม่ติดลบ');
        }
        $price = round((float) ($data['price'] ?? 0), 2);
        if ($price < 0) {
            throw new InvalidArgumentException('ราคาต้องไม่ติดลบ');
        }

        $stmt = $this->db->prepare(
            'UPDATE products SET name = ?, unit = ?, min_stock = ?, price = ?, supplier = ?, purchase_link = ? WHERE id = ?'
        );
        $stmt->execute([
            $name,
            $unit,
            $minStock,
            $price,
            $data['supplier'] ?? null,
            $data['purchase_link'] ?? null,
            $productId,
        ]);
    }

    public function getRecentMovements(int $limit = 10): array
    {
        $sql = "
            (SELECT 'in' AS type, si.id, p.name AS product_name, si.quantity,
                    si.note, si.created_at
             FROM stock_in si JOIN products p ON p.id = si.product_id)
            UNION ALL
            (SELECT 'out' AS type, so.id,
                    COALESCE(
                        s.name,
                        (SELECT p.name FROM stock_out_items soi
                         JOIN products p ON p.id = soi.product_id
                         WHERE soi.stock_out_id = so.id LIMIT 1)
                    ) AS product_name,
                    (SELECT SUM(quantity) FROM stock_out_items WHERE stock_out_id = so.id) AS quantity,
                    so.note, so.created_at
             FROM stock_out so LEFT JOIN sets s ON s.id = so.set_id)
            ORDER BY created_at DESC
            LIMIT ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function stockIn(int $productId, int $quantity, ?string $note = null, ?string $receivedBy = null): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนรับเข้าต้องมากกว่า 0');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO stock_in (product_id, quantity, note, received_by) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$productId, $quantity, $note, $receivedBy]);

            $stmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity + ? WHERE id = ?'
            );
            $stmt->execute([$quantity, $productId]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
        if (function_exists('line_notify_check_low_stock_product')) {
            line_notify_check_low_stock_product($productId);
        }
    }

    public function getAllSets(): array
    {
        return $this->db->query('SELECT * FROM sets ORDER BY name')->fetchAll();
    }

    public function getSetWithItems(int $setId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sets WHERE id = ?');
        $stmt->execute([$setId]);
        $set = $stmt->fetch();
        if (!$set) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT si.*, p.code, p.name, p.unit, p.quantity AS stock_qty
            FROM set_items si
            JOIN products p ON p.id = si.product_id
            WHERE si.set_id = ?
        ");
        $stmt->execute([$setId]);
        $set['items'] = $stmt->fetchAll();

        return $set;
    }

    public function getAllSetsWithItems(): array
    {
        $sets = $this->getAllSets();
        foreach ($sets as &$set) {
            $full = $this->getSetWithItems((int) $set['id']);
            $set['items'] = $full['items'] ?? [];
            $set['can_issue'] = $this->canIssueSet($set['items']);
        }
        return $sets;
    }

    private function canIssueSet(array $items): bool
    {
        foreach ($items as $item) {
            if ((int) $item['stock_qty'] < (int) $item['quantity']) {
                return false;
            }
        }
        return count($items) > 0;
    }

    public function stockOutBySet(int $setId, int $setCount, ?string $note, ?string $issuedBy, ?string $assetCode = null): string
    {
        if ($setCount <= 0) {
            throw new InvalidArgumentException('จำนวนชุดต้องมากกว่า 0');
        }

        $set = $this->getSetWithItems($setId);
        if (!$set || empty($set['items'])) {
            throw new InvalidArgumentException('ไม่พบชุดเบิกหรือชุดว่าง');
        }

        $this->db->beginTransaction();
        try {
            $lockStmt = $this->db->prepare(
                'SELECT id, name, code, unit, quantity FROM products WHERE id = ? FOR UPDATE'
            );
            $deductStmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?'
            );

            foreach ($set['items'] as $item) {
                $needed = (int) $item['quantity'] * $setCount;
                $lockStmt->execute([(int) $item['product_id']]);
                $locked = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$locked || (int) $locked['quantity'] < $needed) {
                    $have = $locked ? (int) $locked['quantity'] : 0;
                    throw new InvalidArgumentException(
                        "อะไหล่ {$item['name']} คงเหลือไม่พอ (ต้องการ {$needed} {$item['unit']}, มี {$have})"
                    );
                }
            }

            $docNo = generateDocNo($this->db);

            $stmt = $this->db->prepare(
                'INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$docNo, $setId, $note, $issuedBy, trim((string) $assetCode) ?: null]);
            $outId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                'INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)'
            );
            $insertedItems = [];

            foreach ($set['items'] as $item) {
                $qty = (int) $item['quantity'] * $setCount;
                $productId = (int) $item['product_id'];
                $itemStmt->execute([$outId, $productId, $qty]);
                $insertedItems[] = [
                    'item_id'      => (int) $this->db->lastInsertId(),
                    'product_id'   => $productId,
                    'product_code' => (string) $item['code'],
                    'quantity'     => $qty,
                ];
                $deductStmt->execute([$qty, $productId, $qty]);
                if ($deductStmt->rowCount() === 0) {
                    throw new InvalidArgumentException(
                        'จำนวนอะไหล่ไม่พอ กรุณาตรวจสอบยอดคงเหลือใหม่'
                    );
                }
            }

            $this->db->commit();
            $this->syncProductionAfterSetOut($outId, $insertedItems, $assetCode, $note, $issuedBy);
            if (function_exists('line_notify_check_low_stock_product')) {
                foreach ($insertedItems as $ins) {
                    line_notify_check_low_stock_product((int)$ins['product_id']);
                }
            }
            return $docNo;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function stockOutItem(int $productId, int $quantity, ?string $note, ?string $issuedBy, ?string $assetCode = null): string
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนเบิกต้องมากกว่า 0');
        }

        $this->db->beginTransaction();
        try {
            $lockStmt = $this->db->prepare(
                'SELECT id, name, code, unit, quantity FROM products WHERE id = ? FOR UPDATE'
            );
            $lockStmt->execute([$productId]);
            $product = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$product) {
                throw new InvalidArgumentException('ไม่พบอะไหล่');
            }
            if ((int) $product['quantity'] < $quantity) {
                throw new InvalidArgumentException(
                    "อะไหล่ {$product['name']} คงเหลือไม่พอ (ต้องการ {$quantity} {$product['unit']}, มี {$product['quantity']})"
                );
            }

            $docNo = generateDocNo($this->db);

            $stmt = $this->db->prepare(
                'INSERT INTO stock_out (doc_no, set_id, note, issued_by, asset_code) VALUES (?, NULL, ?, ?, ?)'
            );
            $stmt->execute([$docNo, $note, $issuedBy, trim((string) $assetCode) ?: null]);
            $outId = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)'
            );
            $stmt->execute([$outId, $productId, $quantity]);
            $itemId = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?'
            );
            $stmt->execute([$quantity, $productId, $quantity]);
            if ($stmt->rowCount() === 0) {
                throw new InvalidArgumentException(
                    'จำนวนอะไหล่ไม่พอ กรุณาตรวจสอบยอดคงเหลือใหม่'
                );
            }

            $this->db->commit();
            $this->syncProductionAfterSingleOut(
                $outId,
                $itemId,
                (string) $product['code'],
                $quantity,
                $assetCode,
                $note,
                $issuedBy
            );
            if (function_exists('line_notify_check_low_stock_product')) {
                line_notify_check_low_stock_product($productId);
            }
            return $docNo;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getSingleItemOutHistory(?int $limit = 20): array
    {
        $sql = "
            SELECT so.id AS stock_out_id, so.doc_no, so.note, so.issued_by, so.created_at,
                   so.asset_code, so.part_movement_id,
                   p.code, p.name, p.unit, soi.quantity, soi.id AS item_id
            FROM stock_out so
            JOIN stock_out_items soi ON soi.stock_out_id = so.id
            JOIN products p ON p.id = soi.product_id
            WHERE so.set_id IS NULL
            ORDER BY so.created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->query($sql)->fetchAll();
    }

    public function getStockOutHistory(?int $limit = null): array
    {
        $sql = "
            SELECT so.*, s.name AS set_name, s.code AS set_code,
                   (SELECT SUM(quantity) FROM stock_out_items WHERE stock_out_id = so.id) AS total_qty,
                   (SELECT COUNT(*) FROM stock_out_items WHERE stock_out_id = so.id) AS item_count,
                   (SELECT p.name FROM stock_out_items soi
                    JOIN products p ON p.id = soi.product_id
                    WHERE soi.stock_out_id = so.id LIMIT 1) AS single_product_name,
                   (SELECT p.code FROM stock_out_items soi
                    JOIN products p ON p.id = soi.product_id
                    WHERE soi.stock_out_id = so.id LIMIT 1) AS single_product_code
            FROM stock_out so
            LEFT JOIN sets s ON s.id = so.set_id
            ORDER BY so.created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->query($sql)->fetchAll();
    }

    public function getStockOutDetail(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT so.*, s.name AS set_name, s.code AS set_code
            FROM stock_out so
            LEFT JOIN sets s ON s.id = so.set_id
            WHERE so.id = ?
        ");
        $stmt->execute([$id]);
        $out = $stmt->fetch();
        if (!$out) {
            return null;
        }

        $stmt = $this->db->prepare("
            SELECT soi.*, p.code, p.name, p.unit
            FROM stock_out_items soi
            JOIN products p ON p.id = soi.product_id
            WHERE soi.stock_out_id = ?
        ");
        $stmt->execute([$id]);
        $out['items'] = $stmt->fetchAll();

        return $out;
    }

    public function getStockInHistory(?int $limit = null): array
    {
        $sql = "
            SELECT si.*, p.code, p.name, p.unit
            FROM stock_in si
            JOIN products p ON p.id = si.product_id
            ORDER BY si.created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->query($sql)->fetchAll();
    }

    /**
     * ดึงแถว stock_in เดียวสำหรับฟอร์มแก้ไข
     *
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function getStockInRow(int $id): ?array
    {
        $st = $this->db->prepare(
            'SELECT si.*, p.name, p.unit FROM stock_in si JOIN products p ON p.id = si.product_id WHERE si.id = ?'
        );
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * ประวัติเบิก Set (set_id IS NOT NULL)
     *
     * @param int|null $limit
     * @return array<int,array<string,mixed>>
     */
    public function getSetOutHistory(?int $limit = 20): array
    {
        $sql = "
            SELECT so.id AS stock_out_id, so.*, s.name AS set_name, s.code AS set_code,
                   (SELECT SUM(quantity) FROM stock_out_items WHERE stock_out_id = so.id) AS total_qty
            FROM stock_out so
            JOIN sets s ON s.id = so.set_id
            ORDER BY so.created_at DESC
        ";
        if ($limit) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->query($sql)->fetchAll();
    }

    public function stockOutByWebhook(string $productCode, int $quantity, string $purpose): string
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนเบิกต้องมากกว่า 0');
        }

        // Find product by code
        $stmt = $this->db->prepare('SELECT * FROM products WHERE code = ?');
        $stmt->execute([$productCode]);
        $product = $stmt->fetch();

        if (!$product) {
            throw new InvalidArgumentException("ไม่พบอะไหล่ รหัส: {$productCode}");
        }

        if ((int) $product['quantity'] < $quantity) {
            throw new InvalidArgumentException(
                "อะไหล่ {$product['name']} คงเหลือไม่พอ (ต้องการ {$quantity} {$product['unit']}, มี {$product['quantity']})"
            );
        }

        $docNo = 'WEBHOOK-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        $this->db->beginTransaction();
        try {
            // Create stock_out record
            $stmt = $this->db->prepare(
                'INSERT INTO stock_out (doc_no, set_id, note, issued_by) VALUES (?, NULL, ?, ?)'
            );
            $stmt->execute([$docNo, $purpose, 'webhook']);
            $outId = (int) $this->db->lastInsertId();

            // Create stock_out_item
            $stmt = $this->db->prepare(
                'INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)'
            );
            $stmt->execute([$outId, $product['id'], $quantity]);

            // Update product quantity
            $stmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity - ? WHERE id = ?'
            );
            $stmt->execute([$quantity, $product['id']]);

            $this->db->commit();
            return $docNo;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * แก้ไขรายการรับเข้า
     *
     * @param int         $id
     * @param int         $quantity
     * @param string|null $note
     * @return void
     */
    public function updateStockIn(int $id, int $quantity, ?string $note = null): void
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนรับเข้าต้องมากกว่า 0');
        }
        $st = $this->db->prepare('SELECT * FROM stock_in WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            throw new InvalidArgumentException('ไม่พบรายการรับเข้า');
        }
        $diff = $quantity - (int) $old['quantity'];
        if ($diff < 0) {
            $product = $this->getProduct((int) $old['product_id']);
            $need = abs($diff);
            if (!$product || (int) $product['quantity'] < $need) {
                $have = $product ? (int) $product['quantity'] : 0;
                throw new InvalidArgumentException(
                    "ไม่สามารถแก้ไขได้ เพราะอะไหล่ถูกเบิกออกไปแล้ว (คงเหลือ {$have} ชิ้น ต้องการลด {$need} ชิ้น)"
                );
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE stock_in SET quantity = ?, note = ? WHERE id = ?')
                ->execute([$quantity, $note, $id]);
            if ($diff !== 0) {
                $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                    ->execute([$diff, (int) $old['product_id']]);
            }
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * ลบรายการรับเข้า
     *
     * @param int $id
     * @return void
     */
    public function deleteStockIn(int $id): void
    {
        $st = $this->db->prepare('SELECT * FROM stock_in WHERE id = ?');
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) {
            throw new InvalidArgumentException('ไม่พบรายการรับเข้า');
        }

        $delQty = (int) $old['quantity'];
        $product = $this->getProduct((int) $old['product_id']);
        if (!$product || (int) $product['quantity'] < $delQty) {
            $have = $product ? (int) $product['quantity'] : 0;
            throw new InvalidArgumentException(
                "ไม่สามารถลบได้ เพราะอะไหล่ถูกเบิกออกไปแล้ว (คงเหลือ {$have} ชิ้น ต้องการลด {$delQty} ชิ้น)"
            );
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')
                ->execute([$delQty, (int) $old['product_id']]);
            $this->db->prepare('DELETE FROM stock_in WHERE id = ?')->execute([$id]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * แก้ไขรายการเบิก (รายชิ้น — set_id IS NULL)
     *
     * @param int         $stockOutId
     * @param int         $quantity
     * @param string|null $note
     * @param string|null $assetCode S/N สินค้า
     * @param string|null $issuedBy
     * @return void
     */
    public function updateStockOutSingle(
        int $stockOutId,
        int $quantity,
        ?string $note,
        ?string $assetCode,
        ?string $issuedBy
    ): void {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนเบิกต้องมากกว่า 0');
        }
        $detail = $this->getStockOutDetail($stockOutId);
        if (!$detail || !empty($detail['set_id']) || count($detail['items']) !== 1) {
            throw new InvalidArgumentException('แก้ไขได้เฉพาะรายการเบิกรายชิ้น');
        }
        $item = $detail['items'][0];
        $oldQty = (int) $item['quantity'];
        $diff = $quantity - $oldQty;
        $productId = (int) $item['product_id'];

        if ($diff > 0) {
            $product = $this->getProduct($productId);
            if (!$product || (int) $product['quantity'] < $diff) {
                throw new InvalidArgumentException('สต็อกไม่พอสำหรับเพิ่มจำนวนเบิก');
            }
        }

        $movementId = (int) ($detail['part_movement_id'] ?? 0);

        $this->db->beginTransaction();
        try {
            if ($diff !== 0) {
                $this->db->prepare('UPDATE stock_out_items SET quantity = ? WHERE id = ?')
                    ->execute([$quantity, (int) $item['id']]);
                $this->db->prepare('UPDATE products SET quantity = quantity - ? WHERE id = ?')
                    ->execute([$diff, $productId]);
            }
            $this->db->prepare('UPDATE stock_out SET note = ?, asset_code = ? WHERE id = ?')
                ->execute([$note, trim((string) $assetCode) ?: null, $stockOutId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        if ($movementId > 0) {
            production_update_out_movement(
                $movementId,
                (float) $quantity,
                $assetCode,
                $note,
                $issuedBy ?? 'parts'
            );
        }
    }

    /**
     * แก้ไขหมายเหตุ/S/N ของเบิก Set (ไม่เปลี่ยนจำนวนชิ้น)
     *
     * @param int         $stockOutId
     * @param string|null $note
     * @param string|null $assetCode
     * @param string|null $issuedBy
     * @return void
     */
    public function updateStockOutMeta(int $stockOutId, ?string $note, ?string $assetCode, ?string $issuedBy): void
    {
        $detail = $this->getStockOutDetail($stockOutId);
        if (!$detail) {
            throw new InvalidArgumentException('ไม่พบรายการเบิก');
        }
        $this->db->prepare('UPDATE stock_out SET note = ?, asset_code = ? WHERE id = ?')
            ->execute([$note, trim((string) $assetCode) ?: null, $stockOutId]);

        foreach ($detail['items'] as $item) {
            $mid = (int) ($item['part_movement_id'] ?? 0);
            if ($mid > 0) {
                production_update_out_movement(
                    $mid,
                    (float) $item['quantity'],
                    $assetCode,
                    $note,
                    $issuedBy ?? 'parts'
                );
            }
        }
    }

    /**
     * ลบรายการเบิกและ sync production ถ้ามีการเชื่อม
     *
     * @param int $stockOutId
     * @return void
     */
    public function deleteStockOut(int $stockOutId): void
    {
        $detail = $this->getStockOutDetail($stockOutId);
        if (!$detail) {
            throw new InvalidArgumentException('ไม่พบรายการเบิก');
        }

        $movementIds = [];
        if (!empty($detail['part_movement_id'])) {
            $movementIds[] = (int) $detail['part_movement_id'];
        }
        foreach ($detail['items'] as $item) {
            if (!empty($item['part_movement_id'])) {
                $movementIds[] = (int) $item['part_movement_id'];
            }
        }
        $movementIds = array_values(array_unique(array_filter($movementIds)));

        $this->db->beginTransaction();
        try {
            foreach ($detail['items'] as $item) {
                $this->db->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?')
                    ->execute([(int) $item['quantity'], (int) $item['product_id']]);
            }
            $this->db->prepare('DELETE FROM stock_out_items WHERE stock_out_id = ?')->execute([$stockOutId]);
            $this->db->prepare('DELETE FROM stock_out WHERE id = ?')->execute([$stockOutId]);
            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }

        foreach ($movementIds as $mid) {
            production_delete_out_movement($mid);
        }
    }

    /**
     * sync production หลังเบิกรายชิ้น
     *
     * @param int         $stockOutId
     * @param int         $itemId
     * @param string      $productCode
     * @param int         $qty
     * @param string|null $assetCode
     * @param string|null $note
     * @param string|null $issuedBy
     * @return void
     */
    private function syncProductionAfterSingleOut(
        int $stockOutId,
        int $itemId,
        string $productCode,
        int $qty,
        ?string $assetCode,
        ?string $note,
        ?string $issuedBy
    ): void {
        $partId = production_part_id_by_product_code($productCode);
        if ($partId === null) {
            return;
        }
        try {
            $mode = $note ?: 'เบิกใช้';
            $mid = production_create_out_movement(
                $partId,
                (float) $qty,
                $assetCode,
                $mode,
                $issuedBy ?? 'parts',
                $note,
                $stockOutId
            );
            production_link_stock_out($this->db, $stockOutId, $mid, $assetCode);
        } catch (Throwable $e) {
            error_log('[syncProductionAfterSingleOut] ' . $e->getMessage());
        }
    }

    /**
     * sync production หลังเบิก Set — สร้าง movement ต่อชิ้น
     *
     * @param int                            $stockOutId
     * @param array<int,array<string,mixed>> $items
     * @param string|null                    $assetCode
     * @param string|null                    $note
     * @param string|null                    $issuedBy
     * @return void
     */
    private function syncProductionAfterSetOut(
        int $stockOutId,
        array $items,
        ?string $assetCode,
        ?string $note,
        ?string $issuedBy
    ): void {
        $mode = $note ?: 'เบิกใช้';
        foreach ($items as $row) {
            $partId = production_part_id_by_product_code((string) $row['product_code']);
            if ($partId === null) {
                continue;
            }
            try {
                $mid = production_create_out_movement(
                    $partId,
                    (float) $row['quantity'],
                    $assetCode,
                    $mode,
                    $issuedBy ?? 'parts',
                    $note,
                    $stockOutId
                );
                production_link_stock_out_item($this->db, (int) $row['item_id'], $mid);
                production_link_stock_out($this->db, $stockOutId, $mid, $assetCode);
            } catch (Throwable $e) {
                error_log('[syncProductionAfterSetOut] ' . $e->getMessage());
            }
        }
    }

    /**
     * ประวัติเบิกขยายทีละ S/N (range → รหัสจากทะเบียนเครื่อง)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStockOutHistoryExpandedBySn(): array
    {
        if (!function_exists('expand_asset_codes')) {
            require_once __DIR__ . '/production_sync.php';
        }

        $expanded = [];
        foreach ($this->getStockOutHistory() as $row) {
            $rawSn = trim((string) ($row['asset_code'] ?? ''));
            $sns = expand_asset_codes($rawSn);
            if ($sns === ['']) {
                $sns = [''];
            }
            $n = count($sns);
            $totalQty = (int) ($row['total_qty'] ?? 0);
            $displayQty = $n > 0 ? (int) round($totalQty / $n) : $totalQty;

            foreach ($sns as $sn) {
                $copy = $row;
                $copy['asset_code'] = $sn;
                $copy['asset_code_raw'] = $rawSn;
                $copy['display_qty'] = $displayQty;
                $expanded[] = $copy;
            }
        }
        return $expanded;
    }

    /**
     * จัดกลุ่มประวัติเบิกตาม S/N เดี่ยว (หลังขยาย range แล้ว)
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStockOutHistoryGroupedBySn(): array
    {
        $rows = $this->getStockOutHistoryExpandedBySn();
        $groups = [];
        $order = [];

        foreach ($rows as $row) {
            $sn = trim((string) ($row['asset_code'] ?? ''));
            if ($sn === '') {
                $key = 'doc:' . (int) $row['id'];
            } else {
                $key = 'sn:' . mb_strtoupper($sn);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'type' => $sn === '' ? 'doc' : 'sn',
                    'sn'   => $sn,
                    'items' => [],
                ];
                $order[] = $key;
            }
            $groups[$key]['items'][] = $row;
        }

        $result = [];
        foreach ($order as $key) {
            $g = $groups[$key];
            $items = $g['items'];
            $docIds = [];
            $totalQty = 0;
            foreach ($items as $it) {
                $docIds[(int) $it['id']] = true;
                $totalQty += (int) ($it['display_qty'] ?? 0);
            }
            $result[] = [
                'type'      => $g['type'],
                'sn'        => $g['sn'],
                'items'     => $items,
                'count'     => count($items),
                'doc_count' => count($docIds),
                'total_qty' => $totalQty,
                'latest'    => $items[0],
            ];
        }
        return $result;
    }

    /**
     * @deprecated ใช้ getStockOutHistoryGroupedBySn() แทน
     */
    public function getStockOutHistoryGrouped(): array
    {
        return $this->getStockOutHistoryGroupedBySn();
    }

    /**
     * ประวัติเบิกจัดกลุ่มตาม S/N — รายการที่ไม่มี S/N แสดงแยกทีละแถว
     *
     * @return array<int,array<string,mixed>>
     */
    public function getStockOutHistoryGroupedLegacy(): array
    {
        $rows = $this->getStockOutHistory();
        $groups = [];
        $order = [];

        foreach ($rows as $row) {
            $sn = trim((string) ($row['asset_code'] ?? ''));
            if ($sn === '') {
                $key = 'single:' . $row['id'];
                $groups[$key] = ['type' => 'single', 'sn' => '', 'items' => [$row]];
                $order[] = $key;
                continue;
            }
            $key = 'sn:' . mb_strtoupper($sn);
            if (!isset($groups[$key])) {
                $groups[$key] = ['type' => 'sn', 'sn' => $sn, 'items' => []];
                $order[] = $key;
            }
            $groups[$key]['items'][] = $row;
        }

        $result = [];
        foreach ($order as $key) {
            $g = $groups[$key];
            $items = $g['items'];
            $totalQty = 0;
            foreach ($items as $it) {
                $totalQty += (int) ($it['total_qty'] ?? 0);
            }
            $result[] = [
                'type'      => $g['type'],
                'sn'        => $g['sn'],
                'items'     => $items,
                'count'     => count($items),
                'total_qty' => $totalQty,
                'latest'    => $items[0],
            ];
        }
        return $result;
    }

    /**
     * รายการเบิกทั้งหมดของ S/N พร้อมรายละเอียดอะไหล่ (สำหรับ popup 🧺)
     *
     * @param string $assetCode
     * @return array<int,array<string,mixed>>
     */
    public function getBasketDetailsByAssetCode(string $assetCode): array
    {
        $assetCode = trim($assetCode);
        if ($assetCode === '') {
            return [];
        }

        if (!function_exists('expand_asset_codes')) {
            require_once __DIR__ . '/production_sync.php';
        }

        $seenIds = [];
        $outs = [];

        $st = $this->db->prepare('
            SELECT so.*, s.name AS set_name, s.code AS set_code
            FROM stock_out so
            LEFT JOIN sets s ON s.id = so.set_id
            WHERE TRIM(so.asset_code) = ?
            ORDER BY so.created_at DESC
        ');
        $st->execute([$assetCode]);
        foreach ($st->fetchAll() as $row) {
            $seenIds[(int) $row['id']] = true;
            $outs[] = $row;
        }

        $rangeSt = $this->db->query("
            SELECT so.*, s.name AS set_name, s.code AS set_code
            FROM stock_out so
            LEFT JOIN sets s ON s.id = so.set_id
            WHERE so.asset_code LIKE '% - %'
            ORDER BY so.created_at DESC
        ");
        while ($row = $rangeSt->fetch()) {
            $id = (int) $row['id'];
            if (isset($seenIds[$id])) {
                continue;
            }
            $codes = expand_asset_codes(trim((string) ($row['asset_code'] ?? '')));
            if (!in_array($assetCode, $codes, true)) {
                continue;
            }
            $seenIds[$id] = true;
            $outs[] = $row;
        }

        usort($outs, function ($a, $b) {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        $itemSt = $this->db->prepare('
            SELECT soi.*, p.code, p.name, p.unit
            FROM stock_out_items soi
            JOIN products p ON p.id = soi.product_id
            WHERE soi.stock_out_id = ?
        ');

        foreach ($outs as &$out) {
            $itemSt->execute([(int) $out['id']]);
            $out['items'] = $itemSt->fetchAll();
        }
        unset($out);

        return $outs;
    }
}
