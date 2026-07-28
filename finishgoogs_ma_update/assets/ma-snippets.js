/**
 * ma-snippets.js — เติมข้อความประจำสินค้า + คัดลอก + popup บน timeline
 */
(function () {
  function normItem(s) {
    return (s == null ? '' : String(s)).trim();
  }

  function assetCodeToMac(code) {
    code = normItem(code);
    if (!code) return '';
    var digits = code.replace(/\D/g, '');
    if (!digits) return '';
    if (digits.length > 8) digits = digits.slice(-8);
    while (digits.length < 8) digits = '0' + digits;
    return digits.slice(0, 2) + ':' + digits.slice(2, 4) + ':' + digits.slice(4, 6) + ':' + digits.slice(6, 8);
  }

  function buildRentalSummaryFromData(rep, fix, fw, remark) {
    rep = normItem(rep);
    fix = normItem(fix);
    fw = normItem(fw);
    remark = normItem(remark);
    var lines = ['✅ ใช้งานได้ปกติ เช็ดทำความสะอาด'];
    if (rep) lines.push('✨ เปลี่ยน : ' + rep);
    if (fix) lines.push('🛠️ แก้ไข : ' + fix);
    lines.push('🛜 FW : ' + fw);
    lines.push('📝 Note : ' + remark);
    return lines.join('\n');
  }

  window.maFillSnippets = function (prefix, data) {
    data = data || {};
    var code = normItem(data.code);
    var serialEl = document.getElementById(prefix + '-serial');
    var macAddrEl = document.getElementById(prefix + '-macaddr');
    var rentalEl = document.getElementById(prefix + '-rental');
    if (serialEl) {
      serialEl.value = code
        ? 'sudo /opt/RTC_SDL_DS3231/srwSerial.py w ' + code
        : '(กรอกหมายเลขสินค้าก่อน)';
    }
    if (macAddrEl) {
      macAddrEl.value = code ? assetCodeToMac(code) : '(กรอกหมายเลขสินค้าก่อน)';
    }
    if (rentalEl) {
      rentalEl.value = buildRentalSummaryFromData(data.replace, data.repair, data.fw, data.remark);
    }
  };

  window.resetMaCopyButtonsInScope = function (prefix) {
    var anchor = document.getElementById(prefix + '-serial');
    if (!anchor) return;
    var box = anchor.closest('.notif-box, .ma-snippets-panel, .ma-detail-section');
    if (!box) return;
    box.querySelectorAll('.ma-copy-btn').forEach(function (btn) {
      if (btn.dataset.copyOrig) btn.innerHTML = btn.dataset.copyOrig;
    });
  };

  window.copyMaText = function (btn) {
    var el = document.getElementById(btn.dataset.target);
    if (!el) return;
    var text = el.value != null ? el.value : el.textContent;

    function done(ok) {
      if (!ok) {
        alert('คัดลอกไม่สำเร็จ');
        return;
      }
      if (!btn.dataset.copyOrig) btn.dataset.copyOrig = btn.innerHTML;
      btn.textContent = 'คัดลอกแล้ว';
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function () { done(true); }).catch(function () { done(false); });
    } else {
      el.focus();
      el.select();
      try {
        done(document.execCommand('copy'));
      } catch (e) {
        done(false);
      }
    }
  };

  window.assetOpenSnippetModal = function (btn) {
    if (!btn || !btn.dataset) return;
    resetMaCopyButtonsInScope('asset-tl-sn');
    var d = btn.dataset;
    maFillSnippets('asset-tl-sn', {
      code: d.code || '',
      replace: d.replace || '',
      repair: d.repair || '',
      fw: d.fw || '',
      remark: d.remark || ''
    });
    var overlay = document.getElementById('asset-snippet-overlay');
    if (overlay) overlay.hidden = false;
  };

  document.addEventListener('click', function (e) {
    var copyBtn = e.target.closest('.ma-copy-btn');
    if (copyBtn) {
      copyMaText(copyBtn);
      return;
    }
    var openBtn = e.target.closest('.asset-snippet-open');
    if (openBtn) {
      assetOpenSnippetModal(openBtn);
      return;
    }
    var overlay = document.getElementById('asset-snippet-overlay');
    if (overlay && !overlay.hidden && e.target === overlay) {
      closeOverlay('asset-snippet-overlay');
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var overlay = document.getElementById('asset-snippet-overlay');
    if (overlay && !overlay.hidden) closeOverlay('asset-snippet-overlay');
  });
})();
