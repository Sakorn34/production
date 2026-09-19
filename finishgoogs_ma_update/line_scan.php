<?php
/**
 * line_scan.php — หน้าสแกน S/N ที่เปิดในไลน์ (LIFF) จากปุ่ม "สแกน S/N" ของไลน์สำรอง
 *
 * ตัวสแกน QR ในไลน์เองไม่ส่งผลกลับให้บอต (และอ่านบาร์โค้ดเส้นไม่ได้) จึงเปิดหน้านี้แทน:
 * สแกนด้วยตัวอ่านชุดเดียวกับทั้งระบบ (assets/scan-field.js) → liff.sendMessages() ส่ง S/N
 * เข้าแชทในนามคนสแกน → webhook ตอบประวัติเครื่องกลับเข้าแชทเดียวกัน
 *
 * ไม่ต้อง login — หน้านี้ไม่แสดงข้อมูลอะไรเลย แค่ส่งข้อความเข้าแชท คนที่ยังไม่ผูกไลน์ส่งมา
 * บอตก็ไม่ตอบข้อมูลให้อยู่ดี (ตรวจที่ line_webhook.php)
 *
 * ตั้งค่า: LINE Developers → channel แบบ LINE Login → LIFF → Endpoint URL = URL ของหน้านี้ ·
 * Size = Full · Scope = chat_message.write · แล้วเอา LIFF ID ไปใส่ที่หน้าตั้งค่า LINE
 */
require __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/shared/line_notify_core.php';

$liffId = trim((string) (line_notify_config()['liff_scan_id'] ?? ''));
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>สแกน S/N</title>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/style.css') ?>">
<style>
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f4f1fb; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
.ls-card { width: min(360px, calc(100vw - 32px)); background: #fff; border-radius: 16px; padding: 24px 20px; text-align: center; box-shadow: 0 2px 12px rgba(40, 20, 80, .08); }
.ls-card h1 { font-size: 20px; margin: 0 0 6px; }
.ls-msg { color: #555; font-size: 14px; line-height: 1.6; margin: 0 0 16px; }
.ls-code { font-family: ui-monospace, monospace; font-size: 18px; font-weight: 700; word-break: break-all; margin: 0 0 16px; }
.ls-btns { display: flex; flex-direction: column; gap: 10px; }
.ls-btns button { font-size: 16px; padding: 12px; border-radius: 10px; border: 0; cursor: pointer; }
.ls-main { background: #06c755; color: #fff; }
.ls-sub { background: #eee; color: #333; }
[hidden] { display: none !important; }
</style>
</head>
<body data-base="<?= h(BASE_URL) ?>">
<div class="ls-card">
  <h1>สแกน S/N</h1>
  <p class="ls-msg" id="ls-msg">กำลังเปิด…</p>
  <p class="ls-code" id="ls-code" hidden></p>
  <div class="ls-btns">
    <button type="button" class="ls-main" id="ls-scan" hidden>สแกนอีกครั้ง</button>
    <button type="button" class="ls-sub" id="ls-close" hidden>ปิด</button>
  </div>
</div>
<script>window.FG_BASE_URL = <?= json_encode(BASE_URL) ?>;</script>
<script src="https://static.line-scdn.net/liff/edge/2/sdk.js" charset="utf-8"></script>
<script src="<?= BASE_URL ?>/assets/fast-scan.js?v=<?= (int) @filemtime(__DIR__ . '/assets/fast-scan.js') ?>"></script>
<script src="<?= BASE_URL ?>/assets/scan-field.js?v=<?= (int) @filemtime(__DIR__ . '/assets/scan-field.js') ?>"></script>
<script>
(function () {
  'use strict';
  var LIFF_ID = <?= json_encode($liffId) ?>;
  var msg = document.getElementById('ls-msg');
  var codeEl = document.getElementById('ls-code');
  var btnScan = document.getElementById('ls-scan');
  var btnClose = document.getElementById('ls-close');
  var ready = false;
  var got = false;

  function say(t) { msg.textContent = t; }
  function showButtons() { btnScan.hidden = false; btnClose.hidden = !ready || !liff.isInClient(); }

  function send(code) {
    codeEl.textContent = code;
    codeEl.hidden = false;
    say('กำลังส่งเข้าแชท…');
    liff.sendMessages([{ type: 'text', text: code }]).then(function () {
      say('ส่งแล้ว — ดูคำตอบในแชทได้เลย');
      setTimeout(function () { liff.closeWindow(); }, 400);
    }).catch(function (e) {
      // เปิดจากนอกห้องแชทของบอต (เช่น เบราว์เซอร์ภายนอก) ส่งข้อความแทนไม่ได้
      say('ส่งเข้าแชทไม่ได้ — คัดลอกรหัสนี้ไปพิมพ์ในแชทแทน (' + (e && e.message ? e.message : 'error') + ')');
      showButtons();
    });
  }

  function scan() {
    got = false;
    codeEl.hidden = true;
    btnScan.hidden = true;
    btnClose.hidden = true;
    say('ส่องกล้องไปที่บาร์โค้ดหรือ QR บนเครื่อง');
    FgScan.open({
      title: 'สแกน S/N เครื่อง',
      continuous: false,
      onCode: function (code) { got = true; send(code); },
      // ตัวสแกนเรียก onClose ก่อน onCode — รอดูก่อนว่าปิดเพราะได้รหัสหรือผู้ใช้กดปิดเอง
      onClose: function () {
        setTimeout(function () {
          if (!got) { say('ยังไม่ได้สแกน'); showButtons(); }
        }, 400);
      }
    });
  }

  btnScan.addEventListener('click', scan);
  btnClose.addEventListener('click', function () { liff.closeWindow(); });

  if (!LIFF_ID) { say('ยังไม่ได้ตั้งค่า LIFF ID ในหน้าตั้งค่า LINE ของระบบ'); return; }
  if (typeof liff === 'undefined') { say('โหลด LINE SDK ไม่ได้ — ลองเปิดใหม่อีกครั้ง'); return; }
  liff.init({ liffId: LIFF_ID }).then(function () {
    ready = true;
    if (!liff.isInClient()) { say('หน้านี้ต้องเปิดจากปุ่ม "สแกน S/N" ในไลน์'); return; }
    scan();
  }).catch(function (e) {
    say('เปิดไม่สำเร็จ: ' + (e && e.message ? e.message : e));
  });
})();
</script>
</body>
</html>
