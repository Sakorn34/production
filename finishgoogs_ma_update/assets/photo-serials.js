/**
 * photo-serials.js — ถ่ายรูปทีละหลายเครื่อง แล้วอ่าน S/N ออกมาให้ตรวจก่อนเพิ่มลงฟอร์ม
 *
 * ทำไม: ป้ายบางรุ่น (เช่น Adapter 12V) เป็นบาร์โค้ดเส้นถี่ กล้องสแกนสด (~1080p) แยกเส้นไม่ออก
 * รูปนิ่งจากกล้องมือถือละเอียดกว่าหลายเท่า และถ่ายทีเดียวได้หลายเครื่อง
 *
 * ขั้นตอนต่อรูป (ทำบนเครื่องผู้ใช้ทั้งหมด ไม่ส่งรูปออกไปไหน):
 *   1. อ่านบาร์โค้ดทั้งรูป — BarcodeDetector ของเบราว์เซอร์ (Android) หรือ zxing-wasm (iPhone ฯลฯ)
 *   2. หาป้ายสีขาวในรูป (สติกเกอร์ S/N) → ป้ายที่ข้อ 1 ยังไม่ได้ ลองอ่านบาร์โค้ดจากป้ายที่ครอปขยาย
 *   3. ยังไม่ได้อีก → อ่านตัวหนังสือใต้บาร์โค้ด (Tesseract OCR) แล้วเทียบกับรูปแบบรหัสของรุ่นนั้น
 *      (ตัวอย่างรหัสล่าสุดของรุ่น → ตัวอักษร/ตัวเลขแต่ละตำแหน่ง + ส่วนต้นที่เหมือนกัน ช่วยแก้ O/0 D/0 ฯลฯ)
 *      ผลจาก OCR ติดป้าย "ตรวจ" เสมอ และแสดงรูปป้ายข้าง ๆ ให้เทียบได้ทันที
 *
 * ใช้:  FgPhotoSerials.open({ samples: [...รหัสล่าสุดของรุ่น], check(code) → Promise<{exists,model}|null>,
 *                            existing: [...รหัสที่อยู่ในฟอร์มแล้ว], onAdd(codes) })
 */
