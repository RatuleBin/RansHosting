<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();

$rh_logo = get_option('rh_logo_url', '');

/* ---------- Access control ---------- */
$is_editor       = current_user_can('edit_posts');
$access_mode     = get_option('rh_portal_access', 'open');
$selected_client = isset($_GET['client']) ? sanitize_text_field(wp_unslash($_GET['client'])) : '';
$given_key       = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
$key_ok          = ($selected_client !== '' && $given_key !== '' && hash_equals(ranshosting_client_key($selected_client), $given_key));
$can_view        = $is_editor || $access_mode === 'open' || $key_ok;
$show_creds      = $is_editor
    || get_option('rh_public_credentials', '0') === '1'
    || ($key_ok && get_option('rh_creds_on_key', '0') === '1');

if (!$can_view) {
    ?>
    <main id="rh-main">
      <div class="rh-container">
        <section class="rh-gate">
          <h1>This portal is private</h1>
          <p>Open the personal link we sent you to see your hosting, domains and invoices. If you can’t find it, message us and we’ll send it again.</p>
          <div class="rh-hero-actions">
            <a class="rh-btn rh-btn-primary" href="https://wa.me/<?php echo esc_attr(ranshosting_wa_number()); ?>?text=<?php echo rawurlencode('Hello RansHosting, could you resend my portal link?'); ?>" target="_blank" rel="noopener">Message support</a>
            <a class="rh-btn rh-btn-outline" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">Staff sign in</a>
          </div>
        </section>
      </div>
    </main>
    <?php
    get_footer();
    return;
}

/* ---------- Data ---------- */
$pay_instructions = get_option('rh_payment_instructions', 'bKash / Nagad: +8801575115472');
$types            = ranshosting_service_types();
$cycles           = ranshosting_billing_cycles();

$query_args = [
    'post_type'      => 'rans_hosting_service',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
];
if ($selected_client !== '') {
    $query_args['meta_query'] = [[
        'key'     => '_rh_owner',
        'value'   => $selected_client,
        'compare' => '=',
    ]];
}
$service_posts = get_posts($query_args);

$all_owners = [];
$owner_keys = [];
if ($selected_client === '') {
    $all_posts = get_posts(['post_type' => 'rans_hosting_service', 'posts_per_page' => -1, 'post_status' => 'publish']);
    foreach ($all_posts as $p) {
        $ow = ranshosting_get_meta($p->ID, 'owner');
        if ($ow !== '' && !in_array($ow, $all_owners, true)) {
            $all_owners[] = $ow;
        }
    }
    sort($all_owners);
    if ($is_editor) {
        foreach ($all_owners as $ow) { $owner_keys[$ow] = ranshosting_client_key($ow); }
    }
}

/* Editors get shortcuts per client (edit, add a service). */
$client_index = [];
if ($is_editor) {
    foreach (ranshosting_clients() as $c) {
        $client_index[$c->post_title] = [
            'id'   => $c->ID,
            'edit' => get_edit_post_link($c->ID, 'raw'),
            'add'  => admin_url('post-new.php?post_type=rans_hosting_service&rh_client=' . $c->ID),
        ];
    }
}
$add_service_url = admin_url('post-new.php?post_type=rans_hosting_service');
if ($selected_client !== '' && isset($client_index[$selected_client])) {
    $add_service_url = $client_index[$selected_client]['add'];
}
$add_client_url = admin_url('post-new.php?post_type=rans_client');

$today_ts = strtotime(wp_date('Y-m-d'));
$services = [];
foreach ($service_posts as $p) {
    // Every login on this service. Usernames, passwords and notes only leave the server when the viewer may see them.
    $logins_out = [];
    foreach (ranshosting_get_logins($p->ID) as $l) {
        $logins_out[] = [
            'label' => (string) ($l['label'] ?? ''),
            'url'   => (string) ($l['url'] ?? ''),
            'user'  => $show_creds ? (string) ($l['user'] ?? '') : '',
            'pass'  => $show_creds ? (string) ($l['pass'] ?? '') : '',
            'note'  => $show_creds ? (string) ($l['note'] ?? '') : '',
        ];
    }

    $end_date  = ranshosting_get_meta($p->ID, 'end_date');
    $days_left = $end_date ? (int) ceil((strtotime($end_date) - $today_ts) / DAY_IN_SECONDS) : 999;
    $type      = ranshosting_get_meta($p->ID, 'service_type', 'hosting');
    $cycle     = ranshosting_get_meta($p->ID, 'billing_cycle', 'yearly');

    $row = [
        'id'            => $p->ID,
        'domain'        => get_the_title($p->ID),
        'owner'         => ranshosting_get_meta($p->ID, 'owner'),
        'clientEmail'   => ranshosting_get_meta($p->ID, 'client_email'),
        'clientPhone'   => ranshosting_get_meta($p->ID, 'client_phone'),
        'type'          => $type,
        'typeLabel'     => $types[$type] ?? ucfirst($type),
        'plan'          => ranshosting_get_meta($p->ID, 'plan'),
        'cycleLabel'    => ($cycles[$cycle]['label'] ?? 'Yearly'),
        'notes'         => ranshosting_get_meta($p->ID, 'notes'),
        'emails'        => array_values(array_filter(array_map('trim', explode(',', (string) ranshosting_get_meta($p->ID, 'domain_emails'))))),
        'clientId'      => (int) ranshosting_get_meta($p->ID, 'client_id'),
        'logins'        => $logins_out,
        'startDate'     => ranshosting_get_meta($p->ID, 'start_date'),
        'endDate'       => $end_date,
        'daysLeft'      => $days_left,
        'domainExpiry'  => ranshosting_get_meta($p->ID, 'domain_expiry'),
        'sslStatus'     => ranshosting_get_meta($p->ID, 'ssl_status', 'active'),
        'serverIp'      => ranshosting_get_meta($p->ID, 'server_ip'),
        'registrar'     => ranshosting_get_meta($p->ID, 'registrar'),
        'nameservers'   => ranshosting_get_meta($p->ID, 'nameservers'),
        'cost'          => (float) ranshosting_get_meta($p->ID, 'cost', 0),
        'paid'          => (float) ranshosting_get_meta($p->ID, 'paid', 0),
        'currency'      => ranshosting_get_meta($p->ID, 'currency', '৳'),
        'paymentStatus' => ranshosting_get_meta($p->ID, 'payment_status', 'Unpaid'),
        'invoiceNo'     => ranshosting_get_meta($p->ID, 'invoice_no', 'INV-' . $p->ID),
        'paymentLink'   => ranshosting_get_meta($p->ID, 'payment_link'),
        'demo'          => (bool) ranshosting_get_meta($p->ID, 'demo'),
    ];
    if ($is_editor) {
        $row['editUrl']  = get_edit_post_link($p->ID, 'raw');
        $row['paidUrl']  = wp_nonce_url(admin_url('admin-post.php?action=rh_row_action&do=paid&from=portal&id=' . $p->ID), 'rh_row_paid_' . $p->ID);
        $row['renewUrl'] = wp_nonce_url(admin_url('admin-post.php?action=rh_row_action&do=renew&from=portal&id=' . $p->ID), 'rh_row_renew_' . $p->ID);
    }
    $services[] = $row;
}

$config = [
    'data'        => $services,
    'payInst'     => $pay_instructions,
    'home'        => home_url('/'),
    'editor'      => $is_editor,
    'accessMode'  => $access_mode,
    'ownerKeys'   => (object) $owner_keys,
    'types'       => $types,
    'contact'     => ranshosting_contact_email(),
    'waDisplay'   => ranshosting_wa_display(),
    'client'      => $selected_client,
    'demoUrl'     => $is_editor ? wp_nonce_url(admin_url('admin-post.php?action=rh_load_demo'), 'rh_load_demo') : '',
    'addUrl'      => $is_editor ? $add_service_url : '',
    'addClientUrl'=> $is_editor ? $add_client_url : '',
    'clients'     => (object) $client_index,
    'showCreds'   => (bool) $show_creds,
];
?>

