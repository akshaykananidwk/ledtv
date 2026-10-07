/* Countdown (#17) — ticks every second with the server-corrected clock (HC.now). ES5 only. */
(function (w, d) {
  'use strict';
  var timer = null;
  function tick(root) {
    var target = parseFloat(root.getAttribute('data-target')) || 0;
    var left = Math.max(0, Math.floor((target - HC.now()) / 1000));
    var v = { d: Math.floor(left / 86400), h: Math.floor((left % 86400) / 3600), m: Math.floor((left % 3600) / 60), s: left % 60 };
    var nums = root.querySelectorAll('[data-cd]');
    for (var i = 0; i < nums.length; i++) {
      var k = nums[i].getAttribute('data-cd');
      nums[i].textContent = k === 'd' ? String(v.d) : HC.pad(v[k]);
    }
    var boxes = root.querySelector('.cd-boxes'), done = root.querySelector('.cd-done');
    if (boxes) { boxes.style.display = left > 0 ? '' : 'none'; }
    if (done) { done.style.display = left > 0 ? 'none' : ''; }
  }
  w.HCApp = {
    init: function () {
      var root = d.getElementById('cdRoot');
      if (!root) { return; }
      tick(root);
      if (timer) { clearInterval(timer); }
      timer = setInterval(function () { tick(root); }, 1000);
    }
  };
})(window, document);
