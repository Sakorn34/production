<?php
/**
 * pages/stock-out-item.php — ย้ายไปรวมที่อื่นแล้ว (2026-08-05)
 *
 * ฟอร์มเบิกรายชิ้น → modal ที่ products.php
 * ประวัติเบิกรายชิ้น → history.php?tab=move&kind=item
 *
 * เก็บไว้เป็น redirect ให้ bookmark เดิมใช้ได้ (ดูเหตุผลใน stock-in.php)
 */

require_once __DIR__ . '/../includes/parts_bootstrap.php';

$editOut = isset($_GET['edit_out']) ? (int) $_GET['edit_out'] : 0;
redirect(url('/pages/history.php?tab=move&kind=item' . ($editOut > 0 ? '&edit_out=' . $editOut : '')));