<main id="rh-main">
<div class="rh-container">

  <section class="rh-hero">
    <div class="rh-hero-copy">
      <div class="rh-brandline">
        <?php if ($rh_logo): ?>
          <img class="rh-brand-logo" src="<?php echo esc_url($rh_logo); ?>" alt="RansTech Digital" />
        <?php else: ?>
          <span class="rh-brand-badge" aria-hidden="true">R</span>
          <span class="rh-brand-text"><span class="rh-brand-name">RansHosting</span><span class="rh-brand-by">by RansTech Digital</span></span>
        <?php endif; ?>
      </div>
      <h1>
        <?php if ($selected_client !== ''): ?>
          Hello, <span class="rh-grad"><?php echo esc_html($selected_client); ?></span>
        <?php else: ?>
          Hosting, domains and email, <span class="rh-grad">renewed on time</span>
        <?php endif; ?>
      </h1>
      <p class="rh-lede">
        <?php if ($selected_client !== ''): ?>
          Your services, renewal dates, invoices and logins in one place. Need a change? Message us and we’ll sort it out.
        <?php else: ?>
          Every client’s term, technical health, mailboxes, access and invoices. Reminders go out from 45 days to 24 hours before a term ends.
        <?php endif; ?>
      </p>
    </div>
    <div class="rh-hero-actions">
      <button type="button" class="rh-btn rh-btn-primary" onclick="rhToggleWa(true)">Talk to support</button>
      <a class="rh-btn rh-btn-outline" href="https://calendly.com/ranstechdigital/est" target="_blank" rel="noopener">Book a meeting</a>
      <?php if ($selected_client !== '' && $is_editor): ?>
        <a class="rh-btn rh-btn-ghost" href="<?php echo esc_url(home_url('/')); ?>">All clients</a>
      <?php endif; ?>
      <?php if (!$is_editor): ?>
        <a class="rh-btn rh-btn-ghost" href="<?php echo esc_url(wp_login_url(home_url('/'))); ?>">Staff sign in</a>
      <?php endif; ?>
    </div>
  </section>

  <section class="rh-stats" id="rh-stats" aria-label="Summary"></section>

  <section class="rh-attn" id="rh-attn" aria-label="Needs attention" hidden></section>

  <section class="rh-timeline" id="rh-timeline" aria-label="Upcoming renewals"></section>

  <?php if (!$show_creds): ?>
    <div class="rh-notice">
      <?php echo $key_ok ? 'Logins are kept private on this page. Message support and we’ll share yours securely.' : 'Usernames and passwords are hidden. Sign in as an administrator or editor to view them.'; ?>
    </div>
  <?php endif; ?>

  <div class="rh-toolbar" id="rh-toolbar">
    <section class="rh-controls" aria-label="Filters">
      <label class="rh-search">
        <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" id="rh-search" placeholder="Search domain, client, email, IP" aria-label="Search services" />
        <kbd aria-hidden="true" id="rh-search-kbd">/</kbd>
        <button type="button" class="rh-search-clear" data-act="clear-search" aria-label="Clear search" hidden>
          <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
      </label>
      <?php if ($selected_client === ''): ?>
        <select id="rh-owner-filter" aria-label="Client">
          <option value="all">All clients</option>
          <?php foreach ($all_owners as $ow): ?>
            <option value="<?php echo esc_attr($ow); ?>"><?php echo esc_html($ow); ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select id="rh-type-filter" aria-label="Service type">
        <option value="all">All service types</option>
        <?php foreach ($types as $k => $label): ?>
          <option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <select id="rh-sort" aria-label="Sort by">
        <option value="expiry">Soonest renewal first</option>
        <option value="expiry-desc">Latest renewal first</option>
        <option value="name">Domain A to Z</option>
        <option value="owner">Client A to Z</option>
        <option value="amount">Highest balance first</option>
      </select>
    </section>

    <div class="rh-subbar">
      <div class="rh-tabs" role="group" aria-label="Status">
        <button type="button" class="rh-tab" data-tab="all" aria-pressed="true">All <span class="rh-count" data-count="all">0</span></button>
        <button type="button" class="rh-tab" data-tab="active" aria-pressed="false">Active <span class="rh-count" data-count="active">0</span></button>
        <button type="button" class="rh-tab" data-tab="expiring" aria-pressed="false">Expiring soon <span class="rh-count" data-count="expiring">0</span></button>
        <button type="button" class="rh-tab" data-tab="expired" aria-pressed="false">Expired <span class="rh-count" data-count="expired">0</span></button>
        <button type="button" class="rh-tab" data-tab="unpaid" aria-pressed="false">Unpaid <span class="rh-count" data-count="unpaid">0</span></button>
      </div>
      <div class="rh-subbar-actions">
        <div class="rh-seg" role="group" aria-label="Layout">
          <button type="button" data-view="comfortable" aria-pressed="true" title="Comfortable layout (v)" aria-label="Comfortable layout">
            <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/></svg>
          </button>
          <button type="button" data-view="compact" aria-pressed="false" title="Compact layout (v)" aria-label="Compact layout">
            <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
          </button>
        </div>
        <?php if ($selected_client === ''): ?>
          <button type="button" class="rh-btn rh-btn-ghost rh-btn-sm" data-act="toggle-group" id="rh-toggle-group" aria-pressed="true" title="Group services under each client (g)">Group by client</button>
        <?php endif; ?>
        <button type="button" class="rh-btn rh-btn-ghost rh-btn-sm" data-act="toggle-all" id="rh-toggle-all" title="Expand or collapse all (e)">Expand all</button>
        <details class="rh-more">
          <summary class="rh-btn rh-btn-ghost rh-btn-sm">Share &amp; export
            <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
          </summary>
          <div class="rh-more-menu">
            <button type="button" data-act="export-ics"><svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg> Add renewals to calendar (.ics)</button>
            <button type="button" data-act="print-report"><svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V3h10v6"/><rect x="4" y="9" width="16" height="8" rx="2"/><path d="M7 14h10v7H7Z"/></svg> Print report</button>
            <?php if ($is_editor): ?>
              <button type="button" data-act="export-csv"><svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11"/><path d="m7 11 5 5 5-5"/><path d="M5 20h14"/></svg> Export CSV</button>
              <?php if ($selected_client === ''): ?>
                <button type="button" data-act="client-link"><svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/></svg> Copy client link</button>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </details>
        <button type="button" class="rh-icon-btn rh-icon-btn-sm" data-act="help" aria-label="Keyboard shortcuts" title="Keyboard shortcuts (?)">
          <svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 .9-1 1.7M12 17h.01"/></svg>
        </button>
        <?php if ($is_editor): ?>
          <a href="<?php echo esc_url($add_service_url); ?>" class="rh-btn rh-btn-primary rh-btn-sm">Add service</a>
          <a href="<?php echo esc_url($add_client_url); ?>" class="rh-btn rh-btn-outline rh-btn-sm">Add client</a>
          <a href="<?php echo esc_url(admin_url('edit.php?post_type=rans_hosting_service')); ?>" class="rh-btn rh-btn-outline rh-btn-sm">Backend</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <section class="rh-list" id="rh-list" aria-live="polite"></section>
</div>
</main>

<!-- Invoice modal -->
<div class="rh-modal-backdrop" id="rh-invoice-modal" role="dialog" aria-modal="true" aria-label="Invoice">
  <div class="rh-modal">
    <div id="rh-invoice-print" class="rh-invoice-paper"></div>
    <div class="rh-modal-actions">
      <button type="button" class="rh-btn rh-btn-outline rh-btn-sm" data-act="close-invoice" id="rh-invoice-close">Close</button>
      <a class="rh-btn rh-btn-outline rh-btn-sm" id="rh-invoice-mail" href="#" style="display:none;">Email to client</a>
      <button type="button" class="rh-btn rh-btn-primary rh-btn-sm" onclick="rhPrint('invoice')">Print or save as PDF</button>
    </div>
  </div>
</div>

