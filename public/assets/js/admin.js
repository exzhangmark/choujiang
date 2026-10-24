/* 后台交互：动态生成“每次抽奖预设奖品”行、复制链接 */
function buildDrawRows() {
  const count = parseInt(document.getElementById('drawsCount').value, 10) || 1;
  const box = document.getElementById('drawRows');
  const opts = window.__PRIZE_OPTIONS__ || '';
  let html = '';
  for (let i = 0; i < count; i++) {
    html += '<div class="draw-row">' +
      '<label>第 ' + (i + 1) + ' 次抽中</label>' +
      '<select class="input" name="draws[]" style="flex:1">' + opts + '</select>' +
      '</div>';
  }
  box.innerHTML = html;
}

function copyGen() {
  const el = document.getElementById('genUrl');
  el.select();
  document.execCommand('copy');
  const btn = el.nextElementSibling;
  const old = btn.textContent;
  btn.textContent = '已复制';
  setTimeout(function () { btn.textContent = old; }, 1500);
}

function copyText(btn) {
  const input = btn.previousElementSibling;
  input.select();
  document.execCommand('copy');
  const old = btn.textContent;
  btn.textContent = '已复制';
  setTimeout(function () { btn.textContent = old; }, 1500);
}

document.addEventListener('DOMContentLoaded', function () {
  if (document.getElementById('drawRows')) buildDrawRows();

  var btn = document.getElementById('btnUpdate');
  if (btn) {
    btn.addEventListener('click', function () {
      if (!confirm('确认从远程仓库拉取最新代码并更新服务器？')) return;
      btn.disabled = true;
      document.getElementById('updateStatus').textContent = '更新中，请稍候…';
      document.getElementById('updateLog').textContent = '';
      fetch('/admin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update'
      })
        .then(function () { pollUpdateLog(); })
        .catch(function () {
          document.getElementById('updateStatus').textContent = '请求失败，请重试';
          btn.disabled = false;
        });
    });
  }
});

var _updateTimer = null;
function pollUpdateLog() {
  clearInterval(_updateTimer);
  _updateTimer = setInterval(function () {
    fetch('/admin?view=update_log')
      .then(function (r) { return r.text(); })
      .then(function (text) {
        var el = document.getElementById('updateLog');
        if (el) el.textContent = text;
        if (text.indexOf('[update] done') !== -1 || text.indexOf('[update] failed') !== -1) {
          clearInterval(_updateTimer);
          document.getElementById('updateStatus').textContent = '更新完成，服务正在重启，请刷新页面查看。';
          var b = document.getElementById('btnUpdate');
          if (b) b.disabled = false;
        }
      })
      .catch(function () { /* 重启窗口期轮询失败可忽略，下次继续 */ });
  }, 2000);
}
