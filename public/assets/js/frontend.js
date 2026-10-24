/* 前端抽奖逻辑：转盘仅作动画，结果由后台链接预设 */
(function () {
  const L = window.__LOTTERY__;
  if (!L) return;

  const $ = (s) => document.querySelector(s);
  let localUsed = L.used_draws || 0;

  // 渲染链接标题 / 剩余次数
  if (L.title) $('#linkTitle').textContent = '链接：' + L.title;
  function renderLeft() {
    const left = L.total_draws - localUsed;
    $('#drawsLeft').innerHTML = '剩余抽奖次数：<b>' + left + '</b> / 共 ' + L.total_draws + ' 次';
    const btn = $('#spinBtn');
    if (left <= 0) {
      btn.disabled = true;
      if (L.total_draws > 0) btn.textContent = '已抽完';
    }
  }

  // 绘制转盘
  Wheel.drawWheel(L.prizes, 0);
  renderLeft();

  // 预填客户信息
  if (L.customer_name) $('#custName').value = L.customer_name;
  if (L.customer_amount) $('#custAmount').value = L.customer_amount;

  // 已抽记录（刷新后重放）
  (L.draws || []).forEach(function (d) {
    addPrizeToList(d.draw_index, d.prize, false);
  });

  // 奖品栏渲染
  function addPrizeToList(idx, prize, prepend) {
    const ul = $('#prizeList');
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

  // 抽奖
  $('#spinBtn').addEventListener('click', function () {
    if (Wheel.isSpinning()) return;
    const left = L.total_draws - localUsed;
    if (left <= 0) return;
    const idx = localUsed; // 本次是第 idx 抽（0 起）
    const btn = this;
    btn.disabled = true;
    btn.textContent = '抽奖中…';

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
          btn.textContent = '抽奖';
          return;
        }
        const prize = res.data.prize;
        const targetIndex = prizeIndexById(prize.id);
        Wheel.spinTo(targetIndex, L.prizes, 4200, function () {
          showResult(prize, res.data.exhausted);
        });
      })
      .catch(function () {
        alert('网络错误，请重试');
        btn.disabled = false;
        btn.textContent = '抽奖';
      });
  });

  // 结果弹窗
  function showResult(prize, exhausted) {
    $('#resBadge').textContent = '🎉';
    $('#resLevel').textContent = prize.level || '恭喜中奖';
    $('#resName').textContent = prize.name || '';
    $('#resValue').textContent = prize.value || '';
    $('#resNote').textContent = exhausted ? '您预设的奖品已被抽完，系统已为您发放以上奖品 🎁' : '';
    const box = $('#resImages');
    box.innerHTML = '';
    (prize.images || []).forEach(function (src) {
      const im = document.createElement('img');
      im.src = src;
      box.appendChild(im);
    });
    const mask = $('#resultMask');
    mask.classList.add('show');
    // 记录到奖品栏
    addPrizeToList(localUsed, prize, false);
    localUsed++;
    renderLeft();
    const btn = $('#spinBtn');
    if (L.total_draws - localUsed <= 0) { btn.disabled = true; btn.textContent = '已抽完'; }
    else { btn.disabled = false; btn.textContent = '再抽一次'; }
  }

  $('#resClose').addEventListener('click', function () {
    $('#resultMask').classList.remove('show');
  });

  // 保存客户信息
  $('#saveCust').addEventListener('click', function () {
    const name = $('#custName').value.trim();
    const amount = $('#custAmount').value.trim();
    const msg = $('#custMsg');
    fetch('/api/customer', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(L.token) + '&name=' + encodeURIComponent(name) + '&amount=' + encodeURIComponent(amount)
    })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        msg.textContent = res.ok ? '✅ 信息已保存' : ('❌ ' + (res.msg || '保存失败'));
        msg.style.color = res.ok ? '#7CFC9B' : '#ff7676';
      })
      .catch(function () { msg.textContent = '❌ 网络错误'; msg.style.color = '#ff7676'; });
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