<!-- Keyboard shortcuts -->
<div class="rh-modal-backdrop" id="rh-help-modal" role="dialog" aria-modal="true" aria-label="Keyboard shortcuts">
  <div class="rh-modal rh-modal-sm">
    <h3 class="rh-modal-title">Keyboard shortcuts</h3>
    <dl class="rh-keys">
      <div><dt><kbd>/</kbd></dt><dd>Search</dd></div>
      <div><dt><kbd>1</kbd> to <kbd>5</kbd></dt><dd>Switch status tab</dd></div>
      <div><dt><kbd>e</kbd></dt><dd>Expand or collapse all</dd></div>
      <div><dt><kbd>g</kbd></dt><dd>Group services by client</dd></div>
      <div><dt><kbd>v</kbd></dt><dd>Comfortable or compact layout</dd></div>
      <div><dt><kbd>t</kbd></dt><dd>Light or dark theme</dd></div>
      <div><dt><kbd>?</kbd></dt><dd>Show this list</dd></div>
      <div><dt><kbd>Esc</kbd></dt><dd>Close dialogs</dd></div>
    </dl>
    <div class="rh-modal-actions"><button type="button" class="rh-btn rh-btn-primary rh-btn-sm" data-act="close-help" id="rh-help-close">Got it</button></div>
  </div>
</div>

<div class="rh-toast" id="rh-toast" role="status" aria-live="polite"></div>

<script>
const RH = <?php echo wp_json_encode($config); ?>;
const RH_DATA = RH.data;

/* ---------- Utilities ---------- */
const rhEsc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
const rhStatusKey = (d) => d < 0 ? 'expired' : (d <= 45 ? 'expiring' : 'active');
const rhMoney = (cur, n) => cur + Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
const rhBalance = (r) => r.paymentStatus === 'Paid' ? 0 : (r.paymentStatus === 'Partial' && r.paid > 0 ? Math.max(0, r.cost - r.paid) : r.cost);
const rhPlural = (n, w) => n + ' ' + w + (n === 1 ? '' : 's');
const rhDaysUntil = (dateStr) => {
  const t = Date.parse(dateStr + 'T00:00:00Z'); if (!t) return null;
  const n = new Date(); return Math.round((t - Date.UTC(n.getFullYear(), n.getMonth(), n.getDate())) / 864e5);
};

const RH_ICON = {
  chev: '<path d="m6 9 6 6 6-6"/>',
  copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
  out: '<path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
  file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
  chat: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"/>',
  pulse: '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/>',
  check: '<path d="m5 12 5 5 9-10"/>',
  cal: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  server: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  alert: '<path d="M12 3 2 20h20Z"/><path d="M12 10v4M12 17h.01"/>',
  wallet: '<path d="M3 7a2 2 0 0 1 2-2h13v4"/><path d="M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2Z"/><path d="M16 14.5h.01"/>',
  refresh: '<path d="M20 11a8 8 0 0 0-14-4L4 9"/><path d="M4 4v5h5"/><path d="M4 13a8 8 0 0 0 14 4l2-2"/><path d="M20 20v-5h-5"/>'
};
const rhIcon = (n) => '<svg class="rh-i" viewBox="0 0 24 24" aria-hidden="true">' + RH_ICON[n] + '</svg>';

function rhToast(msg) {
  const t = document.getElementById('rh-toast');
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(rhToast._t);
  rhToast._t = setTimeout(() => t.classList.remove('show'), 2400);
}

function rhCopy(txt, label) {
  const done = () => rhToast(label || 'Copied');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(txt).then(done, () => rhToast('Copy failed. Select the text and copy it manually.'));
  } else {
    const ta = document.createElement('textarea');
    ta.value = txt; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { rhToast('Copy failed. Select the text and copy it manually.'); }
    ta.remove();
  }
}

function rhDaysLabel(d) {
  if (d < 0) return `<b>${Math.abs(d)}</b> ${Math.abs(d) === 1 ? 'day' : 'days'} overdue`;
  if (d === 0) return '<b>Today</b> is the last day';
  return `<b>${d}</b> ${d === 1 ? 'day' : 'days'} left`;
}
function rhDaysPlain(d) {
  if (d < 0) return 'expired ' + rhPlural(Math.abs(d), 'day') + ' ago';
  if (d === 0) return 'expires today';
  return rhPlural(d, 'day') + ' left';
}

function rhTermPct(r) {
  const s = Date.parse(r.startDate), e = Date.parse(r.endDate);
  if (!s || !e || e <= s) return 0;
  return Math.max(0, Math.min(100, Math.round(((Date.now() - s) / (e - s)) * 100)));
}

function rhPayBadge(r) {
  const cls = r.paymentStatus === 'Paid' ? 'ok' : (r.paymentStatus === 'Partial' ? 'warn' : 'bad');
  let txt = r.paymentStatus;
  if (r.paymentStatus === 'Partial' && r.paid > 0) txt = 'Partial, ' + rhMoney(r.currency, rhBalance(r)) + ' due';
  return `<span class="rh-badge rh-badge-${cls}">${rhEsc(txt)}</span>`;
}

/* ---------- Pieces of a service card ---------- */
function rhCredBlock(l, key) {
  const label = l.label || 'Login';
  const url = l.url, user = l.user, pass = l.pass;
  if (!(url || user || pass)) {
    return `<div class="rh-mini rh-mini-empty">${rhEsc(label)}: nothing saved yet</div>`;
  }
  return `
    <div class="rh-mini">
      <div class="rh-cred-head">
        <b>${rhEsc(label)}</b>
        ${url ? `<a href="${rhEsc(url)}" target="_blank" rel="noopener">Open ${rhIcon('out')}</a>` : ''}
      </div>
      ${user ? `<div class="rh-cred-row"><span class="rh-mono">${rhEsc(user)}</span>
        <span class="rh-cred-btns"><button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-copy="${rhEsc(user)}" data-label="Username copied">Copy</button></span></div>` : ''}
      ${pass ? `<div class="rh-cred-row"><span class="rh-mono" id="rh_pw_${key}" data-raw="${rhEsc(pass)}" data-hidden="1">••••••••</span>
        <span class="rh-cred-btns">
          <button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-toggle="rh_pw_${key}" aria-label="Show or hide password">${rhIcon('eye')}</button>
          <button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-copy="${rhEsc(pass)}" data-label="Password copied">Copy</button>
        </span></div>` : ''}
      ${!RH.showCreds && !user && !pass ? '<div class="rh-login-note">Username and password are private.</div>' : ''}
      ${l.note ? `<div class="rh-login-note">${rhEsc(l.note)}</div>` : ''}
    </div>`;
}

function rhKv(k, v, mono) {
  if (!v) return '';
  return `<div class="rh-kv"><dt>${k}</dt><dd${mono ? ' class="rh-mono"' : ''}>${rhEsc(v)}</dd></div>`;
}

