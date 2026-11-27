<?php
if (!defined('ABSPATH')) {
    exit;
}
$rh_wa    = ranshosting_wa_number();
?>
<div class="rh-dock" role="toolbar" aria-label="Page tools">
  <button type="button" class="rh-icon-btn" id="rh-theme-toggle" aria-label="Switch between light and dark theme" title="Switch theme (t)">
    <svg class="rh-i rh-i-moon" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 14.5A8 8 0 0 1 9.5 4 8 8 0 1 0 20 14.5Z"/></svg>
    <svg class="rh-i rh-i-sun" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
  </button>
  <button type="button" class="rh-icon-btn rh-backtop" id="rh-backtop" aria-label="Back to top" title="Back to top" hidden>
    <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
  </button>
</div>

<div class="rh-wa-widget">
  <div class="rh-wa-box" id="rh-wa-box" role="dialog" aria-label="WhatsApp support">
    <div class="rh-wa-head">
      <img class="rh-wa-avatar" src="<?php echo esc_url(get_theme_file_uri('assets/ratul-bin.jpg')); ?>" alt="Ratul Bin, founder" width="52" height="52" loading="lazy" decoding="async" />
      <div class="rh-wa-heading">
        <strong>RansHosting support</strong>
        <span>Ratul Bin · Founder</span>
      </div>
      <button type="button" class="rh-wa-close" onclick="rhToggleWa(false)" aria-label="Close">✕</button>
    </div>
    <div class="rh-wa-body">
      <p class="rh-wa-intro">Need help with your hosting or domain? Send us a message on WhatsApp.</p>
      <label for="rh-wa-msg">Your message</label>
      <textarea id="rh-wa-msg" rows="3">Hello RansHosting support, I need help with my hosting/domain service.</textarea>
      <button type="button" class="rh-btn rh-btn-wa rh-btn-block" onclick="rhSendWa()">Continue to WhatsApp</button>
    </div>
  </div>
  <button type="button" class="rh-wa-float" id="rh-wa-toggle" aria-controls="rh-wa-box" aria-expanded="false" onclick="rhToggleWa(undefined, this)" aria-label="WhatsApp support">
    <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/></svg>
    <span>WhatsApp support</span>
  </button>
</div>

<script>
var rhWaOpener = null;
function rhToggleWa(force, opener) {
  var box = document.getElementById('rh-wa-box');
  var toggle = document.getElementById('rh-wa-toggle');
  var wasOpen = box.classList.contains('open');
  var open = typeof force === 'boolean' ? force : !wasOpen;
  if (open === wasOpen) return;

  box.classList.toggle('open', open);
  toggle.setAttribute('aria-expanded', String(open));
  if (open) {
    rhWaOpener = opener || document.activeElement;
    document.getElementById('rh-wa-msg').focus();
  } else {
    (rhWaOpener && rhWaOpener.isConnected ? rhWaOpener : toggle).focus();
    rhWaOpener = null;
  }
}
document.querySelector('.rh-wa-widget').addEventListener('keydown', function (event) {
  if (event.key === 'Escape' && document.getElementById('rh-wa-box').classList.contains('open')) {
    event.stopPropagation();
    rhToggleWa(false);
  }
});
function rhSendWa() {
  var msg = encodeURIComponent(document.getElementById('rh-wa-msg').value || '');
  window.open('https://wa.me/<?php echo esc_js($rh_wa); ?>?text=' + msg, '_blank', 'noopener');
}
(function () {
  var root = document.documentElement;
  var tb = document.getElementById('rh-theme-toggle');
  if (tb) tb.addEventListener('click', function () {
    var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('rh-theme', next); } catch (e) {}
  });
  var bt = document.getElementById('rh-backtop');
  if (bt) {
    var onScroll = function () { bt.hidden = window.scrollY < 700; };
    window.addEventListener('scroll', onScroll, { passive: true }); onScroll();
    bt.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    });
  }
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
