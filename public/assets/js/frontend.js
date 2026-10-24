/* 前端抽奖逻辑：转盘仅作动画，结果由后台链接预设；无机会时进入试玩模式 */
(function () {
  const L = window.__LOTTERY__;
  if (!L) return;

  const T = L.texts || {};
  const $ = (s) => document.querySelector(s);
  let localUsed = L.used_draws || 0;

  // 渲染链接标题（后台设置的链接备注/标题）
  if (L.title && $('#linkTitle')) $('#linkTitle').textContent = L.title;

  // 绘制转盘
  Wheel.drawWheel(L.prizes, 0);

  function renderLeft() {
    const el = $('#drawsLeft');
    if (!el) return;
    if (L.trial) {
      el.innerHTML = '<span class="trial-tip">🎃 ' + escapeHtml(T.trial_badge || '试玩模式') + '：转盘可随意试转，结果仅为演示</span>';
      return;
    }
    const left = L.total_draws - localUsed;
    const tpl = T.draws_left || '剩余抽奖次数：{left} / 共 {total} 次';
    el.innerHTML = escapeHtml(tpl)
      .replace('{left}', '<b>' + left + '</b>')
      .replace('{total}', L.total_draws);
    const btn = $('#spinBtn');
    if (left <= 0) {
      btn.disabled = true;
      if (L.total_draws > 0) btn.textContent = '已抽完';
    }
  }
  renderLeft();

  // 领取信息仅展示后台链接备注/标题，不再收集姓名/金额

  // 已抽记录（刷新后重放）
  (L.draws || []).forEach(function (d) {
    addPrizeToList(d.draw_index, d.prize, false);
  });

  function addPrizeToList(idx, prize, prepend) {
    const ul = $('#prizeList');
    if (!ul) return;
    const li = document.createElement('li');
    li.style.borderLeftColor = prize.color || '#8e2de2';
    let imgs = '';
    (prize.images || []).slice(0, 3).forEach(function (src) {
      imgs += '<img src="' + encodeURI(src) + '" alt="">';
    });
    li.innerHTML =
      '<div class="pi-idx">第 ' + (idx + 1) + ' 抽</div>' +
      (imgs || '') +
      '<div class="pi-meta">' +
        '<div class="pi-level">' + escapeHtml(prize.level || '奖项') + '</div>' +
        '<div class="pi-name">' + escapeHtml(prize.name || '') + '</div>' +
        '<div class="pi-value">' + escapeHtml(prize.value || '') + '</div>' +
      '</div>';
    if (prepend && ul.firstChild) ul.insertBefore(li, ul.firstChild);
    else ul.appendChild(li);
  }

  function prizeIndexById(id) {
    for (let i = 0; i < L.prizes.length; i++) {
      if (L.prizes[i].id === id) return i;
    }
    return 0;
  }

  // 试玩：大概率转到大奖
  function pickTrialIndex() {
    const n = L.prizes.length;
    if (n === 0) return 0;
    const r = Math.random();
    if (r < 0.45) return 0;                                   // 特等奖 ~45%
    if (r < 0.75 && n > 1) return 1;                         // 一等奖 ~30%
    return Math.floor(Math.random() * n);                    // 其余随机 ~25%
  }

  $('#spinBtn').addEventListener('click', function () {
    if (Wheel.isSpinning()) return;
    const btn = this;

    // 试玩模式：本地转盘动画，不发奖、不记录
    if (L.trial) {
      btn.disabled = true;
      const idx = pickTrialIndex();
      const prize = L.prizes[idx];
      Wheel.spinTo(idx, L.prizes, 4200, function () {
        showResult(prize, false, true);
        btn.disabled = false;
      });
      return;
    }

    const left = L.total_draws - localUsed;
    if (left <= 0) return;
    const idx = localUsed;
    btn.disabled = true;
    btn.textContent = T.spinning_text || '抽奖中…';
    fetch('/api/draw', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(L.token) + '&draw_index=' + idx
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.ok) {
          alert(res.msg || '抽奖失败');
          btn.disabled = false;
          btn.textContent = T.spin_button || '抽奖';
          return;
        }
        const prize = res.data.prize;
        const targetIndex = prizeIndexById(prize.id);
        Wheel.spinTo(targetIndex, L.prizes, 4200, function () {
          showResult(prize, res.data.exhausted, false);
        });
      })
      .catch(function () {
        alert('网络错误，请重试');
        btn.disabled = false;
        btn.textContent = T.spin_button || '抽奖';
      });
  });

  function showResult(prize, exhausted, isTrial) {
    $('#resBadge').textContent = '🎉';
    $('#resLevel').textContent = prize.level || '恭喜中奖';
    $('#resName').textContent = prize.name || '';
    $('#resValue').textContent = prize.value || '';
    if (isTrial) {
      $('#resNote').textContent = T.trial_note || '这是试玩演示，实际奖品以您的专属抽奖链接为准 🎃';
    } else {
      $('#resNote').textContent = exhausted ? '您预设的奖品已被抽完，系统已为您发放以上奖品 🎁' : '';
    }
    const box = $('#resImages');
    box.innerHTML = '';
    (prize.images || []).forEach(function (src) {
      const im = document.createElement('img');
      im.src = src;
      box.appendChild(im);
    });
    const mask = $('#resultMask');
    mask.classList.add('show');
    if (!isTrial) {
      addPrizeToList(localUsed, prize, false);
      localUsed++;
      renderLeft();
      const btn = $('#spinBtn');
      if (L.total_draws - localUsed <= 0) { btn.disabled = true; btn.textContent = '已抽完'; }
      else { btn.disabled = false; btn.textContent = '再抽一次'; }
    }
  }

  $('#resClose').addEventListener('click', function () {
    $('#resultMask').classList.remove('show');
  });

  // 奖品详情弹窗
  $('#prizeDetailBtn').addEventListener('click', function () {
    const list = $('#detailList');
    if (list) list.innerHTML = '';
    (L.prizes || []).forEach(function (p) {
      const card = document.createElement('div');
      card.className = 'detail-item';
      let imgs = '';
      (p.images || []).forEach(function (src) {
        imgs += '<img src="' + encodeURI(src) + '" alt="">';
      });
      card.innerHTML =
        '<div class="di-head" style="border-color:' + (p.color || '#f0a500') + '">' +
          '<span class="di-level">' + escapeHtml(p.level || '') + '</span>' +
          '<span class="di-name">' + escapeHtml(p.name || '') + '</span>' +
          '<span class="di-value">' + escapeHtml(p.value || '') + '</span>' +
        '</div>' +
        (imgs ? '<div class="di-imgs">' + imgs + '</div>' : '') +
        (p.desc ? '<p class="di-desc">' + escapeHtml(p.desc) + '</p>' : '');
      if (list) list.appendChild(card);
    });
    const mask = $('#detailMask');
    if (mask) mask.classList.add('show');
  });
  $('#detailClose').addEventListener('click', function () {
    $('#detailMask').classList.remove('show');
  });

  // 领取信息不再需要保存（仅展示后台链接备注/标题），已移除输入框与保存按钮

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