function rhServiceCard(r, open) {
  const key = rhStatusKey(r.daysLeft);
  const pct = rhTermPct(r);
  const bal = rhBalance(r);
  const sslCls = r.sslStatus === 'active' ? 'ok' : (r.sslStatus === 'expiring' ? 'warn' : 'bad');
  const sslTxt = r.sslStatus === 'active' ? 'HTTPS active' : (r.sslStatus === 'expiring' ? 'Expiring soon' : 'No SSL');
  const domDays = r.domainExpiry ? rhDaysUntil(r.domainExpiry) : null;
  const domBadge = domDays === null ? '' : (domDays < 0
    ? ' <span class="rh-badge rh-badge-bad">expired</span>'
    : (domDays <= 30 ? ` <span class="rh-badge rh-badge-warn">${domDays}d left</span>` : ''));
  const canCheck = r.type !== 'domain' && r.type !== 'email';
  const logins = r.logins || [];

  return `
  <article class="rh-service is-${key}${open ? ' is-open' : ''}" id="svc-${r.id}" data-id="${r.id}">
    <div class="rh-row">
      <div class="rh-row-id">
        <div class="rh-domain">
          <a href="https://${rhEsc(r.domain)}" target="_blank" rel="noopener">${rhEsc(r.domain)}</a>
          ${r.demo ? '<span class="rh-badge rh-badge-demo">Demo</span>' : ''}
        </div>
        <div class="rh-meta">
          <span class="rh-type">${rhEsc(r.typeLabel)}</span>
          ${r.plan ? `<span>${rhEsc(r.plan)}</span>` : ''}
        </div>
        <div class="rh-owner-line">${rhEsc(r.owner)}${r.clientEmail ? ` <span>${rhEsc(r.clientEmail)}</span>` : ''}</div>
      </div>

      <div class="rh-row-term">
        <div class="rh-days">${rhDaysLabel(r.daysLeft)}</div>
        <div class="rh-bar" role="img" aria-label="${pct}% of the term has passed"><i style="width:${pct}%"></i></div>
        <div class="rh-term-dates">${rhEsc(r.startDate || '?')} to ${rhEsc(r.endDate || '?')}</div>
      </div>

      <div class="rh-row-money">
        <div class="rh-amt">${rhEsc(rhMoney(r.currency, r.cost))} <small>${rhEsc(String(r.cycleLabel).toLowerCase())}</small></div>
        ${rhPayBadge(r)}
      </div>

      <div class="rh-row-actions">
        ${r.paymentLink && bal > 0 ? `<a class="rh-btn rh-btn-primary rh-btn-sm" href="${rhEsc(r.paymentLink)}" target="_blank" rel="noopener">Pay ${rhEsc(rhMoney(r.currency, bal))}</a>` : ''}
        <button type="button" class="rh-expander" data-expand="${r.id}" aria-expanded="${open ? 'true' : 'false'}" aria-controls="rh-d-${r.id}" aria-label="${open ? 'Hide' : 'Show'} details for ${rhEsc(r.domain)}">${rhIcon('chev')}</button>
      </div>
    </div>

    <div class="rh-detail" id="rh-d-${r.id}"${open ? '' : ' hidden'}>
      <div class="rh-grid">
        <div class="rh-cell">
          <h4>Technical health</h4>
          <dl>
            <div class="rh-kv"><dt>SSL</dt><dd><span class="rh-badge rh-badge-${sslCls}">${sslTxt}</span></dd></div>
            ${r.domainExpiry ? `<div class="rh-kv"><dt>Domain expiry</dt><dd><span class="rh-mono">${rhEsc(r.domainExpiry)}</span>${domBadge}</dd></div>` : ''}
            ${rhKv('Server IP', r.serverIp, true)}
            ${rhKv('Registrar', r.registrar)}
            ${rhKv('Nameservers', r.nameservers, true)}
          </dl>
          ${canCheck ? `<div class="rh-cell-foot"><button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-ping="${rhEsc(r.domain)}" data-demo="${r.demo ? '1' : ''}">${rhIcon('pulse')} Check uptime</button></div>` : ''}
        </div>

        <div class="rh-cell">
          <h4>Email accounts (${(r.emails || []).length})</h4>
          ${(r.emails && r.emails.length)
            ? r.emails.map(e => `<div class="rh-mini"><div class="rh-cred-row"><span class="rh-mono">${rhEsc(e)}</span><span class="rh-cred-btns"><button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-copy="${rhEsc(e)}" data-label="Email copied">Copy</button></span></div></div>`).join('')
              + (r.emails.length > 1 ? `<div class="rh-cell-foot"><button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-copy="${rhEsc(r.emails.join(', '))}" data-label="All emails copied">Copy all</button></div>` : '')
            : '<span class="rh-muted">No email accounts on this service.</span>'}
        </div>

        <div class="rh-cell">
          <h4>Logins (${logins.length})</h4>
          ${logins.length ? logins.map((l, i) => rhCredBlock(l, r.id + '_' + i)).join('') : '<span class="rh-muted">No logins on file for this service.</span>'}
        </div>

        <div class="rh-cell">
          <h4>Billing</h4>
          <dl>
            <div class="rh-kv"><dt>Invoice</dt><dd class="rh-mono">${rhEsc(r.invoiceNo)}</dd></div>
            <div class="rh-kv"><dt>Amount</dt><dd><strong>${rhEsc(rhMoney(r.currency, r.cost))}</strong></dd></div>
            <div class="rh-kv"><dt>Payment</dt><dd>${rhPayBadge(r)}</dd></div>
            ${r.paymentStatus === 'Partial' && r.paid > 0 ? `<div class="rh-kv"><dt>Paid so far</dt><dd>${rhEsc(rhMoney(r.currency, r.paid))}</dd></div><div class="rh-kv"><dt>Balance</dt><dd><strong>${rhEsc(rhMoney(r.currency, bal))}</strong></dd></div>` : ''}
            <div class="rh-kv"><dt>Billing cycle</dt><dd>${rhEsc(r.cycleLabel)}</dd></div>
            <div class="rh-kv"><dt>Term ends</dt><dd>${rhEsc(r.endDate || '?')}</dd></div>
          </dl>
        </div>
      </div>

      ${r.notes ? `<div class="rh-note"><b>Note</b> ${rhEsc(r.notes)}</div>` : ''}

      <div class="rh-detail-foot">
        <button type="button" class="rh-btn rh-btn-outline rh-btn-sm" data-invoice="${r.id}">${rhIcon('file')} View invoice</button>
        <button type="button" class="rh-btn rh-btn-wa rh-btn-sm" data-renew="${r.id}">${rhIcon('chat')} Renew via WhatsApp</button>
        <button type="button" class="rh-btn rh-btn-ghost rh-btn-sm" data-summary="${r.id}">${rhIcon('copy')} Copy summary</button>
        ${r.endDate ? `<button type="button" class="rh-btn rh-btn-ghost rh-btn-sm" data-ics="${r.id}">${rhIcon('cal')} Add to calendar</button>` : ''}
        ${RH.editor ? `
          <span class="rh-foot-spacer"></span>
          ${r.clientEmail ? `<a class="rh-btn rh-btn-ghost rh-btn-sm" href="${rhEsc(rhReminderMailto(r))}">${rhIcon('mail')} Email reminder</a>` : ''}
          ${r.paymentStatus !== 'Paid' ? `<a class="rh-btn rh-btn-ghost rh-btn-sm" href="${rhEsc(r.paidUrl)}" data-confirm="Mark ${rhEsc(r.domain)} as paid?">${rhIcon('check')} Mark paid</a>` : ''}
          <a class="rh-btn rh-btn-ghost rh-btn-sm" href="${rhEsc(r.renewUrl)}" data-confirm="Start the next term for ${rhEsc(r.domain)}? The end date moves forward and the invoice resets to Unpaid.">${rhIcon('refresh')} Renew term</a>
          <a class="rh-btn rh-btn-ghost rh-btn-sm" href="${rhEsc(r.editUrl)}">${rhIcon('edit')} Edit</a>` : ''}
      </div>
    </div>
  </article>`;
}

/* ---------- Messages ---------- */
function rhReminderMailto(r) {
  const bal = rhBalance(r);
  const subject = `Renewal reminder: ${r.domain}`;
  const body = `Hello ${r.owner},\n\nYour service for ${r.domain}${r.plan ? ' (' + r.plan + ')' : ''} ${rhDaysPlain(r.daysLeft)} (term ends ${r.endDate}).\n`
    + (bal > 0 ? `Amount due: ${rhMoney(r.currency, bal)}\n` : '')
    + (r.paymentLink ? `Pay online: ${r.paymentLink}\n` : '')
    + `\nQuestions? WhatsApp ${RH.waDisplay}.\n\nRegards,\nRansHosting by RansTech Digital`;
  return 'mailto:' + encodeURIComponent(r.clientEmail).replace(/%40/g, '@') + '?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
}

function rhSummaryText(r) {
  return [
    `${r.domain} (${r.owner})`,
    `Service: ${r.typeLabel}${r.plan ? ', ' + r.plan : ''}`,
    `Term: ${r.startDate} to ${r.endDate} (${rhDaysPlain(r.daysLeft)})`,
    `Amount: ${rhMoney(r.currency, r.cost)} ${String(r.cycleLabel).toLowerCase()}, ${r.paymentStatus}${rhBalance(r) > 0 ? ', balance ' + rhMoney(r.currency, rhBalance(r)) : ''}`,
    r.paymentLink && rhBalance(r) > 0 ? `Pay: ${r.paymentLink}` : ''
  ].filter(Boolean).join('\n');
}

/* ---------- State & filtering ---------- */
const rhState = { tab: 'all', view: 'comfortable', group: true, open: new Set(), highlight: null };
const $ = (id) => document.getElementById(id);

function rhBase() {
  const ownerSel = $('rh-owner-filter');
  const ownerVal = ownerSel ? ownerSel.value : 'all';
  const typeVal = $('rh-type-filter').value;
  const q = ($('rh-search').value || '').toLowerCase().trim();
  return RH_DATA.filter(r => {
    if (ownerVal !== 'all' && r.owner !== ownerVal) return false;
    if (typeVal !== 'all' && r.type !== typeVal) return false;
    if (!q) return true;
    return [r.domain, r.owner, r.clientEmail, r.serverIp, r.registrar, r.plan, r.typeLabel, r.invoiceNo, ...(r.emails || []), ...(r.logins || []).map(l => l.label)].join(' ').toLowerCase().includes(q);
  });
}
const rhTabMatch = (r, tab) => tab === 'all' || (tab === 'unpaid' ? r.paymentStatus !== 'Paid' : rhStatusKey(r.daysLeft) === tab);

