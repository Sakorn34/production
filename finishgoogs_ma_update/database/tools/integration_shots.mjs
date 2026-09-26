// ถ่ายภาพจุดจริงในระบบที่ใช้ข้อมูลจากระบบอื่น — ประกอบหน้า integration.php
// โครงเดียวกับ guide_shots.mjs แต่ครอปเฉพาะ element ที่ต้องการ (ไม่ใช่ทั้งหน้าจอ)
//   node integration_shots.mjs            ถ่ายใหม่ทั้งชุด
//   node integration_shots.mjs sale rent  ถ่ายเฉพาะที่ชื่อขึ้นต้นด้วยคำนั้น
import { spawn } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';

const EDGE = 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe';
const OUT = 'D:/AppServ/www/production/finishgoogs_ma_update/assets/integration';
const BASE = 'http://localhost/production/finishgoogs_ma_update';
const PORT = 9334;
const VIEW_W = 1180;
const ONLY = process.argv.slice(2);
const want = (name) => ONLY.length === 0 || ONLY.some((o) => name.startsWith(o));
mkdirSync(OUT, { recursive: true });

const edge = spawn(EDGE, ['--headless=new', `--remote-debugging-port=${PORT}`,
  '--user-data-dir=' + process.env.TEMP + '/integration-shots-profile',
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
async function go(url, wait = 1800) {
  await send('Page.navigate', { url });
  await sleep(wait);
  await js(`
    const st = document.createElement('style');
    st.textContent = '.ig-hl{outline:3px solid #e11d74 !important;outline-offset:2px;border-radius:10px}'
      + '.flash-toast-host{display:none!important}';
    document.head.appendChild(st);
  `);
}

/**
 * ครอปภาพเฉพาะกล่องที่ระบุ — ได้ภาพที่ "เป็นจุดนั้นจริง ๆ" ไม่ต้องมานั่งหาในภาพเต็มหน้า
 * pad เผื่อขอบรอบ ๆ · maxH กันกล่องยาวเกินจนภาพสูงเป็นหางว่าว
 */
async function shotEl(name, selector, { pad = 12, maxH = 560, hl = true, contains = '' } = {}) {
  if (!want(name)) { return; }
  // เลื่อนหาก่อน แล้ว "วัดกรอบใหม่อีกที" ในคอลถัดไป — ถ้าวัดในคอลเดียวกับที่เลื่อน
  // ค่าที่ได้จะเป็นตำแหน่งก่อนหน้าเลย์เอาต์นิ่ง ภาพที่ครอปออกมาจะเลื่อนไปจากของจริง
  const found = await js(`
    const els = [...document.querySelectorAll(${JSON.stringify(selector)})]
      .filter(e => ${contains ? `e.textContent.includes(${JSON.stringify(contains)})` : 'true'})
      .filter(e => e.getBoundingClientRect().width > 0);
    const el = els[0];
    if (!el) { return false; }
    el.setAttribute('data-ig-shot', '1');
    ${hl ? "el.classList.add('ig-hl');" : ''}
    el.scrollIntoView({ block: 'center' });
    return true;
  `);
  if (!found) { console.log('SKIP (ไม่เจอ element):', name, selector); return; }
  await sleep(700);
  // clip ของ Page.captureScreenshot คิดเป็นพิกัดของ "เอกสารทั้งหน้า" ไม่ใช่พิกัดในจอ
  // ต้องบวก scrollX/scrollY เข้าไป ไม่งั้นภาพจะเลื่อนไปจากกล่องที่ตั้งใจถ่ายเท่ากับระยะที่เลื่อนมา
  const box = await js(`
    const el = document.querySelector('[data-ig-shot="1"]');
    const r = el.getBoundingClientRect();
    return { x: r.left + scrollX, y: r.top + scrollY, w: r.width, h: r.height };
  `);
  const x = Math.max(0, Math.round(box.x - pad));
  const y = Math.max(0, Math.round(box.y - pad));
  const width = Math.min(VIEW_W - x, Math.round(box.w + pad * 2));
  const height = Math.min(maxH, Math.round(box.h + pad * 2));
  const r = await send('Page.captureScreenshot', {
    format: 'jpeg', quality: 80,
    clip: { x, y, width, height, scale: 1 },
    captureBeyondViewport: true,
  });
  writeFileSync(`${OUT}/${name}.jpg`, Buffer.from(r.data, 'base64'));
  console.log('saved', name, `${width}x${height}`);
}

try {
  await connect();
  await send('Page.enable'); await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: VIEW_W, height: 900, deviceScaleFactor: 2, mobile: false });
  await send('Emulation.setLocaleOverride', { locale: 'th-TH' }).catch(() => {});
  await send('Emulation.setTimezoneOverride', { timezoneId: 'Asia/Bangkok' }).catch(() => {});

  // ── งานขาย / ติดตั้ง ─────────────────────────────────────────────
  await go(BASE + '/asset.php?id=18881', 2800);
  await shotEl('sale-card', '.asset-sales-card', { maxH: 430 });
  await shotEl('sale-status', '.info.asset-head-main', { maxH: 330 });
  await go(BASE + '/search.php?q=' + encodeURIComponent('วีไอพี'), 2600);
  await shotEl('sale-search', 'main.content', { maxH: 420, hl: false, pad: 0 });

  // ── งานเช่า ──────────────────────────────────────────────────────
  await go(BASE + '/ma.php', 2600);
  await shotEl('rent-queue', '.grid-products', { maxH: 400 });
  await go(BASE + '/asset.php?id=14898', 2800);
  await shotEl('rent-timeline', 'ul.timeline', { maxH: 430 });
  await shotEl('rent-history', '.asset-rent-card, .asset-sales-card', { maxH: 400 });
  await go(BASE + '/asset.php?id=387', 2600);
  await shotEl('rent-card', '.asset-rent-card', { maxH: 400 });

  // ── งานซ่อม ──────────────────────────────────────────────────────
  await go(BASE + '/asset.php?id=7212', 2800);
  await shotEl('repair-section', '.ma-repair-section', { maxH: 480 });

  // ── ทะเบียนสินค้า / สต๊อกกลาง ────────────────────────────────────
  await go(BASE + '/share.php', 2600);
  await shotEl('stock-stats', '.stock-stats', { maxH: 200 });
  await go(BASE + '/share_admin.php', 2600);
  await shotEl('stock-compare', '.rc-stats', { maxH: 220 });

  // ── ใบเบิกผลิต (inventory) ───────────────────────────────────────
  await go(BASE + '/index.php', 4200);
  await shotEl('inv-queue', '#dash-pickup', { maxH: 480 });
  await go(BASE + '/inv_pickups.php', 2600);
  await shotEl('inv-list', '.ip-grid', { maxH: 430 });

  // ── คลังอะไหล่ช่าง ───────────────────────────────────────────────
  await go(BASE + '/asset_new.php', 2800);
  // ต้องเลือกรุ่นก่อน ชุดอะไหล่ถึงจะโหลดยอดคงเหลือจากคลังช่างมาแสดง
  await js(`const p=document.querySelector('#prod-pick .pp[data-id="22"]')||document.querySelector('#prod-pick .pp'); if(p){p.click();}`);
  await sleep(2600);
  await shotEl('parts-chips', '.panel', { contains: 'ชุดอะไหล่', maxH: 430 });
  await go(BASE + '/parts_link_check.php', 2600);
  await shotEl('parts-check', '.table-wrap', { maxH: 430 });

  console.log('เสร็จแล้ว →', OUT);
} catch (e) {
  console.error('ผิดพลาด:', e.message);
} finally {
  try { ws && ws.close(); } catch (e) {}
  edge.kill();
  process.exit(0);
}
