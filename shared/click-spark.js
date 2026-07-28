/**
 * click-spark.js — เอฟเฟกต์ spark ตอนคลิก (ยังไม่เปิดใช้งานอัตโนมัติ)
 *
 * วัตถุประสงค์: canvas overlay วาด spark ชั่วคราว — loop หยุดเมื่อไม่มี spark ค้าง
 */
(function () {
  'use strict';

  var canvas = null;
  var ctx = null;
  var sparks = [];
  var animationId = 0;
  var loopRunning = false;

  /**
   * วาด spark ทุกเฟรม — หยุด loop เมื่อ sparks ว่าง
   *
   * @return {void}
   */
  function draw() {
    if (!ctx || !canvas) {
      loopRunning = false;
      animationId = 0;
      return;
    }

    ctx.clearRect(0, 0, canvas.width, canvas.height);

    for (var i = sparks.length - 1; i >= 0; i--) {
      var s = sparks[i];
      s.x += s.vx;
      s.y += s.vy;
      s.life -= 1;
      if (s.life <= 0) {
        sparks.splice(i, 1);
        continue;
      }
      ctx.globalAlpha = Math.max(0, s.life / s.maxLife);
      ctx.fillStyle = s.color;
      ctx.beginPath();
      ctx.arc(s.x, s.y, s.size, 0, Math.PI * 2);
      ctx.fill();
    }

    ctx.globalAlpha = 1;

    if (sparks.length === 0) {
      loopRunning = false;
      animationId = 0;
      return;
    }

    animationId = requestAnimationFrame(draw);
  }

  /**
   * เริ่ม animation loop ถ้ายังไม่ทำงาน
   *
   * @return {void}
   */
  function ensureLoop() {
    if (loopRunning) {
      return;
    }
    loopRunning = true;
    animationId = requestAnimationFrame(draw);
  }

  /**
   * สร้าง canvas overlay ครั้งเดียวต่อหน้า
   *
   * @return {void}
   */
  function ensureCanvas() {
    if (canvas) {
      return;
    }
    canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:9999;';
    document.body.appendChild(canvas);
    ctx = canvas.getContext('2d');
    resizeCanvas();
    window.addEventListener('resize', resizeCanvas);
  }

  /**
   * ปรับขนาด canvas ตาม viewport
   *
   * @return {void}
   */
  function resizeCanvas() {
    if (!canvas) {
      return;
    }
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
  }

  /**
   * เพิ่ม spark จากจุดคลิก
   *
   * @param {MouseEvent} e
   * @return {void}
   */
  function addSparks(e) {
    ensureCanvas();
    var color = getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '#e11d74';
    for (var i = 0; i < 8; i++) {
      var angle = Math.random() * Math.PI * 2;
      var speed = 1 + Math.random() * 2.5;
      sparks.push({
        x: e.clientX,
        y: e.clientY,
        vx: Math.cos(angle) * speed,
        vy: Math.sin(angle) * speed,
        size: 2 + Math.random() * 2,
        life: 18 + Math.floor(Math.random() * 10),
        maxLife: 28,
        color: color,
      });
    }
    ensureLoop();
  }

  window.clickSparkInit = function (selector) {
    document.addEventListener('click', function (e) {
      if (selector && !e.target.closest(selector)) {
        return;
      }
      addSparks(e);
    });
  };
})();
