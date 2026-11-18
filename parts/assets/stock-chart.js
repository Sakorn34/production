/**
 * stock-chart.js — กราฟเส้นยอดคงเหลือย้อนหลังของอะไหล่ (หน้า product-detail)
 *
 * ขอบเขต: วาด SVG เอง ไม่พึ่งไลบรารี อ่านข้อมูลจาก data-* ของ #stock-chart
 *   data-series = JSON {day:[{k,in,out,bal}], month:[{k,in,out,bal}], year:[...]}  (คำนวณฝั่ง server: parts_stock_balance_series)
 *   data-min    = สต็อกขั้นต่ำ (เส้นประสีส้ม)   data-unit = หน่วย
 * flow: โหลด → draw('month') → ปุ่ม .stock-chart-seg สลับ day/month/year → วาดใหม่
 * ตัวอย่าง: <div id="stock-chart" data-series='{"month":[],"year":[]}' data-min="20" data-unit="ชิ้น"></div>
 */
(function () {
    'use strict';

    // ─────────────────────────────────────────────────────────────────────────
    // ค่าคงที่
    // ─────────────────────────────────────────────────────────────────────────
    var NS = 'http://www.w3.org/2000/svg';
    var W = 640, H = 260, PL = 46, PR = 30, PT = 12, PB = 30;
    var MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    var host = document.getElementById('stock-chart');
    if (!host) return;

    var data = { day: [], month: [], year: [] };
    try {
        data = JSON.parse(host.getAttribute('data-series') || '{}') || data;
    } catch (err) { /* ข้อมูลเสีย → แสดงข้อความว่าง */ }
    var minStock = parseFloat(host.getAttribute('data-min')) || 0;
    var unit = host.getAttribute('data-unit') || '';

    // ─────────────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** สร้าง element SVG พร้อม attribute */
    function el(name, attrs, parent) {
        var e = document.createElementNS(NS, name);
        for (var k in attrs) e.setAttribute(k, attrs[k]);
        if (parent) parent.appendChild(e);
        return e;
    }

    /** ปัดค่าสูงสุดของแกน Y ให้เป็นเลขกลมๆ */
    function niceMax(max) {
        if (!(max > 0)) return 10;
        var s = Math.pow(10, Math.floor(Math.log10(max)));
        var f = max / s;
        return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * s;
    }

    /** ป้ายแกน X: วัน = "5 ต.ค." · เดือน = "ก.ย. 69" · ปี = "2569" (พ.ศ.) */
    function label(k, period) {
        if (period === 'day') return parseInt(k.slice(8, 10), 10) + ' ' + MONTHS[parseInt(k.slice(5, 7), 10) - 1];
        if (period === 'year') return String(parseInt(k, 10) + 543);
        return MONTHS[parseInt(k.slice(5, 7), 10) - 1] + ' ' + String(parseInt(k.slice(0, 4), 10) + 543).slice(2);
    }

    /** ตัวเลขแสดงผล ไม่เอาศูนย์ท้าย */
    function num(v) {
        return (Math.round(v * 100) / 100).toLocaleString('en-US', { maximumFractionDigits: 2 });
    }

    // tooltip เดียวต่อกราฟ — textContent เท่านั้น ไม่ใช้ innerHTML กับข้อมูล
    var tip = document.createElement('div');
    tip.className = 'stock-chart-tip';
    tip.hidden = true;
    host.appendChild(tip);

    /**
     * path เส้นโค้งแบบ monotone cubic (Fritsch–Carlson) ผ่านทุกจุด และไม่พุ่งเกิน/ต่ำกว่าค่าจริงระหว่างจุด
     * เหมาะกับยอดสต็อก — เส้นสโลปนุ่ม แต่ไม่โก่งลงต่ำกว่าศูนย์หรือสูงกว่ายอดที่เคยมี
     *
     * @param {number[]} xs พิกัด x ของจุด (เรียงจากซ้ายไปขวา)
     * @param {number[]} ys พิกัด y ของจุด
     * @return {string} ค่า d สำหรับ <path>
     */
    function curvePath(xs, ys) {
        var n = xs.length, i;
        var d = 'M' + xs[0].toFixed(1) + ' ' + ys[0].toFixed(1);
        if (n < 2) return d;
        var m = [], t = [];
        for (i = 0; i < n - 1; i++) m[i] = (ys[i + 1] - ys[i]) / (xs[i + 1] - xs[i]);
        t[0] = m[0]; t[n - 1] = m[n - 2];
        for (i = 1; i < n - 1; i++) t[i] = m[i - 1] * m[i] <= 0 ? 0 : (m[i - 1] + m[i]) / 2;
        for (i = 0; i < n - 1; i++) {
            if (m[i] === 0) { t[i] = 0; t[i + 1] = 0; continue; }
            var a = t[i] / m[i], b = t[i + 1] / m[i], s = a * a + b * b;
            if (s > 9) { var tau = 3 / Math.sqrt(s); t[i] = tau * a * m[i]; t[i + 1] = tau * b * m[i]; }
        }
        for (i = 0; i < n - 1; i++) {
            var dx = (xs[i + 1] - xs[i]) / 3;
            d += ' C' + (xs[i] + dx).toFixed(1) + ' ' + (ys[i] + t[i] * dx).toFixed(1) + ' ' +
                (xs[i + 1] - dx).toFixed(1) + ' ' + (ys[i + 1] - t[i + 1] * dx).toFixed(1) + ' ' +
                xs[i + 1].toFixed(1) + ' ' + ys[i + 1].toFixed(1);
        }
        return d;
    }
    // ─────────────────────────────────────────────────────────────────────────
    // วาดกราฟ
    // ─────────────────────────────────────────────────────────────────────────

    /** วาดกราฟตามช่วงเวลา: 'day' | 'month' | 'year' */
    function draw(period) {
        var pts = data[period] || [];
        Array.prototype.slice.call(host.children).forEach(function (c) { if (c !== tip) host.removeChild(c); });
        if (!pts.length) {
            var p = document.createElement('p');
            p.className = 'text-muted';
            p.style.margin = '1rem 0';
            p.textContent = 'ยังไม่มีรายการเคลื่อนไหวให้แสดงกราฟ';
            host.insertBefore(p, tip);
            return;
        }

        // จอแคบ: viewBox ถูกย่อ ตัวอักษรเล็กเกินอ่าน → ขยายตามสัดส่วน (สูงสุด 16)
        var fs = Math.min(16, Math.max(11, Math.round(11 * W / Math.max(host.clientWidth, 1) * 0.85)));
        var max = niceMax(Math.max.apply(null, pts.map(function (q) { return q.bal; }).concat([minStock])));
        var iw = W - PL - PR, ih = H - PT - PB;
        var x = function (i) { return PL + (pts.length < 2 ? iw / 2 : i * iw / (pts.length - 1)); };
        var y = function (v) { return PT + ih - Math.max(0, v) / max * ih; };

        var svg = el('svg', {
            viewBox: '0 0 ' + W + ' ' + H,
            preserveAspectRatio: 'xMidYMid meet',
            width: '100%',
            role: 'img',
            'aria-label': 'กราฟยอดคงเหลือย้อนหลัง'
        });
        host.insertBefore(svg, tip);

        // เส้นตารางแกน Y
        for (var i = 0; i <= 4; i++) {
            var v = max * i / 4;
            el('line', { x1: PL, x2: W - PR, y1: y(v), y2: y(v), stroke: '#ece8f5' }, svg);
            var t = el('text', { x: PL - 6, y: y(v) + 4, 'text-anchor': 'end', 'font-size': fs, fill: '#6b6480' }, svg);
            t.textContent = num(v);
        }
        // ป้ายแกน X — เว้นระยะไม่ให้ทับกัน
        var step = Math.max(1, Math.ceil(pts.length / 8));
        pts.forEach(function (q, idx) {
            if ((pts.length - 1 - idx) % step !== 0) return;
            var tx = el('text', { x: x(idx), y: H - 8, 'text-anchor': 'middle', 'font-size': fs, fill: '#6b6480' }, svg);
            tx.textContent = label(q.k, period);
        });

        // พื้นที่ใต้เส้น + เส้นสต็อกขั้นต่ำ + เส้นยอดคงเหลือ (โค้ง monotone ไม่พุ่งเกินค่าจริง)
        var xs = pts.map(function (q, idx) { return x(idx); });
        var ys = pts.map(function (q) { return y(q.bal); });
        var line = curvePath(xs, ys);
        el('path', {
            d: line + ' L' + xs[xs.length - 1].toFixed(1) + ' ' + y(0) + ' L' + xs[0].toFixed(1) + ' ' + y(0) + ' Z',
            fill: 'rgba(107,33,168,.10)'
        }, svg);
        if (minStock > 0) {
            el('line', { x1: PL, x2: W - PR, y1: y(minStock), y2: y(minStock), stroke: '#d97706', 'stroke-width': 2, 'stroke-dasharray': '6 5' }, svg);
        }
        el('path', { d: line, fill: 'none', stroke: '#6b21a8', 'stroke-width': 2.4, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }, svg);

        // จุดบนเส้น: รายวัน = เฉพาะวันที่มีรายการ (เขียว = มีรับเข้า · แดง = เบิกออกอย่างเดียว)
        // รายเดือน/รายปีมีจุดน้อย → วงกลมทุกช่วง (แดง = ต่ำกว่าขั้นต่ำ)
        pts.forEach(function (q, idx) {
            if (period === 'day') {
                if (!(q.in > 0 || q.out > 0)) return;
                el('circle', { cx: xs[idx], cy: ys[idx], r: 3.4, fill: q.in > 0 ? '#16a34a' : '#dc2626', stroke: '#fff', 'stroke-width': 1.2 }, svg);
            } else {
                var below = minStock > 0 && q.bal <= minStock;
                el('circle', { cx: xs[idx], cy: ys[idx], r: 4, fill: '#fff', stroke: below ? '#dc2626' : '#6b21a8', 'stroke-width': 2 }, svg);
            }
        });

        // ชี้เมาส์/แตะ: เส้นแนวตั้ง + จุดไฮไลต์ + tooltip ของช่วงที่ใกล้ที่สุด (pointer events ใช้ได้ทั้งเมาส์และนิ้ว)
        var guide = el('line', { y1: PT, y2: PT + ih, stroke: '#b9aed6', 'stroke-width': 1, visibility: 'hidden' }, svg);
        var dot = el('circle', { r: 5, fill: '#fff', 'stroke-width': 2.2, visibility: 'hidden' }, svg);
        var hit = el('rect', { x: PL, y: PT, width: iw, height: ih, fill: 'transparent', style: 'touch-action:pan-y' }, svg);
        function hideHover() {
            guide.setAttribute('visibility', 'hidden');
            dot.setAttribute('visibility', 'hidden');
            tip.hidden = true;
        }
        function onMove(ev) {
            var r = svg.getBoundingClientRect();
            var px = (ev.clientX - r.left) / r.width * W;
            var i = pts.length < 2 ? 0 : Math.max(0, Math.min(pts.length - 1, Math.round((px - PL) / iw * (pts.length - 1))));
            var q = pts[i], below = minStock > 0 && q.bal <= minStock;
            guide.setAttribute('x1', xs[i]); guide.setAttribute('x2', xs[i]); guide.setAttribute('visibility', 'visible');
            dot.setAttribute('cx', xs[i]); dot.setAttribute('cy', ys[i]);
            dot.setAttribute('stroke', below ? '#dc2626' : '#6b21a8'); dot.setAttribute('visibility', 'visible');
            tip.textContent = label(q.k, period) + ' · คงเหลือ ' + num(q.bal) + ' ' + unit +
                ((q.in || q.out) ? ' (รับ +' + num(q.in) + ' / เบิก −' + num(q.out) + ')' : '') + (below ? ' · ต่ำกว่าขั้นต่ำ' : '');
            tip.hidden = false;
            tip.style.left = Math.min(Math.max(xs[i] / W * host.clientWidth - tip.offsetWidth / 2, 0), host.clientWidth - tip.offsetWidth) + 'px';
            tip.style.top = Math.max(ys[i] / H * svg.clientHeight - 46, 0) + 'px';
        }
        hit.addEventListener('pointermove', onMove);
        hit.addEventListener('pointerdown', onMove);
        hit.addEventListener('pointerleave', hideHover);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ปุ่มสลับช่วงเวลา
    // ─────────────────────────────────────────────────────────────────────────
    var card = host.closest('.stock-chart-card');
    var btns = card ? card.querySelectorAll('.stock-chart-seg button') : [];
    Array.prototype.forEach.call(btns, function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(btns, function (x) { x.classList.remove('is-on'); });
            b.classList.add('is-on');
            var p = b.getAttribute('data-period');
            draw(p === 'year' || p === 'day' ? p : 'month');
        });
    });

    draw('month');
})();