(function () {
  'use strict';

  var ZXING_URL = 'https://cdn.jsdelivr.net/npm/zxing-wasm@2/dist/es/reader/index.js';
  var TESS_URL = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
  var FORMATS = ['Code128', 'Code39', 'Code93', 'EAN-13', 'ITF', 'QRCode', 'DataMatrix'];
  var MAX_SIDE = 4096;

  var ui = null, state = null, zxing = null, tessWorker = null;

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  // ── ไลบรารี (โหลดเมื่อใช้ครั้งแรก) ──
  function loadZxing() {
    if (zxing) { return Promise.resolve(zxing); }
    return import(ZXING_URL).then(function (m) { zxing = m; return m; });
  }
  function loadTess() {
    if (tessWorker) { return Promise.resolve(tessWorker); }
    var ready = window.Tesseract ? Promise.resolve() : new Promise(function (ok, bad) {
      var s = document.createElement('script');
      s.src = TESS_URL; s.onload = ok; s.onerror = function () { bad(new Error('โหลดตัวอ่านตัวหนังสือไม่ได้')); };
      document.head.appendChild(s);
    });
    return ready.then(function () { return window.Tesseract.createWorker('eng'); }).then(function (w) {
      return w.setParameters({ tessedit_char_whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', tessedit_pageseg_mode: '6' })
        .then(function () { tessWorker = w; return w; });
    });
  }

  // ── รูปแบบรหัสจากตัวอย่างของรุ่น ──
  function buildTemplate(samples) {
    var list = (samples || []).map(function (s) { return String(s).trim().toUpperCase(); }).filter(function (s) { return /^[A-Z0-9]{5,30}$/.test(s); });
    if (!list.length) { return null; }
    var lenCount = {};
    list.forEach(function (s) { lenCount[s.length] = (lenCount[s.length] || 0) + 1; });
    var len = +Object.keys(lenCount).sort(function (a, b) { return lenCount[b] - lenCount[a]; })[0];
    var same = list.filter(function (s) { return s.length === len; });
    var kinds = '';
    for (var i = 0; i < len; i++) {
      var d = same.filter(function (s) { return /\d/.test(s[i]); }).length;
      kinds += d * 2 >= same.length ? 'D' : 'L';
    }
    // ส่วนต้นที่เหมือนกันคิดจากรหัสล่าสุด 10 ตัว (ล็อตที่กำลังผลิต) — ถ้ารวมล็อตเก่าทั้งหมด ส่วนต้นจะสั้นจนช่วยแก้ตัวอักษรไม่ได้
    // ไม่บังคับใช้ส่วนต้นนี้ ถ้ารูปอ่านได้ต่างจริง (ล็อตใหม่) จะเก็บตามที่อ่านได้ ดู matchTemplate
    // ใช้ส่วนต้นที่ยาวที่สุดที่รหัสล่าสุดอย่างน้อย 70% ใช้ร่วมกัน — รหัสล็อตเก่า/พิมพ์ผิดปนมาตัวสองตัวจะได้ไม่ตัดส่วนต้นจนสั้น
    var recent = same.slice(0, 10), need = Math.ceil(recent.length * 0.7), prefix = '';
    for (var L = len - 2; L > 0; L--) {
      var p = recent[0].slice(0, L);
      if (recent.filter(function (s) { return s.slice(0, L) === p; }).length >= need) { prefix = p; break; }
    }
    // เหลือท้ายไว้ให้อ่านอย่างน้อย 2 ตัว — ไม่งั้นส่วนต้นกลืนทั้งรหัส
    prefix = prefix.slice(0, Math.max(0, len - 2));
    return { len: len, kinds: kinds, prefix: prefix };
  }
  var TO_DIGIT = { O: '0', D: '0', Q: '0', U: '0', I: '1', L: '1', T: '1', J: '1', S: '5', B: '8', Z: '2', G: '6', A: '4' };
  var TO_LETTER = { '0': 'O', '1': 'I', '5': 'S', '8': 'B', '2': 'Z', '6': 'G', '4': 'A' };
  function cls(ch) { return TO_DIGIT[ch] || ch; }

  /** หารหัสตามรูปแบบในบรรทัดข้อความ OCR — คืน {code, score} (score ต่ำ = มั่นใจ) */
  function matchTemplate(line, t) {
    line = line.replace(/[^A-Z0-9]/g, '');
    if (!t) {
      var m = line.match(/[A-Z0-9]{8,24}/);
      return m ? { code: m[0], score: 5 } : null;
    }
    var best = null;
    for (var i = 0; i + t.len <= line.length + 1; i++) {
      var seg = line.slice(i, i + t.len);
      if (seg.length < t.len) { break; }
      var score = 0, out = '';
      for (var k = 0; k < t.len; k++) {
        var ch = seg[k];
        // ส่วนต้นที่ทุกรหัสของรุ่นนี้เหมือนกัน: ถ้าคลาสตรง (O≈0, D≈0 ฯลฯ) ใช้ตัวจริงของรุ่น ไม่ตรงก็เก็บตามที่อ่านได้
        if (k < t.prefix.length) {
          if (cls(ch) === cls(t.prefix[k])) { out += t.prefix[k]; continue; }
          score += 2;
        }
        if (t.kinds[k] === 'D') {
          if (!/\d/.test(ch)) { if (TO_DIGIT[ch]) { ch = TO_DIGIT[ch]; score += 1; } else { score += 3; } }
        } else if (/\d/.test(ch)) {
          if (TO_LETTER[ch]) { ch = TO_LETTER[ch]; score += 1; } else { score += 3; }
        }
        out += ch;
      }
      if (!best || score < best.score) { best = { code: out, score: score }; }
    }
    return best && best.score <= Math.max(3, Math.round(t.len / 4)) ? best : null;
  }

  // ── หาป้ายสีขาวในรูป (สติกเกอร์ S/N บนตัวเครื่องสีเข้ม) ──
  function findLabels(canvas) {
    var W = 600, sc = W / canvas.width, H = Math.round(canvas.height * sc);
    var c = document.createElement('canvas'); c.width = W; c.height = H;
    var g = c.getContext('2d'); g.drawImage(canvas, 0, 0, W, H);
    var d = g.getImageData(0, 0, W, H).data, n = W * H, m = new Uint8Array(n), seen = new Uint8Array(n), boxes = [], st = [];
    for (var i = 0; i < n; i++) {
      var r = d[i * 4], gg = d[i * 4 + 1], b = d[i * 4 + 2], mn = Math.min(r, gg, b), mx = Math.max(r, gg, b);
      m[i] = (mn > 165 && mx - mn < 38) ? 1 : 0;
    }
    for (var p = 0; p < n; p++) {
      if (!m[p] || seen[p]) { continue; }
      var x0 = W, y0 = H, x1 = 0, y1 = 0, cnt = 0;
      seen[p] = 1; st.push(p);
      while (st.length) {
        var q = st.pop(), x = q % W, y = (q / W) | 0;
        cnt++;
        if (x < x0) { x0 = x; } if (x > x1) { x1 = x; } if (y < y0) { y0 = y; } if (y > y1) { y1 = y; }
        if (x > 0 && m[q - 1] && !seen[q - 1]) { seen[q - 1] = 1; st.push(q - 1); }
        if (x < W - 1 && m[q + 1] && !seen[q + 1]) { seen[q + 1] = 1; st.push(q + 1); }
        if (y > 0 && m[q - W] && !seen[q - W]) { seen[q - W] = 1; st.push(q - W); }
        if (y < H - 1 && m[q + W] && !seen[q + W]) { seen[q + W] = 1; st.push(q + W); }
      }
      var bw = x1 - x0 + 1, bh = y1 - y0 + 1, area = bw * bh, ar = Math.max(bw, bh) / Math.min(bw, bh);
      if (area > n * 0.002 && area < n * 0.15 && ar > 1.8 && ar < 9 && cnt / area > 0.3) {
        boxes.push({ x: x0 / sc, y: y0 / sc, w: bw / sc, h: bh / sc });
      }
    }
    // เรียงบนลงล่าง ซ้ายไปขวา ให้ลำดับในรายการตรงกับที่วางเครื่องไว้
    boxes.sort(function (a, b) { return Math.abs(a.y - b.y) > Math.min(a.h, b.h) / 2 ? a.y - b.y : a.x - b.x; });
    return boxes;
  }

  function crop(canvas, b, targetW, filter, rotate) {
    var mx = b.w * 0.04, my = b.h * 0.12;
    var sx = Math.max(0, b.x - mx), sy = Math.max(0, b.y - my);
    var sw = Math.min(canvas.width - sx, b.w + 2 * mx), sh = Math.min(canvas.height - sy, b.h + 2 * my);
    var long = rotate ? sh : sw, scale = targetW / long;
    var c = document.createElement('canvas');
    c.width = Math.round((rotate ? sh : sw) * scale); c.height = Math.round((rotate ? sw : sh) * scale);
    var g = c.getContext('2d');
    g.imageSmoothingQuality = 'high';
    if (filter) { g.filter = filter; }
    if (rotate) { g.translate(c.width / 2, c.height / 2); g.rotate(rotate * Math.PI / 180); g.drawImage(canvas, sx, sy, sw, sh, -c.height / 2, -c.width / 2, c.height, c.width); }
    else { g.drawImage(canvas, sx, sy, sw, sh, 0, 0, c.width, c.height); }
    return c;
  }
  function overlaps(a, b) { return a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h; }

  // ── อ่านบาร์โค้ด ──
  function readBarcodes(canvas) {
    var nativeTry = ('BarcodeDetector' in window)
      ? new window.BarcodeDetector().detect(canvas).then(function (list) {
          return list.map(function (r) { var bb = r.boundingBox; return { text: r.rawValue, box: { x: bb.x, y: bb.y, w: bb.width, h: bb.height } }; });
        }).catch(function () { return []; })
      : Promise.resolve([]);
    return nativeTry.then(function (found) {
      if (found.length) { return found; }
      return loadZxing().then(function (z) {
        var id = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
        return z.readBarcodes(id, { tryHarder: true, formats: FORMATS, maxNumberOfSymbols: 60 });
      }).then(function (res) {
        return res.filter(function (r) { return r.isValid && r.text; }).map(function (r) {
          var p = r.position, xs = [p.topLeft.x, p.topRight.x, p.bottomLeft.x, p.bottomRight.x], ys = [p.topLeft.y, p.topRight.y, p.bottomLeft.y, p.bottomRight.y];
          var x = Math.min.apply(null, xs), y = Math.min.apply(null, ys);
          return { text: r.text, box: { x: x, y: y, w: Math.max.apply(null, xs) - x, h: Math.max.apply(null, ys) - y } };
        });
      }).catch(function () { return []; });
    });
  }

  // ── อ่านทั้งรูป ──
  function processImage(file, t, onStep) {
    return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return createImageBitmap(file); }).then(function (bmp) {
      var s = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * s); canvas.height = Math.round(bmp.height * s);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      var results = [];
      onStep('อ่านบาร์โค้ด…');
      return readBarcodes(canvas).then(function (codes) {
        codes.forEach(function (c) {
          results.push({ code: c.text.trim().toUpperCase(), how: 'barcode', thumb: crop(canvas, c.box, 360).toDataURL('image/jpeg', 0.8), box: c.box });
        });
        var labels = findLabels(canvas).filter(function (b) {
          return !results.some(function (r) { return overlaps(r.box, b); });
        });
        // ป้ายที่ยังไม่ได้: ครอปขยายแล้วลองบาร์โค้ดอีกรอบ → ไม่ได้ค่อยอ่านตัวหนังสือ
        var chain = Promise.resolve();
        labels.forEach(function (b, idx) {
          chain = chain.then(function () {
            onStep('อ่านป้าย ' + (idx + 1) + '/' + labels.length + '…');
            var rot = b.h > b.w ? 90 : 0;
            var big = crop(canvas, b, 1600, '', rot);
            var thumb = crop(canvas, b, 360, '', rot).toDataURL('image/jpeg', 0.8);
            return readBarcodes(big).then(function (codes) {
              if (codes.length) {
                results.push({ code: codes[0].text.trim().toUpperCase(), how: 'barcode', thumb: thumb, box: b });
                return;
              }
              return ocrLabel(canvas, b, rot, t).then(function (hit) {
                if (hit && hit.noise) { return; }
                results.push({ code: hit ? hit.code : '', how: hit ? 'ocr' : 'none', thumb: thumb, box: b, prefill: hit ? '' : (t ? t.prefix : '') });
              });
            });
          });
        });
        return chain.then(function () { return results; });
      });
    });
  }

  function ocrLabel(canvas, b, rot, t) {
    return loadTess().then(function (w) {
      var tries = [['grayscale(1) contrast(1.6)', 1400], ['grayscale(1) contrast(2.2) brightness(1.1)', 1800], ['grayscale(1)', 1000]];
      var best = null, textLen = 0;
      var chain = Promise.resolve();
      tries.forEach(function (tr) {
        chain = chain.then(function () {
          if (best && best.score === 0) { return; }
          var rots = rot ? [rot, -rot] : [0];
          var inner = Promise.resolve();
          rots.forEach(function (r) {
            inner = inner.then(function () {
              if (best && best.score === 0) { return; }
              return w.recognize(crop(canvas, b, tr[1], tr[0], r)).then(function (res) {
                textLen = Math.max(textLen, (res.data.text.match(/[A-Z0-9]/g) || []).length);
                res.data.text.split('\n').forEach(function (line) {
                  var hit = matchTemplate(line, t);
                  if (hit && (!best || hit.score < best.score)) { best = hit; }
                });
              });
            });
          });
          return inner;
        });
      });
      // ไม่มีตัวหนังสือเลย = ไม่ใช่ป้าย (พื้นโต๊ะสีอ่อน ฯลฯ) ไม่ต้องขึ้นในรายการ
      return chain.then(function () { return best || (textLen < 6 ? { noise: true } : null); });
    }).catch(function () { return null; });
  }

  // ── หน้าต่าง ──
  function build() {
    if (ui) { return; }
    var el = document.createElement('div');
    el.className = 'fps-overlay';
    el.hidden = true;
    el.innerHTML =
      '<div class="fps-box" role="dialog" aria-modal="true" aria-labelledby="fps-title">'
      + '<div class="fps-head"><b id="fps-title">ถ่ายรูปหลายเครื่อง</b><button type="button" class="fps-x" aria-label="ปิด">✕</button></div>'
      + '<p class="fps-tip">วางเครื่องเรียงกัน หันป้าย S/N ขึ้น ให้ป้ายชัดและไม่สะท้อนแสง ถ่ายได้ครั้งละ 5–10 เครื่อง</p>'
      + '<div class="fps-pick">'
      + '<label class="btn"><input type="file" accept="image/*" capture="environment" hidden data-in="cam"> ถ่ายรูป</label>'
      + '<label class="btn btn-line"><input type="file" accept="image/*" multiple hidden data-in="lib"> เลือกจากคลังรูป</label>'
      + '</div>'
      + '<p class="fps-status" aria-live="polite"></p>'
      + '<div class="fps-list"></div>'
      + '<div class="fps-foot"><button type="button" class="btn fps-add" disabled>เพิ่มลงฟอร์ม</button></div>'
      + '</div>';
    document.body.appendChild(el);
    ui = { root: el, status: el.querySelector('.fps-status'), list: el.querySelector('.fps-list'), add: el.querySelector('.fps-add') };
    el.querySelector('.fps-x').addEventListener('click', close);
    el.addEventListener('click', function (e) { if (e.target === el) { close(); } });
    [].forEach.call(el.querySelectorAll('input[type=file]'), function (inp) {
      inp.addEventListener('change', function () { var f = [].slice.call(inp.files || []); inp.value = ''; if (f.length) { run(f); } });
    });
    ui.list.addEventListener('input', function (e) {
      if (e.target.classList.contains('fps-code')) {
        var row = e.target.closest('.fps-row');
        var pre = e.target.getAttribute('data-prefill');
        row.querySelector('.fps-cb').checked = e.target.value.trim() !== '' && e.target.value.trim().toUpperCase() !== (pre || '').toUpperCase();
        row.setAttribute('data-edited', '1');
        recheck(row);
      }
      updateAdd();
    });
    ui.list.addEventListener('change', updateAdd);
    ui.add.addEventListener('click', function () {
      var codes = [].slice.call(ui.list.querySelectorAll('.fps-row')).filter(function (r) {
        return r.querySelector('.fps-cb').checked && r.querySelector('.fps-code').value.trim() !== '';
      }).map(function (r) { return r.querySelector('.fps-code').value.trim().toUpperCase(); });
      var opts = state.opts;
      close();
      if (codes.length && opts.onAdd) { opts.onAdd(codes); }
    });
  }

  function updateAdd() {
    var n = [].slice.call(ui.list.querySelectorAll('.fps-row')).filter(function (r) {
      return r.querySelector('.fps-cb').checked && r.querySelector('.fps-code').value.trim() !== '';
    }).length;
    ui.add.disabled = !n;
    ui.add.textContent = n ? 'เพิ่ม ' + n + ' เครื่องลงฟอร์ม' : 'เพิ่มลงฟอร์ม';
  }

  function tagHtml(kind, text) { return '<span class="fps-tag is-' + kind + '">' + esc(text) + '</span>'; }

  /** ตรวจซ้ำในรูป/ในฟอร์ม/ในระบบ แล้วติดป้าย */
  function recheck(row) {
    var code = row.querySelector('.fps-code').value.trim().toUpperCase();
    var tag = row.querySelector('.fps-tags');
    var how = row.getAttribute('data-how');
    var base = how === 'barcode' ? tagHtml('ok', 'บาร์โค้ด') : (how === 'ocr' && !row.getAttribute('data-edited') ? tagHtml('check', 'ตัวหนังสือ · ตรวจ') : '');
    var inp = row.querySelector('.fps-code');
    var pre = inp.getAttribute('data-prefill');
    if (!code || (pre && code === pre)) {
      tag.innerHTML = tagHtml('check', pre ? 'อ่านไม่ออก — ดูรูปแล้วพิมพ์ตัวท้าย' : 'อ่านไม่ออก — พิมพ์เอง');
      row.querySelector('.fps-cb').checked = false;
      return;
    }
    var others = [].slice.call(ui.list.querySelectorAll('.fps-code')).filter(function (i) { return i !== row.querySelector('.fps-code') && i.value.trim().toUpperCase() === code; });
    if (others.length || state.existing.indexOf(code) >= 0) {
      tag.innerHTML = base + tagHtml('dup', others.length ? 'ซ้ำในรูป' : 'มีในฟอร์มแล้ว');
      row.querySelector('.fps-cb').checked = false;
      return;
    }
    tag.innerHTML = base;
    if (!state.opts.check) { return; }
    var mine = ++row._seq || (row._seq = 1);
    state.opts.check(code).then(function (r) {
      if (row._seq !== mine) { return; }
      if (r && r.exists) {
        tag.innerHTML = base + tagHtml('dup', 'มีในระบบแล้ว · ' + (r.model || ''));
        row.querySelector('.fps-cb').checked = false;
        updateAdd();
      }
    });
  }

  function run(files) {
    var t = state.template;
    var idx = 0;
    ui.add.disabled = true;
    function next() {
      if (idx >= files.length) {
        ui.status.textContent = ui.list.children.length ? 'อ่านได้ ' + ui.list.querySelectorAll('.fps-code').length + ' ป้าย — ตรวจรหัสเทียบกับรูปป้าย แล้วกดเพิ่ม' : 'ไม่พบป้ายในรูป — ลองถ่ายใกล้ขึ้น ให้ป้ายชัด';
        updateAdd();
        return;
      }
      var f = files[idx++];
      var prefix = files.length > 1 ? 'รูป ' + idx + '/' + files.length + ' · ' : '';
      processImage(f, t, function (s) { ui.status.textContent = prefix + s; }).then(function (res) {
        res.forEach(function (r) {
          var row = document.createElement('div');
          row.className = 'fps-row';
          row.setAttribute('data-how', r.how);
          // อ่านไม่ออก = ไม่ติ๊กไว้ก่อน (ช่องมีแค่ส่วนต้นที่เติมให้) จนกว่าจะพิมพ์ตัวท้ายเอง
          row.innerHTML = '<input type="checkbox" class="fps-cb"' + (r.code ? ' checked' : '') + ' aria-label="เลือก">'
            + '<img class="fps-thumb" alt="" src="' + r.thumb + '">'
            + '<div class="fps-main"><input type="text" class="fps-code" value="' + esc(r.code || r.prefill || '') + '"'
            + (r.prefill && !r.code ? ' data-prefill="' + esc(r.prefill) + '"' : '') + ' autocomplete="off" spellcheck="false" aria-label="รหัสเครื่อง">'
            + '<div class="fps-tags"></div></div>';
          ui.list.appendChild(row);
          recheck(row);
        });
        updateAdd();
        next();
      }).catch(function (e) {
        ui.status.textContent = 'อ่านรูปไม่สำเร็จ: ' + (e && e.message ? e.message : e);
        next();
      });
    }
    ui.status.textContent = 'กำลังเตรียมตัวอ่าน… (ครั้งแรกอาจใช้เวลาโหลดสักครู่)';
    next();
  }

  function open(opts) {
    build();
    state = { opts: opts || {}, template: buildTemplate((opts || {}).samples), existing: ((opts || {}).existing || []).map(function (s) { return String(s).trim().toUpperCase(); }) };
    ui.list.innerHTML = '';
    ui.status.textContent = '';
    updateAdd();
    ui.root.hidden = false;
    document.documentElement.classList.add('fgs-open');
  }
  function close() {
    if (!ui) { return; }
    ui.root.hidden = true;
    document.documentElement.classList.remove('fgs-open');
  }

  window.FgPhotoSerials = { open: open, _matchTemplate: matchTemplate, _buildTemplate: buildTemplate, _findLabels: findLabels, _process: processImage };
})();
