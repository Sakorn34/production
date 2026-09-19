<?php
/**
 * line_webhook.php — รับ event จาก LINE เพื่อผูก LINE ของพนักงานเข้ากับทะเบียนคน
 *
 * เป็น endpoint สาธารณะ (LINE เรียกเข้ามา ไม่มี session) จึง **ต้องตรวจลายเซ็นก่อนเสมอ**
 * ไม่งั้นใครก็ยิงมาผูกบัญชีมั่วได้
 *
 * วิธีใช้: หน้า work_report.php กด "ออกรหัส" ให้คนนั้น → เจ้าตัวทักรหัสไปหาบอต →
 * ไฟล์นี้เก็บ userId ที่มากับข้อความลงทะเบียนคน
 *
 * คนที่ผูกแล้วใช้ถาม-ตอบได้ (ริชเมนู · ค้นหา · ประวัติเครื่อง · สต็อก) — ดู includes/line_bot.php
 *
 * ตั้ง Webhook URL ที่ LINE Developers Console เป็น:
 *   https://<โดเมน>/production/finishgoogs_ma_update/line_webhook.php
 */

require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/work_people.php';
require_once __DIR__ . '/includes/work_summary_send.php';   // WORK_SUMMARY_LINE_BOT
require_once dirname(__DIR__) . '/shared/line_notify_core.php';
require_once dirname(__DIR__) . '/shared/line_flex_templates.php';
require_once __DIR__ . '/includes/line_bot.php';

// LINE ถือว่า response ที่ไม่ใช่ 2xx = ส่งไม่สำเร็จ แล้วจะยิงซ้ำรัว ๆ
// จึงตอบ 200 เกือบทุกกรณี ยกเว้นลายเซ็นไม่ผ่าน (อันนั้นต้องปฏิเสธจริง)
header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$sig = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? (string) $_SERVER['HTTP_X_LINE_SIGNATURE'] : '';

// ผูกกับ bot สำรองเท่านั้น — bot ตัวจริงส่งเข้ากลุ่มอย่างเดียวและไม่ได้เปิด webhook
// userId ของ LINE ผูกกับ channel ดังนั้นตัวที่ตรวจลายเซ็น ตัวที่ตอบ และตัวที่ส่งสรุป
// ต้องเป็น bot เดียวกันทั้งหมด ไม่งั้นได้ userId ที่ส่งข้อความไม่ถึง
$cfg = line_notify_config();
$secret = trim((string) ($cfg['test_channel_secret'] ?? ''));

