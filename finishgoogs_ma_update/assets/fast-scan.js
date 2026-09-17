/**
 * fast-scan.js — ตัวสแกน QR/บาร์โค้ดที่อ่านเร็วแบบแอปมือถือ
 *
 * ทำไมไม่ใช้ html5-qrcode ตรง ๆ: มันถอดรหัสด้วย JavaScript บนภาพที่ย่อลงมา
 * ฉลากเล็ก/เอียง/แสงน้อยจึงจับยาก ต่างจาก AppSheet ที่ใช้ตัวอ่านของระบบมือถือ
 *
 * ลำดับที่เลือกใช้:
 *   1. BarcodeDetector ของเบราว์เซอร์ (Chrome บน Android) — ตัวเดียวกับที่แอปมือถือใช้
 *      อ่านจากภาพกล้องความละเอียดเต็ม เร็วและแม่นที่สุด
 *   2. html5-qrcode (iPhone/เบราว์เซอร์ที่ไม่มีข้อ 1) — แต่ตั้งให้ใช้กล้องความละเอียดสูง
 *      อ่านได้ทั้ง QR และบาร์โค้ดเส้น และกรอบอ่านกว้างเกือบเต็มจอ
 *
 * ใช้:
 *   FastScan.start({ stage, facing: 'environment'|'user', onCode(text), onStatus(text) })
 *     → Promise<controller>  controller.stop() / setTorch(bool) / setZoom(n)
 */
