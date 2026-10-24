/** 大转盘绘制与旋转动画（结果由后台预设，转盘仅作动画展示） */
(function (global) {
  let rotation = 0;       // 当前旋转角（弧度，顺时针）
  let spinning = false;

  function drawWheel(prizes, rot) {
    const canvas = document.getElementById('wheel');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    const W = canvas.width, H = canvas.height;
    const cx = W / 2, cy = H / 2;
    const r = Math.min(cx, cy) - 12;
    const n = prizes.length;
    const seg = (Math.PI * 2) / n;

    ctx.clearRect(0, 0, W, H);
    ctx.save();
    ctx.translate(cx, cy);
    ctx.rotate(rot);

    for (let i = 0; i < n; i++) {
      const start = i * seg - Math.PI / 2; // 从第1段从顶部开始
      const end = start + seg;
      ctx.beginPath();
      ctx.moveTo(0, 0);
      ctx.arc(0, 0, r, start, end);
      ctx.closePath();
      ctx.fillStyle = prizes[i].color || '#f0a500';
      ctx.fill();
      ctx.lineWidth = 2;
      ctx.strokeStyle = 'rgba(255,255,255,.25)';
      ctx.stroke();

      // 文字
      ctx.save();
      ctx.rotate(start + seg / 2);
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillStyle = '#fff';
      ctx.font = 'bold ' + Math.max(11, Math.min(16, 220 / n)) + 'px sans-serif';
      let label = prizes[i].level || ('奖项' + (i + 1));
      if (prizes[i].name) label += ' ' + prizes[i].name;
      ctx.fillText(label, r - 12, 0);
      ctx.restore();
    }
    ctx.restore();

    // 中心圆
    ctx.beginPath();
    ctx.arc(cx, cy, 44, 0, Math.PI * 2);
    ctx.fillStyle = '#160c2a';
    ctx.fill();
    ctx.lineWidth = 3;
    ctx.strokeStyle = '#fff';
    ctx.stroke();
  }

  function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

  /** 旋转到指定奖项索引（使其停在顶部指针处） */
  function spinTo(index, prizes, duration, onDone) {
    if (spinning) return;
    spinning = true;
    const n = prizes.length;
    const seg = (Math.PI * 2) / n;
    // 目标：让 index 段中心停在顶部（-PI/2）
    let target = -(index + 0.5) * seg;
    const spins = 5;
    // 确保 target 大于当前旋转且差距至少为 spins 圈
    while (target <= rotation + Math.PI * 2 * (spins - 1)) {
      target += Math.PI * 2;
    }
    target += Math.PI * 2 * 0; // 已在循环中保证
    const startRot = rotation;
    const delta = target - startRot;
    const t0 = performance.now();

    function frame(now) {
      const p = Math.min(1, (now - t0) / duration);
      rotation = startRot + delta * easeOutCubic(p);
      drawWheel(prizes, rotation);
      if (p < 1) {
        requestAnimationFrame(frame);
      } else {
        spinning = false;
        if (typeof onDone === 'function') onDone();
      }
    }
    requestAnimationFrame(frame);
  }

  function isSpinning() { return spinning; }

  global.Wheel = { drawWheel, spinTo, isSpinning, getRotation: () => rotation };
})(window);