function rhSort(list) {
  const mode = $('rh-sort').value;
  const by = {
    'expiry': (a, b) => a.daysLeft - b.daysLeft,
    'expiry-desc': (a, b) => b.daysLeft - a.daysLeft,
    'name': (a, b) => a.domain.localeCompare(b.domain),
    'owner': (a, b) => a.owner.localeCompare(b.owner) || a.daysLeft - b.daysLeft,
    'amount': (a, b) => rhBalance(b) - rhBalance(a) || a.daysLeft - b.daysLeft
  }[mode];
  return list.slice().sort(by);
}

/* ---------- Client groups ---------- */
function rhGroupList(list) {
  const groups = new Map();
  list.forEach(r => { if (!groups.has(r.owner)) groups.set(r.owner, []); groups.get(r.owner).push(r); });
  const keyFn = {
    'expiry': ([, rs]) => Math.min(...rs.map(x => x.daysLeft)),
    'expiry-desc': ([, rs]) => -Math.max(...rs.map(x => x.daysLeft)),
    'amount': ([, rs]) => -rs.reduce((a, x) => a + rhBalance(x), 0)
  }[$('rh-sort').value];
  return [...groups.entries()].sort((a, b) => (keyFn ? keyFn(a) - keyFn(b) : 0) || a[0].localeCompare(b[0]));
}

function rhGroupHead(owner, rs) {
  const c = (RH.clients || {})[owner];
  const bal = {};
  rs.forEach(x => { const b = rhBalance(x); if (b > 0) bal[x.currency] = (bal[x.currency] || 0) + b; });
  const balTxt = Object.keys(bal).map(k => rhMoney(k, bal[k])).join(' + ');
  const next = rs.slice().sort((a, b) => a.daysLeft - b.daysLeft)[0];
  const counts = {};
  rs.forEach(x => { counts[x.typeLabel] = (counts[x.typeLabel] || 0) + 1; });
  const pills = Object.keys(counts).map(t => `<span class="rh-pill">${rhEsc(t)}${counts[t] > 1 ? ' × ' + counts[t] : ''}</span>`).join('');
  const actions = RH.editor ? `
    <div class="rh-group-actions">
      ${c ? `<a class="rh-btn rh-btn-outline rh-btn-xs" href="${rhEsc(c.add)}">+ Add service</a><a class="rh-btn rh-btn-ghost rh-btn-xs" href="${rhEsc(c.edit)}">${rhIcon('edit')} Edit client</a>` : ''}
      <button type="button" class="rh-btn rh-btn-ghost rh-btn-xs" data-clientlink="${rhEsc(owner)}">${rhIcon('copy')} Copy link</button>
    </div>` : '';
  return `
    <header class="rh-group-head">
      <div class="rh-group-id">
        <h3>${rhEsc(owner)}</h3>
        <div class="rh-group-meta">${rhPlural(rs.length, 'service')} · next renewal ${rhEsc(next.domain)}, ${rhDaysPlain(next.daysLeft)} · ${balTxt ? '<b>' + rhEsc(balTxt) + '</b> due' : 'all paid'}</div>
        <div class="rh-group-types">${pills}</div>
      </div>
      ${actions}
    </header>`;
}

/* ---------- Summary numbers ---------- */
function rhRenderStats(base) {
  const expiring = base.filter(x => x.daysLeft >= 0 && x.daysLeft <= 45);
  const expired = base.filter(x => x.daysLeft < 0);
  const mailboxes = base.reduce((a, x) => a + (x.emails ? x.emails.length : 0), 0);
  const owing = base.filter(x => rhBalance(x) > 0);
  const byCur = {}, billed = {};
  base.forEach(x => { billed[x.currency] = (billed[x.currency] || 0) + x.cost; });
  owing.forEach(x => { byCur[x.currency] = (byCur[x.currency] || 0) + rhBalance(x); });
  const curs = Object.keys(billed);
  const dueText = Object.keys(byCur).length ? Object.keys(byCur).map(c => rhMoney(c, byCur[c])).join(' + ') : 'Nothing';
  const next = expiring.slice().sort((a, b) => a.daysLeft - b.daysLeft)[0];
  let paidBar = '';
  if (curs.length === 1 && billed[curs[0]] > 0) {
    const due = byCur[curs[0]] || 0;
    const pct = Math.max(0, Math.min(100, Math.round(((billed[curs[0]] - due) / billed[curs[0]]) * 100)));
    paidBar = `<div class="rh-meter" role="img" aria-label="${pct}% of billed amount collected"><i style="width:${pct}%"></i></div><div class="rh-stat-sub">${pct}% of ${rhEsc(rhMoney(curs[0], billed[curs[0]]))} collected</div>`;
  }

  $('rh-stats').innerHTML = `
    <div class="rh-stat"><span class="rh-stat-ico">${rhIcon('server')}</span><div class="rh-stat-val">${base.length}</div><div class="rh-stat-label">Services</div><div class="rh-stat-sub">${rhPlural(mailboxes, 'mailbox')} included</div></div>
    <div class="rh-stat is-warn"><span class="rh-stat-ico">${rhIcon('clock')}</span><div class="rh-stat-val">${expiring.length}</div><div class="rh-stat-label">Ending within 45 days</div><div class="rh-stat-sub">${next ? rhEsc(next.domain) + ' is next, ' + rhDaysPlain(next.daysLeft) : 'Nothing due soon'}</div></div>
    <div class="rh-stat is-bad"><span class="rh-stat-ico">${rhIcon('alert')}</span><div class="rh-stat-val">${expired.length}</div><div class="rh-stat-label">Expired</div><div class="rh-stat-sub">${expired.length ? 'Renew to bring them back online' : 'All services are current'}</div></div>
    <div class="rh-stat is-money"><span class="rh-stat-ico">${rhIcon('wallet')}</span><div class="rh-stat-val rh-stat-money">${rhEsc(dueText)}</div><div class="rh-stat-label">Outstanding</div>${paidBar || `<div class="rh-stat-sub">${rhPlural(owing.length, 'unpaid invoice')}</div>`}</div>`;
}

/* ---------- Needs attention ---------- */
function rhRenderAttention(base) {
  const el = $('rh-attn');
  const urgent = base.filter(r => r.daysLeft < 0 || r.daysLeft <= 14 || (rhBalance(r) > 0 && r.daysLeft <= 45))
    .sort((a, b) => a.daysLeft - b.daysLeft);
  if (!urgent.length) { el.hidden = true; el.innerHTML = ''; return; }
  const shown = urgent.slice(0, 4);
  el.hidden = false;
  el.innerHTML = `
    <div class="rh-attn-head">
      <h2>${rhIcon('alert')} Needs attention <span class="rh-count">${urgent.length}</span></h2>
      ${urgent.length > shown.length ? `<span class="rh-muted">Showing the ${shown.length} most urgent</span>` : ''}
    </div>
    <div class="rh-attn-list">
      ${shown.map(r => {
        const cls = rhStatusKey(r.daysLeft);
        const bal = rhBalance(r);
        const why = [rhDaysPlain(r.daysLeft).replace(/^./, c => c.toUpperCase()), bal > 0 ? rhMoney(r.currency, bal) + ' due' : ''].filter(Boolean).join(' · ');
        const act = (RH.editor && r.clientEmail)
          ? `<a class="rh-btn rh-btn-ghost rh-btn-xs" href="${rhEsc(rhReminderMailto(r))}">${rhIcon('mail')} Email reminder</a>`
          : `<button type="button" class="rh-btn rh-btn-wa rh-btn-xs" data-renew="${r.id}">${rhIcon('chat')} Renew</button>`;
        return `<div class="rh-attn-item is-${cls}">
          <button type="button" class="rh-attn-main" data-dot="${r.id}"><b>${rhEsc(r.domain)}</b><span>${rhEsc(why)}</span></button>
          ${act}
        </div>`;
      }).join('')}
    </div>`;
}

