// ถ่ายภาพหน้าจอประกอบคู่มือ (guide.php) จาก localhost ด้วย Edge headless ผ่าน DevTools Protocol
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const OUT = 'D:/AppServ/www/production/finishgoogs_ma_update/assets/guide';
const BASE = 'http://localhost/production/finishgoogs_ma_update';
const PARTS = 'http://localhost/production/parts/pages';
const PORT = 9333;
mkdirSync(OUT, { recursive: true });

const edge = spawn(EDGE, ['--headless=new', `--remote-debugging-port=${PORT}`, '--user-data-dir=' + process.env.TEMP + '/guide-shots-profile',
  '--no-first-run', '--disable-extensions', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let ws, seq = 0;
const pending = new Map();
function send(method, params = {}) {
  const id = ++seq;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((res, rej) => pending.set(id, { res, rej }));
}
async function connect() {
  for (let i = 0; i < 40; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${PORT}/json`)).json();
      const page = list.find((t) => t.type === 'page');
      if (page) {
        ws = new WebSocket(page.webSocketDebuggerUrl);
        await new Promise((r) => (ws.onopen = r));
        ws.onmessage = (ev) => {
          const m = JSON.parse(ev.data);
          if (m.id && pending.has(m.id)) {
            const p = pending.get(m.id); pending.delete(m.id);
            m.error ? p.rej(new Error(m.error.message)) : p.res(m.result);
          }
        };
        return;
      }
    } catch (e) {}
    await sleep(250);
  }
  throw new Error('edge not reachable');
}
async function js(expr) {
  const r = await send('Runtime.evaluate', { expression: `(async()=>{${expr}})()`, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error('JS: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
  return r.result.value;
}
async function go(url, wait = 1500) {
  await send('Page.navigate', { url });
  await sleep(wait);
  // ตัดแถบ/ป้ายที่ไม่เกี่ยว + สไตล์ไฮไลต์ปุ่มที่ต้องกด
  await js(`
    const st = document.createElement('style');
    st.textContent = '.guide-hl{outline:3px solid #e11d74 !important;outline-offset:3px;box-shadow:0 0 0 7px rgba(225,29,116,.22) !important;border-radius:10px}'
      + '.mbar.guide-hl{outline:0 !important;box-shadow:inset 0 0 0 3px #e11d74, 0 -6px 18px rgba(225,29,116,.25) !important;border-radius:0}'
      + '.flash-toast-host{display:none!important}';
    document.head.appendChild(st);
    try { localStorage.setItem('dashMobileTab','stock'); } catch(e) {}
  `);
}
function hl(selector, text) {
  // ไฮไลต์ element แรกที่ตรง selector (และมีข้อความ text ถ้าระบุ)
  return js(`
    const els = [...document.querySelectorAll(${JSON.stringify(selector)})].filter(e => ${text ? `e.textContent.includes(${JSON.stringify(text)})` : 'true'});
    const el = els.find(e => e.getBoundingClientRect().width > 0) || els[0];
    if (el) el.classList.add('guide-hl');
    return !!el;
  `);
}
async function shot(name, clipH = 0) {
  const params = { format: 'jpeg', quality: 78 };
  if (clipH) params.clip = { x: 0, y: 0, width: 390, height: clipH, scale: 1 };
  const r = await send('Page.captureScreenshot', params);
  writeFileSync(`${OUT}/${name}.jpg`, Buffer.from(r.data, 'base64'));
  console.log('saved', name);
}
async function scrollTo(selector, offset = 90) {
  await js(`const e=document.querySelector(${JSON.stringify(selector)}); if(e){ window.scrollTo(0, e.getBoundingClientRect().top + scrollY - ${offset}); }`);
  await sleep(400);
}

try {
  await connect();
  await send('Page.enable'); await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 780, deviceScaleFactor: 2, mobile: true });
  await send('Emulation.setLocaleOverride', { locale: 'th-TH' }).catch(() => {});
  await send('Emulation.setTimezoneOverride', { timezoneId: 'Asia/Bangkok' }).catch(() => {});
  await send('Emulation.setUserAgentOverride', { userAgent: 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36' });

  // 1 เริ่มต้น — แถบล่าง
  await go(BASE + '/index.php', 3500);
  await hl('.mbar');
  await shot('start-bar');

  // 2 สแกน — จังหวะเจอเครื่อง (จำลองกรอบผลลัพธ์ เพราะ headless ไม่มีกล้อง)
  await go(BASE + '/scan.php', 1500);
  await js(`const h=document.getElementById('scan-hit'); h.hidden=false; h.classList.remove('is-miss');
    document.getElementById('scan-hit-mark').textContent='✓'; document.getElementById('scan-hit-text').textContent='พบเครื่องแล้ว';
    document.getElementById('scan-hit-code').textContent='BP25061757'; document.getElementById('scan-hit-sub').textContent='bitVisitor Plus · เครื่องเช่า';
    document.getElementById('cam-status').textContent='กล้องหลัง · โหมดเร็ว · เล็งไปที่ QR หรือบาร์โค้ดบนตัวเครื่อง';
    document.querySelector('.scan-hero').style.display='none'; window.scrollTo(0,0);`);
  await sleep(1200);
  await shot('scan-found', 640);

  // 2b หน้าเครื่อง — ปุ่มบันทึก MA + เมนูอื่นๆ
  await go(BASE + '/asset.php?id=8755', 2000);
  await js(`document.querySelector('.asset-sales-card')?.remove(); document.querySelector('.asset-more-btn').click();`);
  await sleep(300);
  await hl('.asset-more-btn');
  await shot('asset-actions', 520);

  // 3 ลงทะเบียนผลิต — แถวรหัสเครื่อง + สแกนต่อเนื่อง
  await go(BASE + '/asset_new.php', 1500);
  await js(`pickProduct(document.querySelector('.pp[data-id="2"]')); await new Promise(r=>setTimeout(r,2500));
    const inp=document.querySelector('#unit-list input[name="serials[]"]'); if(inp){ inp.value='A2041560966203'; }
    addUnit(); const all=document.querySelectorAll('#unit-list input[name="serials[]"]'); all[1].value='A2041560966204';`);
  await hl('#scan-units');
  await scrollTo('#unit-list', 170);
  await shot('produce-units');

  // 3b ชุดอะไหล่ + checklist + ปุ่มบันทึก
  await go(BASE + '/asset_new.php', 1500);
  await js(`pickProduct(document.querySelector('.pp[data-id="22"]')); await new Promise(r=>setTimeout(r,3000));`);
  await hl('#save-btn');
  await scrollTo('#bom-list', 120);
  await shot('produce-save');

  // 4 MA — ฟอร์มบันทึก MA ของเครื่อง
  await go(BASE + '/ma.php?record=8755', 2500);
  await scrollTo('#ma_visited_at', 140);
  await js(`const l=document.getElementById('machine_status_label'); const n=l && l.nextElementSibling; if(n) n.classList.add('guide-hl');`);
  await shot('ma-form');

  // 5 อัปเดต FW/HW
  await go(BASE + '/update_new.php?asset=8755', 1800);
  await scrollTo('#utype', 230);
  await js(`const l=[...document.querySelectorAll('label')].find(x=>x.textContent.trim()==='ค่าใหม่'); if(l) l.parentElement.classList.add('guide-hl');`);
  await shot('update-form');

  // 6 อะไหล่ — ปุ่มรับเข้า/เบิก
  await go(PARTS + '/products.php', 2000);
  await js(`const b=[...document.querySelectorAll('a,button')].find(e=>e.textContent.trim()==='รับเข้า'); if(b) b.parentElement.classList.add('guide-hl');`);
  await shot('parts-list', 620);

  // 7 นับสต็อก
  await go(BASE + '/stock_scan.php', 2000);
  await js(`document.querySelector('details')?.removeAttribute('open');`);
  await hl('button, a.btn', 'เลือกรุ่นที่ถึงรอบนับ');
  await scrollTo('.guide-hl', 200);
  await shot('count-pick');

  // 8 Dashboard
  await go(BASE + '/index.php', 4500);
  await hl('#dash-fg-summary');
  await shot('dashboard-stock');

  // 9 แจ้งปัญหา
  await go(BASE + '/assets.php', 1500);
  await js(`document.querySelector('[data-support-open]').click();
    document.getElementById('support-msg').value='กดค้นหาแล้วไม่ขึ้นรายการ ลองสองรอบแล้ว';`);
  await sleep(300);
  await hl('#support-send');
  await shot('report-modal');
} catch (e) {
  console.error('ERROR', e.message);
} finally {
  try { ws?.close(); } catch (e) {}
  edge.kill();
}
