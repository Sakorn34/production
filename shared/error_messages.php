<?php
/**
 * แปล exception เป็นข้อความไทยที่ผู้ใช้อ่านเข้าใจ ไม่เผยรายละเอียด SQL/โครงสร้างฐานข้อมูลภายใน
 *
 * exception ที่โยนมาพร้อมข้อความไทยที่เขียนไว้ให้ผู้ใช้อ่านอยู่แล้ว (RuntimeException / InvalidArgumentException
 * เช่น "จำนวนอะไหล่ไม่พอ...") จะคืนข้อความนั้นตรงๆ ส่วน PDOException/mysqli หรือ error ที่ไม่ได้ตั้งใจอื่นๆ
 * จะคืนข้อความกลางแทน — รายละเอียดจริงถูก error_log ไว้เสมอสำหรับผู้ดูแลระบบ
 */
function safe_exception_message(Throwable $e): string
{
    error_log('[' . get_class($e) . '] ' . $e->getMessage());

    if ($e instanceof RuntimeException || $e instanceof InvalidArgumentException) {
        return $e->getMessage();
    }

    if ($e instanceof PDOException) {
        $info = $e->errorInfo ?? null;
        $sqlstate = is_array($info) ? ($info[0] ?? '') : '';
        if ($sqlstate === '23000') {
            return 'ข้อมูลนี้ซ้ำหรือขัดแย้งกับข้อมูลที่มีอยู่ในระบบ กรุณาตรวจสอบแล้วลองใหม่';
        }
    }

    return 'เกิดข้อผิดพลาดของระบบ กรุณาลองใหม่ หรือแจ้งผู้ดูแลระบบถ้ายังไม่หาย';
}