/* ---------- Renewal timeline ---------- */
const RH_T_MIN = -14, RH_T_MAX = 120;
const rhPos = (d) => ((Math.max(RH_T_MIN, Math.min(RH_T_MAX, d)) - RH_T_MIN) / (RH_T_MAX - RH_T_MIN)) * 100;

function rhRenderTimeline(base) {
  const el = $('rh-timeline');
  const inWindow = base.filter(r => r.daysLeft <= RH_T_MAX).sort((a, b) => a.daysLeft - b.daysLeft);
  const later = base.length - inWindow.length;
  const rowsUsed = [];
  const dots = inWindow.map((r) => {
    const x = rhPos(r.daysLeft);
    let row = 0;
    while (rowsUsed[row] !== undefined && x - rowsUsed[row] < 3.2) row++;
    rowsUsed[row] = x;
    const tip = `${r.domain}, ${rhDaysPlain(r.daysLeft)}`;
    return `<button type="button" class="rh-dot is-${rhStatusKey(r.daysLeft)}${x > 72 ? ' tip-left' : ''}" style="left:${x}%;top:${14 + row * 22}px" data-dot="${r.id}" data-tip="${rhEsc(tip)}" aria-label="${rhEsc(tip)}"></button>`;
  }).join('');
  const rowCount = Math.max(1, rowsUsed.length);
  const ticks = [0, 30, 60, 90, 120].map(d => `<span class="rh-tick" style="left:${rhPos(d)}%">${d === 0 ? 'Today' : d + 'd'}</span>`).join('');

  el.innerHTML = `
    <div class="rh-timeline-head">
      <h2>Renewals over the next 120 days</h2>
      <div class="rh-legend"><span class="is-active">Active</span><span class="is-expiring">Ending within 45 days</span><span class="is-expired">Expired</span></div>
    </div>
    <div class="rh-track" style="height:${rowCount * 22 + 26}px">
      <div class="rh-zone rh-zone-over" style="width:${rhPos(0)}%"></div>
      <div class="rh-zone rh-zone-warn" style="left:${rhPos(0)}%;width:${rhPos(45) - rhPos(0)}%"></div>
      <div class="rh-today" style="left:${rhPos(0)}%"></div>
      ${dots || '<div class="rh-track-empty">No renewals fall in this window.</div>'}
    </div>
    <div class="rh-axis">${ticks}</div>
    ${later > 0 ? `<div class="rh-timeline-foot">${rhPlural(later, 'more service')} renew after 120 days.</div>` : ''}`;
}

