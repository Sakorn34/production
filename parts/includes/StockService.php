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

    public function getLowStockProducts(): array
    {
        return $this->db->query(
            'SELECT * FROM products WHERE quantity <= min_stock ORDER BY quantity ASC'
        )->fetchAll();
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

    public function stockOutBySet(int $setId, int $setCount, ?string $note, ?string $issuedBy): string
    {
        if ($setCount <= 0) {
            throw new InvalidArgumentException('จำนวนชุดต้องมากกว่า 0');
        }

        $set = $this->getSetWithItems($setId);
        if (!$set || empty($set['items'])) {
            throw new InvalidArgumentException('ไม่พบชุดเบิกหรือชุดว่าง');
        }

        foreach ($set['items'] as $item) {
            $needed = (int) $item['quantity'] * $setCount;
            if ((int) $item['stock_qty'] < $needed) {
                throw new InvalidArgumentException(
                    "อะไหล่ {$item['name']} คงเหลือไม่พอ (ต้องการ {$needed} {$item['unit']}, มี {$item['stock_qty']})"
                );
            }
        }

        $docNo = generateDocNo($this->db);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO stock_out (doc_no, set_id, note, issued_by) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$docNo, $setId, $note, $issuedBy]);
            $outId = (int) $this->db->lastInsertId();

            $itemStmt = $this->db->prepare(
                'INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)'
            );
            $updateStmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity - ? WHERE id = ?'
            );

            foreach ($set['items'] as $item) {
                $qty = (int) $item['quantity'] * $setCount;
                $itemStmt->execute([$outId, $item['product_id'], $qty]);
                $updateStmt->execute([$qty, $item['product_id']]);
            }

            $this->db->commit();
            return $docNo;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function stockOutItem(int $productId, int $quantity, ?string $note, ?string $issuedBy): string
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('จำนวนเบิกต้องมากกว่า 0');
        }

        $product = $this->getProduct($productId);
        if (!$product) {
            throw new InvalidArgumentException('ไม่พบอะไหล่');
        }

        if ((int) $product['quantity'] < $quantity) {
            throw new InvalidArgumentException(
                "อะไหล่ {$product['name']} คงเหลือไม่พอ (ต้องการ {$quantity} {$product['unit']}, มี {$product['quantity']})"
            );
        }

        $docNo = generateDocNo($this->db);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO stock_out (doc_no, set_id, note, issued_by) VALUES (?, NULL, ?, ?)'
            );
            $stmt->execute([$docNo, $note, $issuedBy]);
            $outId = (int) $this->db->lastInsertId();

            $stmt = $this->db->prepare(
                'INSERT INTO stock_out_items (stock_out_id, product_id, quantity) VALUES (?, ?, ?)'
            );
            $stmt->execute([$outId, $productId, $quantity]);

            $stmt = $this->db->prepare(
                'UPDATE products SET quantity = quantity - ? WHERE id = ?'
            );
            $stmt->execute([$quantity, $productId]);

            $this->db->commit();
            return $docNo;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getSingleItemOutHistory(?int $limit = 20): array
    {
        $sql = "
            SELECT so.doc_no, so.note, so.issued_by, so.created_at,
                   p.code, p.name, p.unit, soi.quantity
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
}