(function () {
  'use strict';

  var NATIVE_FORMATS = ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'data_matrix', 'upc_a', 'itf'];

  // facing: 'environment' = กล้องหลัง (ค่าเริ่มต้น) · 'user' = กล้องหน้า
  function videoConstraints(facing) {
    return {
      facingMode: { ideal: facing === 'user' ? 'user' : 'environment' },
      width: { ideal: 1920 },
      height: { ideal: 1080 }
    };
  }

  function tuneTrack(track) {
    var caps = {};
    try { caps = track.getCapabilities ? track.getCapabilities() : {}; } catch (e) {}
    var adv = [];
    // โฟกัสต่อเนื่อง — ไม่งั้นบางรุ่นล็อกโฟกัสไว้ไกล ฉลากใกล้ ๆ เบลอจนอ่านไม่ออก
    if (caps.focusMode && caps.focusMode.indexOf('continuous') >= 0) { adv.push({ focusMode: 'continuous' }); }
    if (caps.exposureMode && caps.exposureMode.indexOf('continuous') >= 0) { adv.push({ exposureMode: 'continuous' }); }
    if (adv.length) { track.applyConstraints({ advanced: adv }).catch(function () {}); }
    return caps;
  }

  function controllerFor(track, caps, stopFn, engine) {
    return {
      engine: engine,
      canTorch: !!caps.torch,
      zoom: caps.zoom ? { min: caps.zoom.min, max: caps.zoom.max, step: caps.zoom.step || 0.1 } : null,
      setTorch: function (on) {
        return track.applyConstraints({ advanced: [{ torch: !!on }] });
      },
      setZoom: function (z) {
        return track.applyConstraints({ advanced: [{ zoom: Number(z) }] });
      },
      stop: stopFn
    };
  }

  // ── 1) ตัวอ่านของระบบ ──────────────────────────────────────────────────
  function startNative(opts) {
    var stage = opts.stage;
    var video = document.createElement('video');
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    // กล้องหน้าแสดงกลับด้านแบบกระจก จะได้เล็งง่าย (ไม่มีผลกับการอ่านรหัส)
    video.className = 'fs-video' + (opts.facing === 'user' ? ' fs-mirror' : '');
    stage.appendChild(video);

    var stopped = false;
    var stream = null;

    return BarcodeDetector.getSupportedFormats().then(function (supported) {
      var formats = NATIVE_FORMATS.filter(function (f) { return supported.indexOf(f) >= 0; });
      if (formats.indexOf('qr_code') < 0) { throw new Error('native-no-qr'); }
      var detector = new BarcodeDetector({ formats: formats });
      return navigator.mediaDevices.getUserMedia({ audio: false, video: videoConstraints(opts.facing) }).then(function (s) {
        stream = s;
        video.srcObject = s;
        return video.play().then(function () {
          var track = s.getVideoTracks()[0];
          var caps = tuneTrack(track);

          var busy = false;
          function tick() {
            if (stopped) { return; }
            if (!busy && video.readyState >= 2) {
              busy = true;
              detector.detect(video).then(function (codes) {
                busy = false;
                if (codes && codes.length) {
                  // ถ้าเจอหลายอันในภาพ เอาอันที่ใหญ่สุด (น่าจะเป็นอันที่เล็งอยู่)
                  codes.sort(function (a, b) {
                    return (b.boundingBox.width * b.boundingBox.height) - (a.boundingBox.width * a.boundingBox.height);
                  });
                  opts.onCode(String(codes[0].rawValue || ''));
                }
              }).catch(function () { busy = false; });
            }
            setTimeout(tick, 80);
          }
          tick();

          var ctl = controllerFor(track, caps, function () {
            stopped = true;
            try { s.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {}
            if (video.parentNode) { video.parentNode.removeChild(video); }
            return Promise.resolve();
          }, 'native');
          ctl.formats = formats;
          return ctl;
        });
      });
    }).catch(function (e) {
      stopped = true;
      if (stream) { try { stream.getTracks().forEach(function (t) { t.stop(); }); } catch (x) {} }
      if (video.parentNode) { video.parentNode.removeChild(video); }
      throw e;
    });
  }

  // ── 2) สำรอง: html5-qrcode ตั้งค่าให้อ่านง่ายขึ้น ──────────────────────────
  function startFallback(opts) {
    if (typeof Html5Qrcode === 'undefined') {
      return Promise.reject(new Error('โหลดตัวสแกนไม่ได้'));
    }
    var holder = document.createElement('div');
    holder.id = 'fs-reader-' + Date.now();
    holder.className = 'fs-reader' + (opts.facing === 'user' ? ' fs-mirror' : '');
    opts.stage.appendChild(holder);

    var F = window.Html5QrcodeSupportedFormats || {};
    var formats = ['QR_CODE', 'CODE_128', 'CODE_39', 'EAN_13', 'EAN_8', 'DATA_MATRIX', 'UPC_A', 'ITF']
      .map(function (k) { return F[k]; })
      .filter(function (v) { return v !== undefined; });

    var scanner = new Html5Qrcode(holder.id, {
      formatsToSupport: formats.length ? formats : undefined,
      // ถ้าเบราว์เซอร์มีตัวอ่านของระบบ ให้ html5-qrcode ใช้ตัวนั้นแทน JavaScript
      experimentalFeatures: { useBarCodeDetectorIfSupported: true },
      verbose: false
    });
    var config = {
      fps: 15,
      // กรอบอ่านกว้างเกือบเต็ม — กรอบเล็กทำให้ต้องเล็งเป๊ะ ซึ่งเป็นเหตุที่ "อ่านยาก"
      qrbox: function (w, h) {
        return { width: Math.floor(w * 0.92), height: Math.floor(h * 0.92) };
      },
      disableFlip: false,
      videoConstraints: videoConstraints(opts.facing)
    };
    return scanner.start({ facingMode: opts.facing === 'user' ? 'user' : 'environment' }, config, function (text) {
      opts.onCode(String(text || ''));
    }, function () {}).then(function () {
      var caps = {};
      try { caps = scanner.getRunningTrackCapabilities() || {}; } catch (e) {}
      return {
        engine: 'fallback',
        canTorch: !!caps.torch,
        zoom: caps.zoom ? { min: caps.zoom.min, max: caps.zoom.max, step: caps.zoom.step || 0.1 } : null,
        setTorch: function (on) { return scanner.applyVideoConstraints({ advanced: [{ torch: !!on }] }); },
        setZoom: function (z) { return scanner.applyVideoConstraints({ advanced: [{ zoom: Number(z) }] }); },
        stop: function () {
          return scanner.stop().catch(function () {}).then(function () {
            if (holder.parentNode) { holder.parentNode.removeChild(holder); }
          });
        }
      };
    });
  }

  window.FastScan = {
    start: function (opts) {
      var status = opts.onStatus || function () {};
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        return Promise.reject(new Error('เบราว์เซอร์นี้เปิดกล้องไม่ได้ (ต้องเปิดผ่าน https)'));
      }
      if ('BarcodeDetector' in window) {
        status('กำลังเปิดกล้อง (โหมดเร็ว)…');
        return startNative(opts).catch(function () {
          status('กำลังเปิดกล้อง…');
          return startFallback(opts);
        });
      }
      status('กำลังเปิดกล้อง…');
      return startFallback(opts);
    }
  };
})();