/* ---------- Main render ---------- */
function rhRender() {
  const base = rhBase();
  const counts = { all: base.length, active: 0, expiring: 0, expired: 0, unpaid: 0 };
  base.forEach(r => { counts[rhStatusKey(r.daysLeft)]++; if (r.paymentStatus !== 'Paid') counts.unpaid++; });
  document.querySelectorAll('[data-count]').forEach(s => { s.textContent = counts[s.dataset.count]; });
  document.querySelectorAll('.rh-tab').forEach(b => b.setAttribute('aria-pressed', b.dataset.tab === rhState.tab ? 'true' : 'false'));

  rhRenderStats(base);
  rhRenderAttention(base);
  rhRenderTimeline(base);

  const list = rhSort(base.filter(r => rhTabMatch(r, rhState.tab)));
  const box = $('rh-list');
  const grouped = rhState.group && !RH.client;
  box.classList.toggle('is-compact', rhState.view === 'compact');
  box.classList.toggle('is-grouped', grouped);
  const gBtn = $('rh-toggle-group'); if (gBtn) gBtn.setAttribute('aria-pressed', rhState.group ? 'true' : 'false');
  document.querySelectorAll('[data-view]').forEach(b => b.setAttribute('aria-pressed', b.dataset.view === rhState.view ? 'true' : 'false'));
  const hasQ = !!($('rh-search').value || '').length;
  const clr = document.querySelector('.rh-search-clear'); if (clr) clr.hidden = !hasQ;
  const kb = $('rh-search-kbd'); if (kb) kb.hidden = hasQ;
  if (!RH_DATA.length) {
    box.innerHTML = `<div class="rh-empty"><h3>No services yet</h3><p>${RH.editor ? 'Add your first client, then add their services (hosting, email accounts, domains, maintenance) under them. Or load demo clients to see how the portal looks.' : 'Nothing has been added to this portal yet. Message support if you expected to see services here.'}</p>${RH.editor ? `<div class="rh-hero-actions" style="justify-content:center"><a class="rh-btn rh-btn-primary" href="${rhEsc(RH.addClientUrl)}">Add a client</a><a class="rh-btn rh-btn-outline" href="${rhEsc(RH.demoUrl)}">Load demo services</a></div>` : ''}</div>`;
  } else if (!list.length) {
    box.innerHTML = `<div class="rh-empty"><h3>Nothing matches these filters</h3><p>Try a different search or clear the filters.</p><button type="button" class="rh-btn rh-btn-outline" data-act="clear">Clear filters</button></div>`;
  } else {
    if (grouped) {
      box.innerHTML = rhGroupList(list).map(([owner, rs]) =>
        `<section class="rh-group" aria-label="${rhEsc(owner)}">${rhGroupHead(owner, rs)}<div class="rh-group-list">${rs.map(r => rhServiceCard(r, rhState.open.has(r.id))).join('')}</div></section>`
      ).join('');
    } else {
      box.innerHTML = list.map(r => rhServiceCard(r, rhState.open.has(r.id))).join('');
    }
  }
  const toggleAll = $('rh-toggle-all');
  if (toggleAll) {
    const allOpen = list.length && list.every(r => rhState.open.has(r.id));
    toggleAll.textContent = allOpen ? 'Collapse all' : 'Expand all';
    toggleAll.dataset.state = allOpen ? 'open' : 'closed';
  }
  if (rhState.highlight) {
    const t = $('svc-' + rhState.highlight);
    if (t) { t.classList.add('is-flash'); t.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center' }); }
    rhState.highlight = null;
  }
  rhSavePrefs();
}

function rhSetOpen(id, open) {
  if (open) rhState.open.add(id); else rhState.open.delete(id);
  const card = $('svc-' + id), detail = $('rh-d-' + id);
  if (!card || !detail) return;
  card.classList.toggle('is-open', open);
  detail.hidden = !open;
  const btn = card.querySelector('[data-expand]');
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

/* ---------- Invoice ---------- */
let rhLastFocus = null;
function rhOpenInvoice(id) {
  const r = RH_DATA.find(x => x.id === id);
  if (!r) return;
  const st = String(r.paymentStatus || 'Unpaid').toLowerCase();
  const issued = new Date().toISOString().slice(0, 10);
  const bal = rhBalance(r);
  const due = r.daysLeft < 0 ? 'Due now' : (r.endDate || '');
  document.getElementById('rh-invoice-print').innerHTML = `
    <div class="rh-inv-top">
      <div class="rh-inv-brand">
        <div class="rh-inv-badge">R</div>
        <div><div class="rh-inv-title">RansHosting</div><div class="rh-inv-sub">by RansTech Digital, ranstechdigital.com</div></div>
      </div>
      <div class="rh-inv-meta">
        <strong>Invoice ${rhEsc(r.invoiceNo)}</strong><br/>
        <span class="rh-inv-sub">Issued ${issued}</span><br/>
        <span class="rh-inv-sub">Due ${rhEsc(due)}</span><br/>
        <span class="rh-stamp ${rhEsc(st)}">${rhEsc(String(r.paymentStatus || 'Unpaid').toUpperCase())}</span>
      </div>
    </div>
    <div class="rh-inv-cols">
      <div><div class="rh-inv-label">Billed to</div><strong>${rhEsc(r.owner)}</strong><br/>${rhEsc(r.clientEmail || '')}${r.clientPhone ? '<br/>' + rhEsc(r.clientPhone) : ''}</div>
      <div><div class="rh-inv-label">From</div><strong>RansTech Digital</strong><br/>${rhEsc(RH.contact)}<br/>WhatsApp ${rhEsc(RH.waDisplay)}</div>
    </div>
    <table class="rh-inv-table">
      <thead><tr><th>Service</th><th>Term</th><th style="text-align:right;">Amount</th></tr></thead>
      <tbody><tr>
        <td><strong>${rhEsc(r.typeLabel)}${r.plan ? ': ' + rhEsc(r.plan) : ''}</strong><br/><span class="rh-inv-sub">${rhEsc(r.domain)}, billed ${rhEsc(String(r.cycleLabel).toLowerCase())}</span></td>
        <td>${rhEsc(r.startDate)} to ${rhEsc(r.endDate)}</td>
        <td style="text-align:right;">${rhEsc(rhMoney(r.currency, r.cost))}</td>
      </tr></tbody>
    </table>
    ${r.paymentStatus === 'Partial' && r.paid > 0 ? `
      <div class="rh-inv-line"><span>Total</span><span>${rhEsc(rhMoney(r.currency, r.cost))}</span></div>
      <div class="rh-inv-line"><span>Paid so far</span><span>- ${rhEsc(rhMoney(r.currency, r.paid))}</span></div>` : ''}
    <div class="rh-inv-total"><span>${bal > 0 ? 'Balance due' : 'Total paid'}</span><span>${rhEsc(rhMoney(r.currency, bal > 0 ? bal : r.cost))}</span></div>
    ${bal > 0 ? `<div class="rh-inv-pay">
      <strong>How to pay</strong><br/>${rhEsc(RH.payInst).replace(/\n/g, '<br/>')}
      ${r.paymentLink ? `<div style="margin-top:6px;">Pay online: <a href="${rhEsc(r.paymentLink)}" target="_blank" rel="noopener">${rhEsc(r.paymentLink)}</a></div>` : ''}
    </div>` : ''}
    ${r.notes ? `<div class="rh-inv-sub" style="margin-top:12px;">Note: ${rhEsc(r.notes)}</div>` : ''}
    <div class="rh-inv-foot">Thank you for choosing RansTech Digital. Questions? Message us on WhatsApp anytime.</div>
  `;
  const mail = document.getElementById('rh-invoice-mail');
  if (RH.editor && r.clientEmail) {
    mail.href = 'mailto:' + r.clientEmail + '?subject=' + encodeURIComponent(`Invoice ${r.invoiceNo} for ${r.domain}`)
      + '&body=' + encodeURIComponent(`Hello ${r.owner},\n\nInvoice ${r.invoiceNo} for ${r.domain}: ${rhMoney(r.currency, bal > 0 ? bal : r.cost)} ${bal > 0 ? 'due' : 'paid'}.\n` + (r.paymentLink && bal > 0 ? `Pay online: ${r.paymentLink}\n` : '') + `\nRegards,\nRansHosting by RansTech Digital`);
    mail.style.display = '';
  } else { mail.style.display = 'none'; }
  rhLastFocus = document.activeElement;
  document.getElementById('rh-invoice-modal').style.display = 'flex';
  document.getElementById('rh-invoice-close').focus();
}

function rhCloseInvoice() {
  document.getElementById('rh-invoice-modal').style.display = 'none';
  if (rhLastFocus && rhLastFocus.focus) rhLastFocus.focus();
}

/* ---------- Saved preferences ---------- */
function rhSavePrefs() {
  try { localStorage.setItem('rh-prefs', JSON.stringify({ tab: rhState.tab, view: rhState.view, group: rhState.group, sort: $('rh-sort').value, type: $('rh-type-filter').value })); } catch (e) {}
}
function rhLoadPrefs() {
  let p = {};
  try { p = JSON.parse(localStorage.getItem('rh-prefs') || '{}') || {}; } catch (e) { p = {}; }
  if (['all', 'active', 'expiring', 'expired', 'unpaid'].includes(p.tab)) rhState.tab = p.tab;
  if (p.view === 'compact' || p.view === 'comfortable') rhState.view = p.view;
  if (typeof p.group === 'boolean') rhState.group = p.group;
  const sortEl = $('rh-sort'), typeEl = $('rh-type-filter');
  if (p.sort && [...sortEl.options].some(o => o.value === p.sort)) sortEl.value = p.sort;
  if (p.type && [...typeEl.options].some(o => o.value === p.type)) typeEl.value = p.type;
}

/* ---------- Calendar (.ics) ---------- */
function rhIcsEsc(s) { return String(s == null ? '' : s).replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\r?\n/g, '\\n'); }
function rhIcsFold(line) { const out = []; while (line.length > 73) { out.push(line.slice(0, 73)); line = ' ' + line.slice(73); } out.push(line); return out.join('\r\n'); }
function rhBuildIcs(rows) {
  const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z');
  const L = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//RansHosting//Renewals//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:RansHosting renewals'];
  let n = 0;
  rows.forEach(r => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(r.endDate || '')) return;
    n++;
    const next = new Date(Date.parse(r.endDate + 'T00:00:00Z') + 864e5).toISOString().slice(0, 10);
    const bal = rhBalance(r);
    const desc = `${r.typeLabel}${r.plan ? ', ' + r.plan : ''}. Client: ${r.owner}. Term ends ${r.endDate}. ${bal > 0 ? 'Balance due ' + rhMoney(r.currency, bal) + '.' : 'Paid.'} Questions? WhatsApp ${RH.waDisplay}.`;
    L.push('BEGIN:VEVENT', 'UID:rh-' + r.id + '-' + r.endDate + '@ranshosting', 'DTSTAMP:' + stamp,
      'DTSTART;VALUE=DATE:' + r.endDate.replace(/-/g, ''), 'DTEND;VALUE=DATE:' + next.replace(/-/g, ''),
      'SUMMARY:' + rhIcsEsc('Renew ' + r.domain + ' (' + r.typeLabel + ')'), 'DESCRIPTION:' + rhIcsEsc(desc), 'TRANSP:TRANSPARENT');
    [30, 7].forEach(d => L.push('BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' + rhIcsEsc(r.domain + ' renews in ' + d + ' days'), 'TRIGGER:-P' + d + 'D', 'END:VALARM'));
    L.push('END:VEVENT');
  });
  L.push('END:VCALENDAR');
  return { text: L.map(rhIcsFold).join('\r\n') + '\r\n', count: n };
}
function rhDownload(name, text, type) {
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([text], { type }));
  a.download = name;
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}
function rhExportIcs(rows, name) {
  const out = rhBuildIcs(rows);
  if (!out.count) { rhToast('No services with a renewal date to add.'); return; }
  rhDownload(name, out.text, 'text/calendar;charset=utf-8');
  rhToast(rhPlural(out.count, 'renewal') + ' ready for your calendar (reminders at 30 and 7 days)');
}

/* ---------- Print & help ---------- */
function rhPrint(mode) {
  document.body.classList.add('print-' + mode);
  window.print();
}
window.addEventListener('afterprint', () => document.body.classList.remove('print-invoice', 'print-report'));
function rhHelp(open) {
  const m = $('rh-help-modal');
  m.style.display = open ? 'flex' : 'none';
  if (open) $('rh-help-close').focus();
}
function rhSetView(v) { rhState.view = v; rhRender(); rhToast(v === 'compact' ? 'Compact layout' : 'Comfortable layout'); }

/* ---------- Uptime, CSV, links ---------- */
function rhPing(domain, btn) {
  if (btn.dataset.demo) { rhToast('Demo domains are not live, so there is nothing to check.'); return; }
  const prev = btn.innerHTML;
  btn.textContent = 'Checking...';
  const start = performance.now();
  fetch('https://' + domain, { mode: 'no-cors', cache: 'no-store' })
    .then(() => { btn.textContent = 'Up, ' + Math.round(performance.now() - start) + ' ms'; btn.classList.add('is-up'); })
    .catch(() => { btn.textContent = 'Unreachable'; btn.classList.add('is-down'); setTimeout(() => { btn.innerHTML = prev; btn.classList.remove('is-down'); }, 4000); });
}