if ($secret === '') {
    error_log('[line_webhook] ยังไม่ได้ตั้ง test_channel_secret (bot สำรอง) — ปฏิเสธทุก request');
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'no secret'], JSON_UNESCAPED_UNICODE);
    exit;
}
// hash_equals กัน timing attack — เทียบสตริงตรง ๆ บอกความยาว/ตำแหน่งที่ต่างได้
$expect = base64_encode(hash_hmac('sha256', $raw, $secret, true));
if ($sig === '' || !hash_equals($expect, $sig)) {
    error_log('[line_webhook] ลายเซ็นไม่ถูกต้อง');
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'bad signature'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode($raw, true);
$events = (is_array($body) && isset($body['events']) && is_array($body['events'])) ? $body['events'] : [];

$replies = [];
foreach ($events as $ev) {
    $type = (string) ($ev['type'] ?? '');
    $userId = (string) ($ev['source']['userId'] ?? '');
    $replyToken = (string) ($ev['replyToken'] ?? '');
    if ($userId === '') {
        continue;
    }
    // ตอบเฉพาะแชทส่วนตัว — bot สำรองอยู่ในกลุ่มด้วย (ห้องทดสอบ) และ reply token ในกลุ่ม
    // จะตอบเข้ากลุ่ม คนอื่นในกลุ่มเห็นผลค้นหา (มีชื่อลูกค้า/สัญญาเช่า) · ในกลุ่มเงียบไปเลย
    // ไม่ตอบแม้แต่คำแนะนำ ไม่งั้นทุกข้อความที่คุยกันในกลุ่มจะโดนบอตตอบ
    if ((string) ($ev['source']['type'] ?? '') !== 'user') {
        continue;
    }

    $person = line_bot_person($userId, WORK_SUMMARY_LINE_BOT);
    if ($type === 'follow') {
        $replies[] = [$replyToken, $person
            ? 'ยินดีต้อนรับกลับครับ คุณ ' . (string) $person['display_name'] . "\n\n" . line_bot_help_text()
            : 'ยินดีต้อนรับ 👋' . "\n"
            . 'ให้ผู้ดูแลออกรหัสผูกบัญชีจากหน้า "สรุปงานรายคน" แล้วพิมพ์รหัส 8 ตัวนั้นมาที่นี่ '
            . 'เพื่อรับสรุปงานของคุณทุกเดือน และใช้ค้นหาสินค้า/ประวัติเครื่องผ่านไลน์นี้ครับ'];
        continue;
    }
    $isText = $type === 'message' && (string) ($ev['message']['type'] ?? '') === 'text';
    $text = $isText ? trim((string) ($ev['message']['text'] ?? '')) : '';

    // รหัสผูกบัญชีที่ยังมีอยู่จริงมาก่อนเสมอ — คนที่ผูกแล้วก็ผูกใหม่ได้ (เปลี่ยนเครื่อง/บัญชี)
    $code = $isText ? line_bot_link_code_in($text) : '';
    if ($code === '' && $person) {
        // ถาม-ตอบ: เฉพาะคนที่ผูกไลน์แล้ว — คำตอบไปทาง reply token = เข้าแชทของคนที่ถามคนเดียว
        $msgs = line_bot_handle($ev);
        if ($msgs) {
            $replies[] = [$replyToken, $msgs];
        }
        continue;
    }
    if ($code === '') {
        if ($type === 'postback' || $isText) {
            // คนที่ยังไม่ผูก: พิมพ์รูปแบบรหัส 8 ตัวที่ไม่มีในระบบ = รหัสผิด/หมดอายุ · อย่างอื่น = บอกวิธีผูก
            $replies[] = [$replyToken, preg_match('/^[A-Za-z0-9]{8}$/', $text)
                ? '❌ ไม่พบรหัสนี้' . "\n" . 'ลองให้ผู้ดูแลออกรหัสใหม่อีกครั้งครับ'
                : 'ยังไม่ได้ผูกบัญชีครับ' . "\n"
                . 'ให้ผู้ดูแลออกรหัสผูกบัญชีจากหน้า "สรุปงานรายคน" แล้วพิมพ์รหัส 8 ตัวนั้นมาที่นี่ก่อน จึงจะค้นหาข้อมูลได้'];
        }
        continue;
    }
    $res = work_people_consume_link_code($code, $userId, line_webhook_display_name($userId), WORK_SUMMARY_LINE_BOT);
    if (!empty($res['ok'])) {
        $replies[] = [$replyToken, 'ผูกบัญชีเรียบร้อย ✅' . "\n"
            . 'คุณ ' . (string) ($res['person']['display_name'] ?? '') . ' จะได้รับสรุปงานทุกวันที่ 21 ครับ' . "\n\n"
            . line_bot_help_text()];
    } else {
        $replies[] = [$replyToken, '❌ ' . (string) ($res['error'] ?? 'ผูกบัญชีไม่สำเร็จ') . "\n"
            . 'ลองให้ผู้ดูแลออกรหัสใหม่อีกครั้งครับ'];
    }
}

foreach ($replies as $r) {
    if (is_array($r[1])) {
        line_bot_reply($r[0], $r[1], WORK_SUMMARY_LINE_BOT);
    } else {
        line_bot_reply($r[0], [line_bot_text($r[1], false)], WORK_SUMMARY_LINE_BOT);
    }
}

echo json_encode(['ok' => true, 'handled' => count($events)], JSON_UNESCAPED_UNICODE);

/**
 * ดึงชื่อโปรไฟล์ LINE มาเก็บไว้ให้ผู้ดูแลเห็นว่าผูกกับไลน์ไหน — ไม่ได้ก็ไม่เป็นไร
 *
 * @param  string $userId
 * @return string
 */
function line_webhook_display_name(string $userId): string
{
    $token = line_notify_bot_token(WORK_SUMMARY_LINE_BOT);
    if ($token === '' || $userId === '') {
        return '';
    }
    $ch = curl_init('https://api.line.me/v2/bot/profile/' . rawurlencode($userId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    if (function_exists('line_notify_apply_curl_ssl')) {
        line_notify_apply_curl_ssl($ch);
    }
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !is_string($out)) {
        return '';
    }
    $j = json_decode($out, true);
    return is_array($j) ? (string) ($j['displayName'] ?? '') : '';
}