function rhExportCsv() {
  const rows = rhSort(rhBase().filter(r => rhTabMatch(r, rhState.tab)));
  if (!rows.length) { rhToast('Nothing to export with these filters.'); return; }
  const head = ['Domain', 'Client', 'Client email', 'Client phone', 'Service type', 'Plan', 'Billing cycle', 'Term start', 'Term end', 'Days left', 'Status', 'Domain expiry', 'SSL', 'Server IP', 'Registrar', 'Currency', 'Cost', 'Paid', 'Balance', 'Payment status', 'Invoice no', 'Logins on file'];
  const cell = (v) => { let s = String(v == null ? '' : v); if (/^[=+\-@]/.test(s)) s = "'" + s; return '"' + s.replace(/"/g, '""') + '"'; };
  const lines = [head.map(cell).join(',')].concat(rows.map(r => [
    r.domain, r.owner, r.clientEmail, r.clientPhone, r.typeLabel, r.plan, r.cycleLabel, r.startDate, r.endDate, r.daysLeft,
    rhStatusKey(r.daysLeft), r.domainExpiry, r.sslStatus, r.serverIp, r.registrar, r.currency, r.cost, r.paid, rhBalance(r), r.paymentStatus, r.invoiceNo, (r.logins || []).length
  ].map(cell).join(',')));
  const blob = new Blob(['\ufeff' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = 'ranshosting-services-' + new Date().toISOString().slice(0, 10) + '.csv';
  document.body.appendChild(a); a.click(); a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
  rhToast(rhPlural(rows.length, 'service') + ' exported (logins are never included)');
}

function rhClientLink() {
  const sel = $('rh-owner-filter');
  rhClientLinkFor(sel ? sel.value : 'all');
}
function rhClientLinkFor(owner) {
  if (owner === 'all') {
    rhToast(RH.accessMode === 'key' ? 'Pick a client first. Private links are made per client.' : 'Pick a client first to copy their link.');
    return;
  }
  const url = new URL(RH.home);
  url.searchParams.set('client', owner);
  if (RH.accessMode === 'key' && RH.ownerKeys[owner]) url.searchParams.set('key', RH.ownerKeys[owner]);
  rhCopy(url.toString(), 'Link for ' + owner + ' copied');
}

/* ---------- Events ---------- */
document.addEventListener('click', (ev) => {
  const t = ev.target.closest('[data-copy],[data-toggle],[data-ping],[data-invoice],[data-renew],[data-summary],[data-act],[data-tab],[data-expand],[data-dot],[data-view],[data-ics],[data-clientlink],a[data-confirm]');
  document.querySelectorAll('.rh-more[open]').forEach(d => { if (!d.contains(ev.target)) d.removeAttribute('open'); });
  if (!t) {
    if (ev.target.id === 'rh-invoice-modal') return rhCloseInvoice();
    if (ev.target.id === 'rh-help-modal') return rhHelp(false);
    const row = ev.target.closest('.rh-row');
    if (row && !ev.target.closest('a,button')) {
      const card = row.closest('.rh-service');
      if (card) rhSetOpen(Number(card.dataset.id), !rhState.open.has(Number(card.dataset.id)));
    }
    return;
  }
  if (t.dataset.confirm) { if (!confirm(t.dataset.confirm)) ev.preventDefault(); return; }
  if (t.dataset.copy !== undefined) return rhCopy(t.dataset.copy, t.dataset.label);
  if (t.dataset.toggle) {
    const el = document.getElementById(t.dataset.toggle);
    if (!el) return;
    const hidden = el.getAttribute('data-hidden') === '1';
    el.textContent = hidden ? el.getAttribute('data-raw') : '••••••••';
    el.setAttribute('data-hidden', hidden ? '0' : '1');
    return;
  }
  if (t.dataset.clientlink !== undefined) return rhClientLinkFor(t.dataset.clientlink);
  if (t.dataset.ping) return rhPing(t.dataset.ping, t);
  if (t.dataset.invoice) return rhOpenInvoice(Number(t.dataset.invoice));
  if (t.dataset.summary) { const r = RH_DATA.find(x => x.id === Number(t.dataset.summary)); return r && rhCopy(rhSummaryText(r), 'Summary copied'); }
  if (t.dataset.renew) {
    const r = RH_DATA.find(x => x.id === Number(t.dataset.renew));
    if (!r) return;
    document.getElementById('rh-wa-msg').value = `Hello RansHosting, I would like to renew ${r.domain}${r.plan ? ' (' + r.plan + ')' : ''}. Term ends ${r.endDate}. Amount: ${rhMoney(r.currency, r.cost)}.`;
    return rhToggleWa(true);
  }
  if (t.dataset.tab) { rhState.tab = t.dataset.tab; return rhRender(); }
  if (t.dataset.expand) { const id = Number(t.dataset.expand); return rhSetOpen(id, !rhState.open.has(id)); }
  if (t.dataset.dot) {
    const id = Number(t.dataset.dot);
    rhState.tab = 'all';
    const sel = $('rh-owner-filter'); if (sel) sel.value = 'all';
    $('rh-type-filter').value = 'all'; $('rh-search').value = '';
    rhState.open.add(id); rhState.highlight = id;
    history.replaceState(null, '', '#svc-' + id);
    return rhRender();
  }
  const menu = t.closest('.rh-more'); if (menu) menu.removeAttribute('open');
  if (t.dataset.view) return rhSetView(t.dataset.view);
  if (t.dataset.ics) { const r = RH_DATA.find(x => x.id === Number(t.dataset.ics)); return r && rhExportIcs([r], 'renew-' + r.domain.replace(/[^a-z0-9.-]/gi, '') + '.ics'); }
  if (t.dataset.act === 'export-ics') return rhExportIcs(rhSort(rhBase().filter(r => rhTabMatch(r, rhState.tab))), 'ranshosting-renewals-' + new Date().toISOString().slice(0, 10) + '.ics');
  if (t.dataset.act === 'print-report') return rhPrint('report');
  if (t.dataset.act === 'help') return rhHelp(true);
  if (t.dataset.act === 'close-help') return rhHelp(false);
  if (t.dataset.act === 'clear-search') { $('rh-search').value = ''; rhRender(); return $('rh-search').focus(); }
  if (t.dataset.act === 'close-invoice') return rhCloseInvoice();
  if (t.dataset.act === 'client-link') return rhClientLink();
  if (t.dataset.act === 'toggle-group') { rhState.group = !rhState.group; return rhRender(); }
  if (t.dataset.act === 'export-csv') return rhExportCsv();
  if (t.dataset.act === 'clear') {
    const sel = $('rh-owner-filter'); if (sel) sel.value = 'all';
    $('rh-type-filter').value = 'all'; $('rh-search').value = ''; rhState.tab = 'all';
    return rhRender();
  }
  if (t.dataset.act === 'toggle-all') {
    const open = t.dataset.state !== 'open';
    rhSort(rhBase().filter(r => rhTabMatch(r, rhState.tab))).forEach(r => { if (open) rhState.open.add(r.id); else rhState.open.delete(r.id); });
    return rhRender();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { rhCloseInvoice(); rhHelp(false); rhToggleWa(false); document.querySelectorAll('.rh-more[open]').forEach(d => d.removeAttribute('open')); return; }
  const tag = (e.target.tagName || '').toLowerCase();
  if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.metaKey || e.ctrlKey || e.altKey) return;
  const k = e.key;
  if (k === '/') { e.preventDefault(); $('rh-search').focus(); }
  else if (k === '?') { e.preventDefault(); rhHelp(true); }
  else if (k === 'e') { const b = $('rh-toggle-all'); if (b) b.click(); }
  else if (k === 'g') { const b = $('rh-toggle-group'); if (b) b.click(); }
  else if (k === 'v') { rhSetView(rhState.view === 'compact' ? 'comfortable' : 'compact'); }
  else if (k === 't') { const b = $('rh-theme-toggle'); if (b) b.click(); }
  else if (/^[1-5]$/.test(k)) { rhState.tab = ['all', 'active', 'expiring', 'expired', 'unpaid'][Number(k) - 1]; rhRender(); }
});

['rh-owner-filter', 'rh-type-filter', 'rh-sort'].forEach(id => { const el = $(id); if (el) el.addEventListener('change', rhRender); });
$('rh-search').addEventListener('input', rhRender);

(function init() {
  rhLoadPrefs();
  const tb = $('rh-toolbar');
  if (tb) { const chk = () => tb.classList.toggle('is-stuck', tb.getBoundingClientRect().top <= 1); window.addEventListener('scroll', chk, { passive: true }); chk(); }
  const m = /^#svc-(\d+)$/.exec(location.hash);
  if (m) { const id = Number(m[1]); if (RH_DATA.some(r => r.id === id)) { rhState.open.add(id); rhState.highlight = id; } }
  rhRender();
})();
</script>

<?php
get_footer();
