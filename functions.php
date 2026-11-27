<?php
if (!defined('ABSPATH')) {
    exit;
}

define('RANSHOSTING_VERSION', '2.4.0');
define('RANSHOSTING_WA_NUMBER', '8801575115472'); // default; can be changed under RansHosting → Reminders & Settings

/* =========================================================================
   Helpers
   ========================================================================= */
function ranshosting_wa_number() {
    $n = preg_replace('/\D+/', '', (string) get_option('rh_wa_number', ''));
    return $n !== '' ? $n : RANSHOSTING_WA_NUMBER;
}
function ranshosting_wa_display() {
    $n = ranshosting_wa_number();
    return $n === RANSHOSTING_WA_NUMBER ? '+880 1575-115472' : '+' . $n;
}
function ranshosting_contact_email() {
    $e = sanitize_email((string) get_option('rh_contact_email', ''));
    return $e !== '' ? $e : 'ratul@ranstechdigital.com';
}
function ranshosting_client_key($owner) {
    return substr(hash_hmac('sha256', strtolower(trim((string) $owner)), wp_salt('auth')), 0, 16);
}
function ranshosting_service_types() {
    return [
        'hosting'     => __('Web hosting', 'ranshosting'),
        'email'       => __('Email accounts', 'ranshosting'),
        'domain'      => __('Domain registration', 'ranshosting'),
        'maintenance' => __('Website maintenance', 'ranshosting'),
        'vps'         => __('VPS / cloud server', 'ranshosting'),
    ];
}
function ranshosting_billing_cycles() {
    return [
        'monthly'   => ['label' => __('Monthly', 'ranshosting'),   'months' => 1],
        'quarterly' => ['label' => __('Quarterly', 'ranshosting'), 'months' => 3],
        'yearly'    => ['label' => __('Yearly', 'ranshosting'),    'months' => 12],
        'biennial'  => ['label' => __('Every 2 years', 'ranshosting'), 'months' => 24],
    ];
}
function ranshosting_get_meta($post_id, $key, $default = '') {
    $val = get_post_meta($post_id, '_rh_' . $key, true);
    return ($val === '' || $val === false) ? $default : $val;
}
function ranshosting_valid_date($s) {
    if (!is_string($s) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $s, $parts)) return false;
    return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

/** Move to the target month, clamping dates such as January 31 to its last day. */
function ranshosting_renewal_end_date($base, $months) {
    $date = new DateTimeImmutable($base, new DateTimeZone('UTC'));
    $target = $date->modify('first day of this month')->modify('+' . (int) $months . ' months');
    $day = min((int) $date->format('j'), (int) $target->format('t'));
    return $target->format('Y-m-') . str_pad((string) $day, 2, '0', STR_PAD_LEFT);
}

/** Usernames and passwords: keep every character the person typed, only drop control characters. */
function ranshosting_sanitize_secret($v) {
    return trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $v));
}

/** Quick-pick labels offered when adding a login to a service. */
function ranshosting_login_presets() {
    return ['cPanel', 'WordPress admin', 'Webmail', 'Email account', 'Domain registrar', 'DNS (Cloudflare etc.)', 'FTP / SFTP', 'SSH', 'Database', 'Backup dashboard', 'Other'];
}

/**
 * Logins for one service: a list of [label, url, user, pass, note].
 * Services saved before v2.4 only have the three fixed login sets, so fall back to those.
 */
function ranshosting_get_logins($post_id) {
    if (metadata_exists('post', $post_id, '_rh_logins')) {
        $l = get_post_meta($post_id, '_rh_logins', true);
        return is_array($l) ? array_values($l) : [];
    }
    return ranshosting_legacy_logins($post_id);
}
function ranshosting_legacy_logins($post_id) {
    $out = [];
    $sets = ['web' => 'Website admin', 'dom' => 'Domain registrar', 'mail' => 'Email / webmail'];
    foreach ($sets as $k => $label) {
        $row = [
            'label' => $label,
            'url'   => (string) ranshosting_get_meta($post_id, $k . '_url'),
            'user'  => (string) ranshosting_get_meta($post_id, $k . '_user'),
            'pass'  => (string) ranshosting_get_meta($post_id, $k . '_pass'),
            'note'  => '',
        ];
        if ($row['url'] !== '' || $row['user'] !== '' || $row['pass'] !== '') $out[] = $row;
    }
    return $out;
}

/** All clients, A to Z. */
function ranshosting_clients($statuses = ['publish', 'draft', 'private']) {
    return get_posts([
        'post_type' => 'rans_client', 'post_status' => $statuses, 'posts_per_page' => -1,
        'orderby' => 'title', 'order' => 'ASC',
    ]);
}
/** Services that belong to one client. */
function ranshosting_client_services($client_id, $statuses = 'any') {
    return get_posts([
        'post_type' => 'rans_hosting_service', 'post_status' => $statuses, 'posts_per_page' => -1,
        'meta_key' => '_rh_client_id', 'meta_value' => (int) $client_id,
        'orderby' => 'title', 'order' => 'ASC',
    ]);
}
function ranshosting_client_portal_link($name) {
    $args = ['client' => $name];
    if (get_option('rh_portal_access', 'open') === 'key') $args['key'] = ranshosting_client_key($name);
    return add_query_arg(array_map('rawurlencode', $args), home_url('/'));
}

/* =========================================================================
   Theme setup, assets, privacy
   ========================================================================= */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', ['style', 'script']);
});

// The portal lists client data: keep it out of search engines.
add_filter('wp_robots', 'wp_robots_no_robots');

add_action('wp_enqueue_scripts', function () {
    $style_path = get_stylesheet_directory() . '/style.css';
    $style_ver  = file_exists($style_path) ? filemtime($style_path) : RANSHOSTING_VERSION;

    wp_enqueue_style('ranshosting-style', get_stylesheet_directory_uri() . '/style.css', [], $style_ver);
}, 99);

/* =========================================================================
   Custom post types: Clients and their Services
   ========================================================================= */
add_action('init', function () {
    register_post_type('rans_hosting_service', [
        'labels' => [
            'name'          => __('Services', 'ranshosting'),
            'singular_name' => __('Service', 'ranshosting'),
            'all_items'     => __('All Services', 'ranshosting'),
            'add_new'       => __('Add New Service', 'ranshosting'),
            'add_new_item'  => __('Add New Service', 'ranshosting'),
            'edit_item'     => __('Edit Service', 'ranshosting'),
            'menu_name'     => __('RansHosting', 'ranshosting'),
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => true,
        'menu_icon'       => 'dashicons-cloud',
        'supports'        => ['title'],
        'capability_type' => 'post',
    ]);

    register_post_type('rans_client', [
        'labels' => [
            'name'          => __('Clients', 'ranshosting'),
            'singular_name' => __('Client', 'ranshosting'),
            'add_new'       => __('Add New Client', 'ranshosting'),
            'add_new_item'  => __('Add New Client', 'ranshosting'),
            'edit_item'     => __('Edit Client', 'ranshosting'),
            'all_items'     => __('Clients', 'ranshosting'),
            'title_field_placeholder' => __('Client or business name', 'ranshosting'),
        ],
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => 'edit.php?post_type=rans_hosting_service',
        'supports'        => ['title'],
        'capability_type' => 'post',
    ]);
});

add_filter('enter_title_here', function ($text, $post) {
    if ($post->post_type === 'rans_client') return __('Client or business name', 'ranshosting');
    if ($post->post_type === 'rans_hosting_service') return __('Domain (e.g. example.com)', 'ranshosting');
    return $text;
}, 10, 2);

/* New service opened from a client screen: suggest that client's domain as the title. */
add_filter('default_title', function ($title, $post) {
    if ($post->post_type !== 'rans_hosting_service' || empty($_GET['rh_client'])) return $title;
    $svc = ranshosting_client_services((int) $_GET['rh_client']);
    return $svc ? $svc[0]->post_title : $title;
}, 10, 2);

/* =========================================================================
   Client screen
   ========================================================================= */
add_action('add_meta_boxes', function () {
    add_meta_box('ranshosting_client_details', __('Client contact', 'ranshosting'), 'ranshosting_render_client_box', 'rans_client', 'normal', 'high');
    add_meta_box('ranshosting_client_services', __('Services for this client', 'ranshosting'), 'ranshosting_render_client_services_box', 'rans_client', 'normal', 'default');
    add_meta_box('ranshosting_service_details', __('Service, logins, technical health & billing', 'ranshosting'), 'ranshosting_render_meta_box', 'rans_hosting_service', 'normal', 'high');
});

function ranshosting_admin_css() {
    ?>
    <style>
      .rh-admin-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 14px; }
      .rh-admin-box { background: #f7faf5; border: 1px solid #dbe4d8; padding: 16px; border-radius: 14px; margin-bottom: 14px; }
      .rh-admin-box h4 { margin: 0 0 10px; font-size: 13px; color: #0f172a; }
      .rh-admin-field label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; }
      .rh-admin-field input, .rh-admin-field select, .rh-admin-field textarea { width: 100%; }
      .rh-login { background: #fff; border: 1px solid #cbd5e1; border-left: 4px solid #508777; border-radius: 6px; padding: 10px; margin-bottom: 10px; }
      .rh-login-foot { display: flex; justify-content: space-between; align-items: end; gap: 12px; }
      .rh-login-foot .rh-admin-field { flex: 1; }
      .rh-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
      .rh-chips .button { border-radius: 999px; }
      .rh-svc-table td, .rh-svc-table th { vertical-align: middle; }
      @media (max-width: 782px) { .rh-admin-grid { grid-template-columns: 1fr; } }
    </style>
    <?php
}

function ranshosting_render_client_box($post) {
    wp_nonce_field('ranshosting_save_client', 'ranshosting_client_nonce');
    ranshosting_admin_css();
    $email = ranshosting_get_meta($post->ID, 'c_email');
    $phone = ranshosting_get_meta($post->ID, 'c_phone');
    $notes = ranshosting_get_meta($post->ID, 'c_notes');
    ?>
    <?php if (ranshosting_get_meta($post->ID, 'demo')): ?>
      <div class="notice notice-info inline"><p>This is a <strong>demo</strong> client. It never receives reminder emails.</p></div>
    <?php endif; ?>
    <div class="rh-admin-grid">
      <div class="rh-admin-field"><label>Reminder email</label><input type="email" name="rh_c_email" value="<?php echo esc_attr($email); ?>" /></div>
      <div class="rh-admin-field"><label>WhatsApp number</label><input type="text" name="rh_c_phone" value="<?php echo esc_attr($phone); ?>" placeholder="+88017..." /></div>
      <div class="rh-admin-field"><label>Private portal link</label>
        <?php if ($post->post_status !== 'auto-draft' && $post->post_title !== ''): ?>
          <input type="text" readonly onclick="this.select()" value="<?php echo esc_attr(ranshosting_client_portal_link($post->post_title)); ?>" />
        <?php else: ?>
          <input type="text" readonly value="Available after you save the client" />
        <?php endif; ?>
      </div>
    </div>
    <div class="rh-admin-field"><label>Internal notes</label><textarea name="rh_c_notes" rows="2"><?php echo esc_textarea($notes); ?></textarea></div>
    <p class="description" style="margin-top:8px;">Contact details here are copied onto every service of this client, so reminders and invoices always use them. Renaming a client changes its private portal link.</p>
    <?php
}

function ranshosting_render_client_services_box($post) {
    $types = ranshosting_service_types();
    if ($post->post_status === 'auto-draft') {
        echo '<p>Save this client first, then add its services (hosting, email accounts, domain registrations, website maintenance) from here.</p>';
        return;
    }
    $services = ranshosting_client_services($post->ID);
    $add = function ($type) use ($post) {
        return admin_url('post-new.php?post_type=rans_hosting_service&rh_client=' . (int) $post->ID . '&rh_type=' . rawurlencode($type));
    };
    ?>
    <p>
      <?php foreach ($types as $k => $label): ?>
        <a class="button button-primary" style="margin:0 6px 6px 0" href="<?php echo esc_url($add($k)); ?>">+ <?php echo esc_html($label); ?></a>
      <?php endforeach; ?>
    </p>
    <?php if (!$services): ?>
      <p class="description">No services yet. Use the buttons above to add the first one.</p>
      <?php return; ?>
    <?php endif; ?>
    <table class="widefat striped rh-svc-table">
      <thead><tr><th>Domain</th><th>Service</th><th>Logins</th><th>Term ends</th><th>Payment</th></tr></thead>
      <tbody>
      <?php foreach ($services as $s):
          $t    = ranshosting_get_meta($s->ID, 'service_type', 'hosting');
          $end  = ranshosting_get_meta($s->ID, 'end_date');
          $pay  = ranshosting_get_meta($s->ID, 'payment_status', 'Unpaid');
          $nlog = count(ranshosting_get_logins($s->ID));
      ?>
        <tr>
          <td><strong><a href="<?php echo esc_url(get_edit_post_link($s->ID)); ?>"><?php echo esc_html($s->post_title ?: '(no domain)'); ?></a></strong></td>
          <td><?php echo esc_html(($types[$t] ?? $t) . (ranshosting_get_meta($s->ID, 'plan') ? ', ' . ranshosting_get_meta($s->ID, 'plan') : '')); ?></td>
          <td><?php echo (int) $nlog; ?></td>
          <td><?php echo esc_html($end ?: '—'); ?></td>
          <td><?php echo esc_html($pay); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

/** Copies a client's name and contact details onto each of its services (reminders and the portal read them from there). */
function ranshosting_sync_client_to_service($service_id, $client_id) {
    $c = get_post($client_id);
    if (!$c || $c->post_type !== 'rans_client') return;
    update_post_meta($service_id, '_rh_client_id', (int) $client_id);
    update_post_meta($service_id, '_rh_owner', wp_slash($c->post_title));
    update_post_meta($service_id, '_rh_client_email', wp_slash((string) ranshosting_get_meta($client_id, 'c_email')));
    update_post_meta($service_id, '_rh_client_phone', wp_slash((string) ranshosting_get_meta($client_id, 'c_phone')));
}

add_action('save_post_rans_client', function ($post_id, $post) {
    if (!isset($_POST['ranshosting_client_nonce']) || !wp_verify_nonce($_POST['ranshosting_client_nonce'], 'ranshosting_save_client')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    update_post_meta($post_id, '_rh_c_email', sanitize_email(wp_unslash($_POST['rh_c_email'] ?? '')));
    update_post_meta($post_id, '_rh_c_phone', sanitize_text_field(wp_unslash($_POST['rh_c_phone'] ?? '')));
    update_post_meta($post_id, '_rh_c_notes', sanitize_textarea_field(wp_unslash($_POST['rh_c_notes'] ?? '')));

    foreach (ranshosting_client_services($post_id) as $svc) {
        ranshosting_sync_client_to_service($svc->ID, $post_id);
    }
}, 10, 2);

/* =========================================================================
   Service screen
   ========================================================================= */
function ranshosting_meta_fields() {
    return [
        'client_id', 'service_type', 'plan', 'billing_cycle', 'notes', 'domain_emails',
        'start_date', 'end_date', 'domain_expiry', 'ssl_status',
        'server_ip', 'registrar', 'nameservers',
        'cost', 'paid', 'currency', 'payment_status', 'invoice_no', 'payment_link',
    ];
}

/** One login row. $i is the array index (or the placeholder __i__ for the JS template). */
function ranshosting_login_row_html($i, $l = []) {
    $l = wp_parse_args($l, ['label' => '', 'url' => '', 'user' => '', 'pass' => '', 'note' => '']);
    $n = 'rh_logins[' . $i . ']';
    ob_start();
    ?>
    <div class="rh-login">
      <div class="rh-admin-grid">
        <div class="rh-admin-field"><label>Label</label><input type="text" name="<?php echo esc_attr($n); ?>[label]" value="<?php echo esc_attr($l['label']); ?>" list="rh-login-presets" placeholder="cPanel, Webmail, Registrar..." /></div>
        <div class="rh-admin-field"><label>Login URL</label><input type="url" name="<?php echo esc_attr($n); ?>[url]" value="<?php echo esc_attr($l['url']); ?>" placeholder="https://" /></div>
        <div class="rh-admin-field"><label>Username</label><input type="text" autocomplete="off" name="<?php echo esc_attr($n); ?>[user]" value="<?php echo esc_attr($l['user']); ?>" /></div>
        <div class="rh-admin-field"><label>Password</label><input type="text" autocomplete="off" name="<?php echo esc_attr($n); ?>[pass]" value="<?php echo esc_attr($l['pass']); ?>" /></div>
      </div>
      <div class="rh-login-foot">
        <div class="rh-admin-field"><label>Note (optional)</label><input type="text" name="<?php echo esc_attr($n); ?>[note]" value="<?php echo esc_attr($l['note']); ?>" placeholder="e.g. 2FA goes to the owner's phone" /></div>
        <button type="button" class="button-link-delete rh-login-remove">Remove login</button>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

function ranshosting_sub_services($post_id) {
    $items = ranshosting_get_meta($post_id, 'sub_services', []);
    return is_array($items) ? $items : [];
}

function ranshosting_service_cost($post_id) {
    $items = ranshosting_sub_services($post_id);
    return $items ? round(array_sum(array_column($items, 'cost')), 2) : (float) ranshosting_get_meta($post_id, 'cost', 0);
}

function ranshosting_render_sub_service_row($index, $item = []) {
    $prefix = 'rh_sub_services[' . $index . ']';
    ?>
    <fieldset class="rh-admin-box rh-sub-service">
      <legend><?php esc_html_e('Sub-service', 'ranshosting'); ?></legend>
      <div class="rh-admin-grid">
        <?php foreach ([
            'type' => [__('Service type', 'ranshosting'), ranshosting_service_types(), 'hosting'],
            'cycle' => [__('Billing cycle', 'ranshosting'), array_map(function ($cycle) { return $cycle['label']; }, ranshosting_billing_cycles()), 'yearly'],
        ] as $key => [$label, $options, $default]) :
            ?>
            <div class="rh-admin-field"><label><?php echo esc_html($label); ?>
              <select name="<?php echo esc_attr($prefix . '[' . $key . ']'); ?>">
                <?php foreach ($options as $value => $text) : ?>
                  <option value="<?php echo esc_attr($value); ?>" <?php selected($item[$key] ?? $default, $value); ?>><?php echo esc_html($text); ?></option>
                <?php endforeach; ?>
              </select>
            </label></div>
        <?php endforeach; ?>
        <?php foreach ([
            'name' => [__('Description (optional)', 'ranshosting'), 'text'],
            'start' => [__('Start date', 'ranshosting'), 'date'],
            'end' => [__('End date', 'ranshosting'), 'date'],
            'cost' => [__('Amount per term', 'ranshosting'), 'number'],
        ] as $key => [$label, $type]) : ?>
          <div class="rh-admin-field"><label><?php echo esc_html($label); ?>
            <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($prefix . '[' . $key . ']'); ?>"
              value="<?php echo esc_attr($item[$key] ?? ''); ?>" <?php if ($key === 'cost') echo 'min="0" max="999999999" step="0.01" required data-rh-item-cost'; ?> />
          </label></div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="button" data-rh-remove-item><?php esc_html_e('Remove sub-service', 'ranshosting'); ?></button>
    </fieldset>
    <?php
}

function ranshosting_render_sub_services($post_id) {
    $items = ranshosting_sub_services($post_id);
    ?>
    <div class="rh-admin-box">
      <h4><?php esc_html_e('Sub-services', 'ranshosting'); ?></h4>
      <p><?php esc_html_e('Add each hosting plan, domain registration, or email package separately. Amounts use the invoice currency below. Sub-service dates don’t change the main service’s reminder schedule.', 'ranshosting'); ?></p>
      <input type="hidden" name="rh_sub_services_submitted" value="1" />
      <div id="rh-sub-service-list">
        <?php foreach ($items as $index => $item) ranshosting_render_sub_service_row($index, $item); ?>
      </div>
      <template id="rh-sub-service-template"><?php ranshosting_render_sub_service_row('__INDEX__'); ?></template>
      <button type="button" class="button" id="rh-add-sub-service"><?php esc_html_e('Add sub-service', 'ranshosting'); ?></button>
    </div>
    <?php
}

function ranshosting_render_meta_box($post) {
    wp_nonce_field('ranshosting_save_meta', 'ranshosting_meta_nonce');
    $m = [];
    foreach (ranshosting_meta_fields() as $f) {
        $m[$f] = ranshosting_get_meta($post->ID, $f);
    }
    // A new service opened from a client screen arrives with the client and type chosen.
    if (!$m['client_id'] && !empty($_GET['rh_client'])) $m['client_id'] = (int) $_GET['rh_client'];
    if (!$m['service_type'] && !empty($_GET['rh_type']) && isset(ranshosting_service_types()[sanitize_key($_GET['rh_type'])])) {
        $m['service_type'] = sanitize_key($_GET['rh_type']);
    }
    $clients = ranshosting_clients();
    $logins  = ranshosting_get_logins($post->ID);
    if (!$logins && $post->post_status === 'auto-draft') $logins = [[]]; // start a new service with one empty login row
    $sent = ranshosting_get_meta($post->ID, 'sent_reminders', []);
    $sent = is_array($sent) ? $sent : [];
    ranshosting_admin_css();
    ?>
    <?php if (ranshosting_get_meta($post->ID, 'demo')): ?>
      <div class="notice notice-info inline"><p>This is a <strong>demo</strong> record. It never receives reminder emails.</p></div>
    <?php endif; ?>

    <div class="rh-admin-box">
      <h4>Client</h4>
      <?php if (!$clients): ?>
        <p>You have no clients yet. <a href="<?php echo esc_url(admin_url('post-new.php?post_type=rans_client')); ?>"><strong>Add a client first</strong></a>, then add services under them.</p>
      <?php else: ?>
        <div class="rh-admin-grid">
          <div class="rh-admin-field" style="grid-column: span 2;">
            <label>This service belongs to</label>
            <select name="rh_client_id" required>
              <option value="">Choose a client...</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?php echo (int) $c->ID; ?>" <?php selected((int) $m['client_id'], $c->ID); ?>><?php echo esc_html($c->post_title); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="rh-admin-field"><label>&nbsp;</label><a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=rans_client')); ?>" target="_blank" rel="noopener">New client (opens in a new tab)</a></div>
        </div>
        <p class="description">The client's email and WhatsApp number come from the client record.</p>
      <?php endif; ?>
    </div>

    <div class="rh-admin-box">
      <h4>Service</h4>
      <div class="rh-admin-grid">
        <div class="rh-admin-field">
          <label>Service type</label>
          <select name="rh_service_type">
            <?php foreach (ranshosting_service_types() as $k => $label): ?>
              <option value="<?php echo esc_attr($k); ?>" <?php selected($m['service_type'] ?: 'hosting', $k); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="rh-admin-field"><label>Plan name</label><input type="text" name="rh_plan" value="<?php echo esc_attr($m['plan']); ?>" placeholder="Business Hosting" /></div>
        <div class="rh-admin-field">
          <label>Billing cycle</label>
          <select name="rh_billing_cycle">
            <?php foreach (ranshosting_billing_cycles() as $k => $c): ?>
              <option value="<?php echo esc_attr($k); ?>" <?php selected($m['billing_cycle'] ?: 'yearly', $k); ?>><?php echo esc_html($c['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="rh-admin-field" style="margin-bottom:12px;">
        <label>Email accounts on this service (comma-separated)</label>
        <input type="text" name="rh_domain_emails" value="<?php echo esc_attr($m['domain_emails']); ?>" placeholder="info@domain.com, billing@domain.com" />
      </div>
      <div class="rh-admin-field">
        <label>Notes (shown on the portal card)</label>
        <textarea name="rh_notes" rows="2"><?php echo esc_textarea($m['notes']); ?></textarea>
      </div>
    </div>

    <div class="rh-admin-box">
      <h4>Logins for this service</h4>
      <p class="description" style="margin-top:0;">Add as many as this service needs: control panel, WordPress admin, each mailbox, registrar, DNS, FTP and so on. Leave a row empty and it is ignored.</p>
      <input type="hidden" name="rh_logins_submitted" value="1" />
      <datalist id="rh-login-presets">
        <?php foreach (ranshosting_login_presets() as $p): ?><option value="<?php echo esc_attr($p); ?>"></option><?php endforeach; ?>
      </datalist>
      <div id="rh-logins">
        <?php foreach ($logins as $i => $l) echo ranshosting_login_row_html($i, $l); // phpcs:ignore WordPress.Security.EscapeOutput ?>
      </div>
      <script type="text/template" id="rh-login-tpl"><?php echo ranshosting_login_row_html('__i__'); // phpcs:ignore WordPress.Security.EscapeOutput ?></script>
      <p style="margin-bottom:0;"><button type="button" class="button button-primary" id="rh-add-login">+ Add login</button></p>
      <div class="rh-chips" aria-label="Quick add">
        <?php foreach (['cPanel', 'WordPress admin', 'Webmail', 'Domain registrar', 'DNS (Cloudflare etc.)', 'FTP / SFTP', 'SSH'] as $p): ?>
          <button type="button" class="button button-small rh-chip" data-label="<?php echo esc_attr($p); ?>">+ <?php echo esc_html($p); ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function () {
      var wrap = document.getElementById('rh-logins');
      var tpl  = document.getElementById('rh-login-tpl');
      if (!wrap || !tpl) return;
      var n = Date.now();
      function add(label) {
        var holder = document.createElement('div');
        holder.innerHTML = tpl.innerHTML.replace(/__i__/g, 'n' + (n++));
        var row = holder.firstElementChild;
        if (label) row.querySelector('input[name$="[label]"]').value = label;
        wrap.appendChild(row);
        row.querySelector(label ? 'input[name$="[url]"]' : 'input[name$="[label]"]').focus();
      }
      document.getElementById('rh-add-login').addEventListener('click', function () { add(''); });
      document.querySelectorAll('.rh-chip').forEach(function (b) { b.addEventListener('click', function () { add(b.dataset.label); }); });
      wrap.addEventListener('click', function (e) {
        if (!e.target.classList.contains('rh-login-remove')) return;
        var row = e.target.closest('.rh-login');
        if (row && confirm('Remove this login? It is deleted when you click Update.')) row.remove();
      });
    })();
    </script>

    <div class="rh-admin-box">
      <h4>Technical &amp; Domain Health</h4>
      <div class="rh-admin-grid">
        <div class="rh-admin-field"><label>Domain Expiry Date</label><input type="date" name="rh_domain_expiry" value="<?php echo esc_attr($m['domain_expiry']); ?>" /></div>
        <div class="rh-admin-field">
          <label>SSL Status</label>
          <select name="rh_ssl_status">
            <option value="active" <?php selected($m['ssl_status'], 'active'); ?>>Active (HTTPS)</option>
            <option value="expiring" <?php selected($m['ssl_status'], 'expiring'); ?>>Expiring Soon</option>
            <option value="none" <?php selected($m['ssl_status'], 'none'); ?>>No SSL</option>
          </select>
        </div>
        <div class="rh-admin-field"><label>Server IP</label><input type="text" name="rh_server_ip" value="<?php echo esc_attr($m['server_ip']); ?>" /></div>
        <div class="rh-admin-field"><label>Registrar</label><input type="text" name="rh_registrar" value="<?php echo esc_attr($m['registrar']); ?>" /></div>
        <div class="rh-admin-field" style="grid-column: span 2;"><label>Nameservers</label><input type="text" name="rh_nameservers" value="<?php echo esc_attr($m['nameservers']); ?>" /></div>
      </div>
    </div>

    <?php ranshosting_render_sub_services($post->ID); ?>
    <div class="rh-admin-box">
      <h4>Main Service Invoice, Dates &amp; Payment</h4>
      <div class="rh-admin-grid">
        <div class="rh-admin-field"><label>Term Start Date</label><input type="date" name="rh_start_date" value="<?php echo esc_attr($m['start_date']); ?>" required /></div>
        <div class="rh-admin-field"><label>Term End Date</label><input type="date" name="rh_end_date" value="<?php echo esc_attr($m['end_date']); ?>" required /></div>
        <div class="rh-admin-field">
          <label>Payment Status</label>
          <select name="rh_payment_status">
            <option value="Unpaid" <?php selected($m['payment_status'], 'Unpaid'); ?>>Unpaid</option>
            <option value="Paid" <?php selected($m['payment_status'], 'Paid'); ?>>Paid</option>
            <option value="Partial" <?php selected($m['payment_status'], 'Partial'); ?>>Partial</option>
          </select>
        </div>
        <div class="rh-admin-field" id="rh-standalone-cost"><label>Service Cost (per term)<input type="number" step="0.01" min="0" name="rh_cost" value="<?php echo esc_attr($m['cost']); ?>" /></label></div>
        <div class="rh-admin-field"><label for="rh-invoice-total">Invoice total</label><output id="rh-invoice-total" aria-live="polite"><?php echo esc_html(number_format_i18n(ranshosting_service_cost($post->ID), 2)); ?></output></div>
        <div class="rh-admin-field"><label>Amount paid so far (for Partial)</label><input type="number" step="0.01" min="0" name="rh_paid" value="<?php echo esc_attr($m['paid']); ?>" /></div>
        <div class="rh-admin-field">
          <label>Currency</label>
          <select name="rh_currency">
            <option value="৳" <?php selected($m['currency'], '৳'); ?>>BDT (৳)</option>
            <option value="$" <?php selected($m['currency'], '$'); ?>>USD ($)</option>
            <option value="€" <?php selected($m['currency'], '€'); ?>>EUR (€)</option>
            <option value="£" <?php selected($m['currency'], '£'); ?>>GBP (£)</option>
          </select>
        </div>
        <div class="rh-admin-field"><label>Invoice No</label><input type="text" name="rh_invoice_no" value="<?php echo esc_attr($m['invoice_no']); ?>" /></div>
      </div>
      <div class="rh-admin-field">
        <label>Renewal Payment Link</label>
        <input type="url" name="rh_payment_link" value="<?php echo esc_attr($m['payment_link']); ?>" placeholder="https://..." />
      </div>
      <p class="description" style="margin-top:10px;">
        Reminders already sent for this term: <strong><?php echo $sent ? esc_html(implode(', ', $sent)) : 'none'; ?></strong>.
        Changing the end date starts a fresh reminder cycle automatically.
      </p>
    </div>
    <script>
    (() => {
      const list = document.getElementById('rh-sub-service-list');
      const add = document.getElementById('rh-add-sub-service');
      const template = document.getElementById('rh-sub-service-template');
      const manual = document.getElementById('rh-standalone-cost');
      const cost = manual.querySelector('input');
      const currency = document.querySelector('[name="rh_currency"]');
      const total = document.getElementById('rh-invoice-total');
      let index = list.children.length;
      function update() {
        const inputs = Array.from(list.querySelectorAll('[data-rh-item-cost]'));
        manual.hidden = inputs.length > 0;
        cost.disabled = inputs.length > 0;
        cost.required = !inputs.length;
        add.disabled = inputs.length >= 40;
        const cents = (inputs.length ? inputs : [cost]).reduce((sum, input) => sum + Math.round((Number(input.value) || 0) * 100), 0);
        total.value = currency.value + ' ' + (cents / 100).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
      }
      add.addEventListener('click', () => {
        if (list.children.length >= 40) return;
        list.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(index++)));
        update();
        list.lastElementChild.querySelector('select').focus();
      });
      list.addEventListener('click', event => {
        const button = event.target.closest('[data-rh-remove-item]');
        if (!button) return;
        button.closest('.rh-sub-service').remove();
        update();
        add.focus();
      });
      list.addEventListener('input', update);
      cost.addEventListener('input', update);
      currency.addEventListener('change', update);
      update();
    })();
    </script>
    <?php
}

add_action('save_post_rans_hosting_service', function ($post_id) {
    if (!isset($_POST['ranshosting_meta_nonce']) || !wp_verify_nonce($_POST['ranshosting_meta_nonce'], 'ranshosting_save_meta')) {
        return;
    }
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    $map = [
        'service_type'   => 'sanitize_key',
        'plan'           => 'sanitize_text_field',
        'billing_cycle'  => 'sanitize_key',
        'notes'          => 'sanitize_textarea_field',
        'domain_emails'  => 'sanitize_text_field',
        'start_date'     => 'sanitize_text_field',
        'end_date'       => 'sanitize_text_field',
        'domain_expiry'  => 'sanitize_text_field',
        'ssl_status'     => 'sanitize_key',
        'server_ip'      => 'sanitize_text_field',
        'registrar'      => 'sanitize_text_field',
        'nameservers'    => 'sanitize_text_field',
        'cost'           => 'sanitize_text_field',
        'paid'           => 'sanitize_text_field',
        'currency'       => 'sanitize_text_field',
        'payment_status' => 'sanitize_text_field',
        'invoice_no'     => 'sanitize_text_field',
        'payment_link'   => 'esc_url_raw',
    ];

    $old_end = ranshosting_get_meta($post_id, 'end_date');

    foreach ($map as $key => $cb) {
        $field_name = 'rh_' . $key;
        if (!isset($_POST[$field_name])) continue;
        $val = call_user_func($cb, wp_unslash($_POST[$field_name]));

        // Whitelist / format checks so bad input cannot break the portal.
        if (in_array($key, ['start_date', 'end_date', 'domain_expiry'], true) && $val !== '' && !ranshosting_valid_date($val)) continue;
        if ($key === 'service_type' && !isset(ranshosting_service_types()[$val])) $val = 'hosting';
        if ($key === 'billing_cycle' && !isset(ranshosting_billing_cycles()[$val])) $val = 'yearly';
        if ($key === 'ssl_status' && !in_array($val, ['active', 'expiring', 'none'], true)) $val = 'active';
        if ($key === 'payment_status' && !in_array($val, ['Paid', 'Unpaid', 'Partial'], true)) $val = 'Unpaid';
        if (in_array($key, ['cost', 'paid'], true)) $val = $val === '' ? '' : (string) max(0, (float) $val);

        update_post_meta($post_id, '_rh_' . $key, wp_slash($val));
    }

    if (isset($_POST['rh_sub_services_submitted'])) {
        $rows = isset($_POST['rh_sub_services']) && is_array($_POST['rh_sub_services']) ? wp_unslash($_POST['rh_sub_services']) : [];
        $items = [];
        $types = ranshosting_service_types();
        $cycles = ranshosting_billing_cycles();
        foreach (array_slice($rows, 0, 40) as $row) {
            if (!is_array($row)) continue;
            $row = array_filter($row, 'is_string');
            $type = $row['type'] ?? '';
            $cycle = $row['cycle'] ?? '';
            $start = $row['start'] ?? '';
            $end = $row['end'] ?? '';
            $items[] = [
                'type' => isset($types[$type]) ? $type : 'hosting',
                'cycle' => isset($cycles[$cycle]) ? $cycle : 'yearly',
                'name' => sanitize_text_field($row['name'] ?? ''),
                'start' => ranshosting_valid_date($start) ? $start : '',
                'end' => ranshosting_valid_date($end) ? $end : '',
                'cost' => round(min(999999999, max(0, (float) ($row['cost'] ?? 0))), 2),
            ];
        }
        update_post_meta($post_id, '_rh_sub_services', wp_slash($items));
    }

    // Client: copy name, email and phone from the chosen client record.
    $cid = isset($_POST['rh_client_id']) ? (int) $_POST['rh_client_id'] : 0;
    if ($cid && get_post_type($cid) === 'rans_client') {
        ranshosting_sync_client_to_service($post_id, $cid);
    }

    // Logins: any number of rows. Only touched when the form actually carried the login box.
    if (isset($_POST['rh_logins_submitted'])) {
        $raw   = (isset($_POST['rh_logins']) && is_array($_POST['rh_logins'])) ? wp_unslash($_POST['rh_logins']) : [];
        $clean = [];
        foreach (array_slice($raw, 0, 60) as $row) {
            if (!is_array($row)) continue;
            $l = [
                'label' => sanitize_text_field($row['label'] ?? ''),
                'url'   => esc_url_raw(trim((string) ($row['url'] ?? ''))),
                'user'  => ranshosting_sanitize_secret($row['user'] ?? ''),
                'pass'  => ranshosting_sanitize_secret($row['pass'] ?? ''),
                'note'  => sanitize_text_field($row['note'] ?? ''),
            ];
            if (implode('', $l) === '') continue;
            $clean[] = $l;
        }
        // wp_slash: update_post_meta strips one level of backslashes, which would corrupt passwords containing "\".
        update_post_meta($post_id, '_rh_logins', wp_slash($clean));
    }

    // New term → new reminder cycle.
    $new_end = ranshosting_get_meta($post_id, 'end_date');
    if ($old_end !== $new_end) {
        delete_post_meta($post_id, '_rh_sent_reminders');
    }
});

/* =========================================================================
   Admin lists: columns, sorting, filter by client, quick actions
   ========================================================================= */
add_filter('manage_rans_hosting_service_posts_columns', function ($cols) {
    return [
        'cb'         => $cols['cb'],
        'title'      => __('Domain', 'ranshosting'),
        'rh_client'  => __('Client', 'ranshosting'),
        'rh_service' => __('Service', 'ranshosting'),
        'rh_logins'  => __('Logins', 'ranshosting'),
        'rh_ends'    => __('Term ends', 'ranshosting'),
        'rh_payment' => __('Payment', 'ranshosting'),
        'rh_ssl'     => __('SSL', 'ranshosting'),
    ];
});

add_action('manage_rans_hosting_service_posts_custom_column', function ($col, $post_id) {
    switch ($col) {
        case 'rh_client':
            $cid = (int) ranshosting_get_meta($post_id, 'client_id');
            $name = ranshosting_get_meta($post_id, 'owner', '—');
            if ($cid && get_post($cid)) echo '<a href="' . esc_url(get_edit_post_link($cid)) . '"><strong>' . esc_html($name) . '</strong></a>';
            else echo esc_html($name);
            $mail = ranshosting_get_meta($post_id, 'client_email');
            if ($mail) echo '<br><span style="color:#64748b">' . esc_html($mail) . '</span>';
            break;
        case 'rh_service':
            $types = ranshosting_service_types();
            $t = ranshosting_get_meta($post_id, 'service_type', 'hosting');
            echo esc_html(($types[$t] ?? $t));
            $plan = ranshosting_get_meta($post_id, 'plan');
            if ($plan) echo '<br><span style="color:#64748b">' . esc_html($plan) . '</span>';
            if (ranshosting_get_meta($post_id, 'demo')) echo ' <span style="background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:4px;font-size:11px">Demo</span>';
            break;
        case 'rh_logins':
            echo (int) count(ranshosting_get_logins($post_id));
            break;
        case 'rh_ends':
            $end = ranshosting_get_meta($post_id, 'end_date');
            if (!$end) { echo '—'; break; }
            $days = (int) ceil((strtotime($end) - strtotime(wp_date('Y-m-d'))) / DAY_IN_SECONDS);
            $color = $days < 0 ? '#b91c1c' : ($days <= 45 ? '#b45309' : '#047857');
            $text  = $days < 0 ? sprintf('expired %dd ago', abs($days)) : sprintf('%dd left', $days);
            echo esc_html($end) . '<br><strong style="color:' . esc_attr($color) . '">' . esc_html($text) . '</strong>';
            break;
        case 'rh_payment':
            $st  = ranshosting_get_meta($post_id, 'payment_status', 'Unpaid');
            $cur = ranshosting_get_meta($post_id, 'currency', '৳');
            $cost = ranshosting_service_cost($post_id);
            echo '<strong>' . esc_html($st) . '</strong><br>' . esc_html($cur . number_format_i18n($cost, $cost == floor($cost) ? 0 : 2));
            break;
        case 'rh_ssl':
            $s = ranshosting_get_meta($post_id, 'ssl_status', 'active');
            echo esc_html(['active' => 'Active', 'expiring' => 'Expiring', 'none' => 'None'][$s] ?? $s);
            break;
    }
}, 10, 2);

add_filter('manage_edit-rans_hosting_service_sortable_columns', function ($cols) {
    $cols['rh_ends'] = 'rh_ends';
    $cols['rh_client'] = 'rh_client';
    return $cols;
});

/* Service list filters share the same options for rendering and validation. */
function ranshosting_service_filters() {
    return [
        'rh_type_filter' => [__('All service types', 'ranshosting'), ranshosting_service_types()],
        'rh_payment_filter' => [__('All payment statuses', 'ranshosting'), [
            'Unpaid' => __('Unpaid', 'ranshosting'), 'Partial' => __('Partial', 'ranshosting'), 'Paid' => __('Paid', 'ranshosting'),
        ]],
        'rh_renewal_filter' => [__('All renewal dates', 'ranshosting'), [
            'expired' => __('Expired', 'ranshosting'),
            'week' => __('Due within 7 days', 'ranshosting'),
            'month' => __('Due within 30 days', 'ranshosting'),
            'upcoming' => __('Due within 45 days', 'ranshosting'),
            'undated' => __('No renewal date', 'ranshosting'),
        ]],
    ];
}

function ranshosting_service_filter_value($name) {
    return isset($_GET[$name]) && is_string($_GET[$name]) ? wp_unslash($_GET[$name]) : '';
}

add_action('restrict_manage_posts', function ($post_type, $which) {
    if ($post_type !== 'rans_hosting_service' || $which !== 'top') return;
    $clients = [];
    foreach (ranshosting_clients() as $client) $clients[$client->ID] = $client->post_title;
    $filters = ['rh_client_filter' => [__('All clients', 'ranshosting'), $clients]] + ranshosting_service_filters();
    foreach ($filters as $name => [$label, $options]) {
        $current = ranshosting_service_filter_value($name);
        echo '<label class="screen-reader-text" for="' . esc_attr($name) . '">' . esc_html($label) . '</label>';
        echo '<select id="' . esc_attr($name) . '" name="' . esc_attr($name) . '"><option value="">' . esc_html($label) . '</option>';
        foreach ($options as $value => $text) {
            echo '<option value="' . esc_attr($value) . '"' . selected($current, $value, false) . '>' . esc_html($text) . '</option>';
        }
        echo '</select>';
    }
    $names = array_keys($filters);
    $active = array_filter(array_map('ranshosting_service_filter_value', $names), function ($value) { return $value !== ''; });
    if ($active) {
        $url = remove_query_arg(array_merge($names, ['paged']), wp_unslash($_SERVER['REQUEST_URI'] ?? 'edit.php?post_type=rans_hosting_service'));
        echo '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Clear filters', 'ranshosting') . '</a>';
    }
}, 10, 2);

function ranshosting_service_billing_filters($payment, $renewal) {
    $mq = [];
    if (in_array($payment, ['Paid', 'Unpaid', 'Partial'], true)) {
        $clause = ['key' => '_rh_payment_status', 'value' => $payment];
        $mq[] = $payment === 'Unpaid' ? ['relation' => 'OR', $clause,
            ['key' => '_rh_payment_status', 'value' => ''],
            ['key' => '_rh_payment_status', 'compare' => 'NOT EXISTS'],
        ] : $clause;
    }

    $windows = ['week' => 7, 'month' => 30, 'upcoming' => 45];
    $today = current_datetime()->setTime(0, 0);
    if ($renewal === 'expired') {
        $mq[] = ['key' => '_rh_end_date', 'value' => $today->format('Y-m-d'), 'compare' => '<', 'type' => 'DATE'];
        $mq[] = ['key' => '_rh_end_date', 'value' => '', 'compare' => '!='];
    } elseif (isset($windows[$renewal])) {
        $mq[] = ['key' => '_rh_end_date', 'value' => [$today->format('Y-m-d'), $today->modify('+' . $windows[$renewal] . ' days')->format('Y-m-d')], 'compare' => 'BETWEEN', 'type' => 'DATE'];
    } elseif ($renewal === 'undated') {
        $mq[] = ['relation' => 'OR',
            ['key' => '_rh_end_date', 'value' => ''],
            ['key' => '_rh_end_date', 'compare' => 'NOT EXISTS'],
        ];
    }

    return $mq;
}

add_action('pre_get_posts', function ($q) {
    global $pagenow;
    if (!is_admin() || $pagenow !== 'edit.php' || !$q->is_main_query() || $q->get('post_type') !== 'rans_hosting_service') return;

    $mq = [];
    $client = ranshosting_service_filter_value('rh_client_filter');
    if (ctype_digit($client) && (int) $client > 0) $mq[] = ['key' => '_rh_client_id', 'value' => (int) $client];
    $type = ranshosting_service_filter_value('rh_type_filter');
    if (isset(ranshosting_service_types()[$type])) $mq[] = ['key' => '_rh_service_type', 'value' => $type];

    $mq = array_merge($mq, ranshosting_service_billing_filters(
        ranshosting_service_filter_value('rh_payment_filter'),
        ranshosting_service_filter_value('rh_renewal_filter')
    ));

    // Include missing metadata when sorting, so new or incomplete services stay visible.
    $order = $q->get('orderby');
    if ($order === 'rh_ends' || $order === 'rh_client' || ($order === '' && !isset($_GET['orderby']))) {
        $key = $order === 'rh_client' ? '_rh_owner' : '_rh_end_date';
        $mq[] = ['relation' => 'OR',
            'rh_sort' => ['key' => $key, 'compare' => 'EXISTS'],
            ['key' => $key, 'compare' => 'NOT EXISTS'],
        ];
        $direction = strtoupper((string) $q->get('order')) === 'DESC' ? 'DESC' : 'ASC';
        $q->set('orderby', ['rh_sort' => isset($_GET['order']) ? $direction : 'ASC', 'ID' => 'ASC']);
    }
    if ($mq) {
        $existing = $q->get('meta_query');
        if ($existing) $mq[] = $existing;
        $q->set('meta_query', $mq);
    }
});

/* Clients list: how many services, the next renewal and what is owed. */
add_filter('manage_rans_client_posts_columns', function ($cols) {
    return [
        'cb'          => $cols['cb'],
        'title'       => __('Client', 'ranshosting'),
        'rh_contact'  => __('Contact', 'ranshosting'),
        'rh_services' => __('Services', 'ranshosting'),
        'rh_next'     => __('Next renewal', 'ranshosting'),
        'rh_owed'     => __('Outstanding', 'ranshosting'),
    ];
});
add_action('manage_rans_client_posts_custom_column', function ($col, $post_id) {
    $svc = ($col === 'rh_contact' || $col === 'title') ? [] : ranshosting_client_services($post_id, 'publish');
    switch ($col) {
        case 'rh_contact':
            $e = ranshosting_get_meta($post_id, 'c_email'); $p = ranshosting_get_meta($post_id, 'c_phone');
            echo esc_html($e ?: '—') . ($p ? '<br><span style="color:#64748b">' . esc_html($p) . '</span>' : '');
            if (ranshosting_get_meta($post_id, 'demo')) echo ' <span style="background:#e0e7ff;color:#3730a3;padding:1px 6px;border-radius:4px;font-size:11px">Demo</span>';
            break;
        case 'rh_services':
            $types = ranshosting_service_types(); $count = [];
            foreach ($svc as $s) { $t = ranshosting_get_meta($s->ID, 'service_type', 'hosting'); $count[$t] = ($count[$t] ?? 0) + 1; }
            echo '<strong>' . (int) count($svc) . '</strong>';
            if ($count) { echo '<br><span style="color:#64748b">'; $bits = []; foreach ($count as $t => $n) $bits[] = $n . ' ' . strtolower($types[$t] ?? $t); echo esc_html(implode(', ', $bits)); echo '</span>'; }
            break;
        case 'rh_next':
            $best = null;
            foreach ($svc as $s) { $e = ranshosting_get_meta($s->ID, 'end_date'); if ($e && ($best === null || $e < $best)) $best = $e; }
            echo $best ? esc_html($best) : '—';
            break;
        case 'rh_owed':
            $owed = [];
            foreach ($svc as $s) {
                $st = ranshosting_get_meta($s->ID, 'payment_status', 'Unpaid'); if ($st === 'Paid') continue;
                $cost = ranshosting_service_cost($s->ID); $paid = (float) ranshosting_get_meta($s->ID, 'paid', 0);
                $due = $st === 'Partial' && $paid > 0 ? max(0, $cost - $paid) : $cost;
                $c = ranshosting_get_meta($s->ID, 'currency', '৳'); $owed[$c] = ($owed[$c] ?? 0) + $due;
            }
            if (!$owed) { echo '—'; break; }
            $bits = []; foreach ($owed as $c => $v) $bits[] = $c . number_format_i18n($v, $v == floor($v) ? 0 : 2);
            echo '<strong>' . esc_html(implode(' + ', $bits)) . '</strong>';
            break;
    }
}, 10, 2);

add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'rans_hosting_service' || !current_user_can('edit_post', $post->ID)) return $actions;
    $link = function ($do, $label) use ($post) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=rh_row_action&do=' . $do . '&id=' . $post->ID), 'rh_row_' . $do . '_' . $post->ID);
        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    };
    $actions['rh_paid']  = $link('paid', 'Mark paid');
    $actions['rh_renew'] = $link('renew', 'Renew term');
    if (!ranshosting_get_meta($post->ID, 'demo')) {
        $actions['rh_remind'] = $link('remind', 'Send reminder now');
    }
    return $actions;
}, 10, 2);

add_action('admin_post_rh_row_action', function () {
    $do = isset($_GET['do']) ? sanitize_key($_GET['do']) : '';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    check_admin_referer('rh_row_' . $do . '_' . $id);
    if (!$id || !current_user_can('edit_post', $id) || get_post_type($id) !== 'rans_hosting_service') wp_die('Not allowed.');

    $msg = '';
    if ($do === 'paid') {
        update_post_meta($id, '_rh_payment_status', 'Paid');
        update_post_meta($id, '_rh_paid', ranshosting_service_cost($id));
        $msg = 'Marked as paid.';
    } elseif ($do === 'renew') {
        $end    = ranshosting_get_meta($id, 'end_date');
        $cycle  = ranshosting_billing_cycles()[ranshosting_get_meta($id, 'billing_cycle', 'yearly')]['months'] ?? 12;
        $today  = wp_date('Y-m-d');
        $base   = ranshosting_valid_date($end) && $end >= $today ? $end : $today;
        $new    = ranshosting_renewal_end_date($base, $cycle);
        update_post_meta($id, '_rh_start_date', $base);
        update_post_meta($id, '_rh_end_date', $new);
        update_post_meta($id, '_rh_payment_status', 'Unpaid');
        update_post_meta($id, '_rh_paid', '');
        delete_post_meta($id, '_rh_sent_reminders');
        $msg = 'Term renewed until ' . $new . ' (invoice set to Unpaid).';
    } elseif ($do === 'remind') {
        $res = ranshosting_send_reminder($id, 'manual');
        $msg = $res ? 'Reminder emailed to the client.' : 'Could not send: add a client email and an end date first.';
    }
    if (isset($_GET['from']) && $_GET['from'] === 'portal') {
        wp_safe_redirect(home_url('/') . '#svc-' . $id);
        exit;
    }
    wp_safe_redirect(add_query_arg('rh_notice', rawurlencode($msg), admin_url('edit.php?post_type=rans_hosting_service')));
    exit;
});

add_action('admin_notices', function () {
    if (!empty($_GET['rh_notice']) && current_user_can('edit_posts')) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sanitize_text_field(wp_unslash($_GET['rh_notice']))) . '</p></div>';
    }
});

/* Front-end "load demo data" link (editors only, shown when the portal is empty). */
add_action('admin_post_rh_load_demo', function () {
    check_admin_referer('rh_load_demo');
    if (!current_user_can('edit_posts')) wp_die('Not allowed.');
    ranshosting_seed_demo_services();
    wp_safe_redirect(home_url('/'));
    exit;
});

/* =========================================================================
   Settings page
   ========================================================================= */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=rans_hosting_service',
        __('RansHosting Reminders & Settings', 'ranshosting'),
        __('Reminders & Settings', 'ranshosting'),
        'manage_options',
        'ranshosting-settings',
        'ranshosting_render_admin_settings_page'
    );
});

add_action('admin_menu', function () {
    $screen = add_submenu_page(
        'edit.php?post_type=rans_hosting_service',
        __('Renewals & Payments', 'ranshosting'),
        __('Renewals & Payments', 'ranshosting'),
        'manage_options',
        'ranshosting-renewals',
        'ranshosting_render_renewals_dashboard'
    );
    if ($screen) {
        add_action('load-' . $screen, function () {
            add_action('admin_enqueue_scripts', function () {
                wp_enqueue_style('ranshosting-renewals', get_theme_file_uri('renewals-admin.css'), ['dashicons'], filemtime(get_theme_file_path('renewals-admin.css')));
            });
        });
    }
});

function ranshosting_render_renewals_dashboard() {
    if (!current_user_can('manage_options')) return;

    $cards = [
        ['urgent', 'warning', __('Overdue renewals', 'ranshosting'), __('Term ended before today.', 'ranshosting'), 'expired', ''],
        ['soon', 'calendar-alt', __('Due within 7 days', 'ranshosting'), __('Renewals to follow up on this week.', 'ranshosting'), 'week', ''],
        ['planned', 'calendar', __('Due within 30 days', 'ranshosting'), __('Includes the next 7 days.', 'ranshosting'), 'month', ''],
        ['billing', 'money-alt', __('Unpaid services', 'ranshosting'), __('Does not include partial payments.', 'ranshosting'), '', 'Unpaid'],
    ];
    $services_url = admin_url('edit.php?post_type=rans_hosting_service');
    ?>
    <div class="wrap rh-renewals">
        <header class="rh-renewals-header">
            <div>
                <h1><?php esc_html_e('Renewals & Payments', 'ranshosting'); ?></h1>
                <p><?php esc_html_e('See what needs attention and open the matching services in one click.', 'ranshosting'); ?></p>
            </div>
            <a class="button" href="<?php echo esc_url($services_url); ?>"><?php esc_html_e('View all services', 'ranshosting'); ?></a>
        </header>
        <section aria-labelledby="rh-renewals-heading">
            <h2 id="rh-renewals-heading"><?php esc_html_e('Published services at a glance', 'ranshosting'); ?></h2>
            <div class="rh-renewals-grid">
            <?php foreach ($cards as [$tone, $icon, $label, $description, $renewal, $payment]) :
                $query = new WP_Query([
                    'post_type' => 'rans_hosting_service',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'fields' => 'ids',
                    'orderby' => 'none',
                    'meta_query' => ranshosting_service_billing_filters($payment, $renewal),
                ]);
                $args = ['post_type' => 'rans_hosting_service', 'post_status' => 'publish'];
                if ($renewal !== '') $args['rh_renewal_filter'] = $renewal;
                if ($payment !== '') $args['rh_payment_filter'] = $payment;
                $url = add_query_arg($args, admin_url('edit.php'));
                ?>
                <a class="rh-renewal-card rh-renewal-card--<?php echo esc_attr($tone); ?>" href="<?php echo esc_url($url); ?>">
                    <span class="rh-renewal-icon dashicons dashicons-<?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
                    <h3><?php echo esc_html($label); ?></h3>
                    <strong class="rh-renewal-count"><?php echo esc_html(number_format_i18n($query->found_posts)); ?></strong>
                    <span class="rh-renewal-description"><?php echo esc_html($description); ?></span>
                    <span class="rh-renewal-action"><?php
                        echo $query->found_posts ? esc_html__('View matching services', 'ranshosting') : esc_html__('No matching services', 'ranshosting');
                    ?> <span aria-hidden="true">→</span></span>
                </a>
            <?php endforeach; ?>
            </div>
        </section>
    </div>
    <?php
}

function ranshosting_render_admin_settings_page() {
    if (!current_user_can('manage_options')) return;

    if (isset($_POST['rh_save_settings']) && check_admin_referer('rh_settings_nonce')) {
        update_option('rh_payment_instructions', sanitize_textarea_field(wp_unslash($_POST['rh_payment_instructions'] ?? '')));
        update_option('rh_auto_cron_enabled', isset($_POST['rh_auto_cron_enabled']) ? '1' : '0');
        update_option('rh_public_credentials', isset($_POST['rh_public_credentials']) ? '1' : '0');
        update_option('rh_creds_on_key', isset($_POST['rh_creds_on_key']) ? '1' : '0');
        $mode = sanitize_key($_POST['rh_portal_access'] ?? 'open');
        update_option('rh_portal_access', in_array($mode, ['open', 'key'], true) ? $mode : 'open');
        update_option('rh_logo_url', esc_url_raw(wp_unslash($_POST['rh_logo_url'] ?? '')));
        update_option('rh_wa_number', preg_replace('/\D+/', '', wp_unslash($_POST['rh_wa_number'] ?? '')));
        update_option('rh_contact_email', sanitize_email(wp_unslash($_POST['rh_contact_email'] ?? '')));
        update_option('rh_admin_email', sanitize_email(wp_unslash($_POST['rh_admin_email'] ?? '')));
        echo '<div class="notice notice-success"><p>RansHosting settings saved.</p></div>';
    }
    if (isset($_POST['rh_load_demo']) && check_admin_referer('rh_settings_nonce')) {
        $n = ranshosting_seed_demo_services();
        echo '<div class="notice notice-success"><p>' . esc_html("Loaded {$n} demo hosting service(s).") . '</p></div>';
    }
    if (isset($_POST['rh_remove_demo']) && check_admin_referer('rh_settings_nonce')) {
        $n = ranshosting_remove_demo_services();
        echo '<div class="notice notice-success"><p>' . esc_html("Removed {$n} demo hosting service(s).") . '</p></div>';
    }
    if (isset($_POST['rh_run_reminders_now']) && check_admin_referer('rh_settings_nonce')) {
        $sent_count = ranshosting_process_renewal_reminders(true);
        echo '<div class="notice notice-success"><p>' . esc_html("Processed reminders. Sent {$sent_count} email(s).") . '</p></div>';
    }
    if (isset($_POST['rh_test_email']) && check_admin_referer('rh_settings_nonce')) {
        $to = get_option('rh_admin_email') ?: get_option('admin_email');
        $ok = wp_mail($to, 'RansHosting test email', "This is a test from the RansHosting portal.\nIf you can read this, reminder emails can be delivered from this site.");
        echo '<div class="notice ' . ($ok ? 'notice-success' : 'notice-error') . '"><p>' . esc_html($ok ? "Test email sent to {$to}." : 'The test email could not be sent. Check your site\'s mail configuration (an SMTP plugin usually fixes this).') . '</p></div>';
    }
    if (isset($_POST['rh_clear_log']) && check_admin_referer('rh_settings_nonce')) {
        delete_option('rh_reminder_log');
    }

    $pay_inst = get_option('rh_payment_instructions', 'bKash / Nagad: +8801575115472');
    $cron_on  = get_option('rh_auto_cron_enabled', '1');
    $pub_cred = get_option('rh_public_credentials', '0');
    $key_cred = get_option('rh_creds_on_key', '0');
    $access   = get_option('rh_portal_access', 'open');
    $logo_url = get_option('rh_logo_url', '');
    $wa       = get_option('rh_wa_number', '');
    $contact  = get_option('rh_contact_email', '');
    $adm      = get_option('rh_admin_email', '');
    $log      = get_option('rh_reminder_log', []);
    $log      = is_array($log) ? $log : [];
    $next     = wp_next_scheduled('ranshosting_daily_reminder_cron');
    ?>
    <div class="wrap">
      <h1>RansHosting — Settings &amp; Reminder Engine</h1>
      <p>Reminder schedule: <strong>45d, 30d, 15d, 10d, 5d, 3d and 24h</strong> before the term end date, plus one overdue notice after expiry. Services marked <em>Paid</em> are skipped, and demo records never receive email.
         <?php if ($next): ?>Next automatic check: <strong><?php echo esc_html(wp_date('M j, Y H:i', $next)); ?></strong>.<?php endif; ?></p>
      <form method="post">
        <?php wp_nonce_field('rh_settings_nonce'); ?>
        <table class="form-table">
          <tr>
            <th>Daily WP-Cron reminders</th>
            <td><label><input type="checkbox" name="rh_auto_cron_enabled" value="1" <?php checked($cron_on, '1'); ?> /> Check once daily and email clients when a reminder is due</label></td>
          </tr>
          <tr>
            <th>Admin copy of reminders</th>
            <td><input type="email" name="rh_admin_email" value="<?php echo esc_attr($adm); ?>" class="regular-text" placeholder="you@example.com" />
              <p class="description">Optional. Receives a BCC of every reminder sent, so you know what clients were told.</p></td>
          </tr>
          <tr>
            <th>Who can open the portal</th>
            <td>
              <label><input type="radio" name="rh_portal_access" value="open" <?php checked($access, 'open'); ?> /> Anyone with the link (current behaviour: the home page lists every client)</label><br />
              <label><input type="radio" name="rh_portal_access" value="key" <?php checked($access, 'key'); ?> /> <strong>Private client links only</strong> (recommended): visitors need the personal link from <em>Copy client link</em>; logged-in editors still see everything</label>
            </td>
          </tr>
          <tr>
            <th>Credentials</th>
            <td>
              <label><input type="checkbox" name="rh_creds_on_key" value="1" <?php checked($key_cred, '1'); ?> /> Show a client their own usernames and passwords on their private link</label><br />
              <label><input type="checkbox" name="rh_public_credentials" value="1" <?php checked($pub_cred, '1'); ?> /> Show credentials to every visitor (not recommended)</label>
              <p class="description">When both are off, only logged-in editors see credentials.</p>
            </td>
          </tr>
          <tr>
            <th>Portal logo URL</th>
            <td><input type="url" name="rh_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="large-text" placeholder="https://ranstechdigital.com/wp-content/.../logo.png" />
              <p class="description">Replaces the built-in mark in the header. Use a logo that works on a dark background.</p></td>
          </tr>
          <tr>
            <th>Support WhatsApp number</th>
            <td><input type="text" name="rh_wa_number" value="<?php echo esc_attr($wa); ?>" class="regular-text" placeholder="<?php echo esc_attr(RANSHOSTING_WA_NUMBER); ?>" />
              <p class="description">Digits only, with country code. Leave blank to use the default.</p></td>
          </tr>
          <tr>
            <th>Public contact email</th>
            <td><input type="email" name="rh_contact_email" value="<?php echo esc_attr($contact); ?>" class="regular-text" placeholder="ratul@ranstechdigital.com" />
              <p class="description">Shown in the footer and on invoices.</p></td>
          </tr>
          <tr>
            <th>Invoice payment instructions</th>
            <td><textarea name="rh_payment_instructions" rows="4" class="large-text"><?php echo esc_textarea($pay_inst); ?></textarea></td>
          </tr>
        </table>
        <p class="submit">
          <button type="submit" name="rh_save_settings" class="button button-primary">Save settings</button>
          <button type="submit" name="rh_run_reminders_now" class="button button-secondary">Run reminder check now</button>
          <button type="submit" name="rh_test_email" class="button">Send test email</button>
          <button type="submit" name="rh_load_demo" class="button">Load demo services</button>
          <button type="submit" name="rh_remove_demo" class="button" onclick="return confirm('Delete all demo services?');">Remove demo services</button>
        </p>

        <h2>Recent reminder activity</h2>
        <?php if (!$log): ?>
          <p class="description">Nothing sent yet.</p>
        <?php else: ?>
          <table class="widefat striped" style="max-width:900px">
            <thead><tr><th>When</th><th>Service</th><th>Stage</th><th>Sent to</th><th>Result</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($log, 0, 30) as $row): ?>
              <tr>
                <td><?php echo esc_html(wp_date('M j, H:i', (int) $row['t'])); ?></td>
                <td><?php echo esc_html($row['domain']); ?></td>
                <td><?php echo esc_html($row['stage']); ?></td>
                <td><?php echo esc_html($row['to']); ?></td>
                <td><?php echo $row['ok'] ? 'Sent' : '<strong style="color:#b91c1c">Failed</strong>'; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p><button type="submit" name="rh_clear_log" class="button-link">Clear log</button></p>
        <?php endif; ?>
      </form>
    </div>
    <?php
}

/* =========================================================================
   Reminder engine
   ========================================================================= */
add_action('init', function () {
    if (!wp_next_scheduled('ranshosting_daily_reminder_cron')) {
        wp_schedule_event(time(), 'daily', 'ranshosting_daily_reminder_cron');
    }
});
add_action('switch_theme', function () {
    wp_clear_scheduled_hook('ranshosting_daily_reminder_cron');
});
add_action('ranshosting_daily_reminder_cron', 'ranshosting_process_renewal_reminders');

function ranshosting_reminder_stage($days_left) {
    $stages = [
        ['key' => '45d', 'max' => 45, 'min' => 31],
        ['key' => '30d', 'max' => 30, 'min' => 16],
        ['key' => '15d', 'max' => 15, 'min' => 11],
        ['key' => '10d', 'max' => 10, 'min' => 6],
        ['key' => '5d',  'max' => 5,  'min' => 4],
        ['key' => '3d',  'max' => 3,  'min' => 2],
        ['key' => '24h', 'max' => 1,  'min' => 0],
        ['key' => 'overdue', 'max' => -1, 'min' => -14],
    ];
    foreach ($stages as $st) {
        if ($days_left <= $st['max'] && $days_left >= $st['min']) return $st['key'];
    }
    return null;
}

function ranshosting_log_reminder($domain, $stage, $to, $ok) {
    $log = get_option('rh_reminder_log', []);
    $log = is_array($log) ? $log : [];
    array_unshift($log, ['t' => time(), 'domain' => $domain, 'stage' => $stage, 'to' => $to, 'ok' => (bool) $ok]);
    update_option('rh_reminder_log', array_slice($log, 0, 60), false);
}

/** Sends one reminder email for a service. Returns true when wp_mail accepted it. */
function ranshosting_send_reminder($post_id, $stage) {
    $end_str = ranshosting_get_meta($post_id, 'end_date');
    $to      = ranshosting_get_meta($post_id, 'client_email');
    if (!$end_str || !$to) return false;

    $today     = strtotime(wp_date('Y-m-d'));
    $days_left = (int) ceil((strtotime($end_str) - $today) / DAY_IN_SECONDS);
    $domain    = get_the_title($post_id);
    $owner     = ranshosting_get_meta($post_id, 'owner', 'there');
    $cur       = ranshosting_get_meta($post_id, 'currency', '৳');
    $cost      = ranshosting_service_cost($post_id);
    $paid      = (float) ranshosting_get_meta($post_id, 'paid', 0);
    $status    = ranshosting_get_meta($post_id, 'payment_status', 'Unpaid');
    $due       = $status === 'Partial' && $paid > 0 ? max(0, $cost - $paid) : $cost;
    $amount    = $cur . number_format_i18n($due, $due == floor($due) ? 0 : 2);
    $pay_link  = ranshosting_get_meta($post_id, 'payment_link', home_url('/'));
    $plan      = ranshosting_get_meta($post_id, 'plan');
    $pay_inst  = get_option('rh_payment_instructions', 'bKash / Nagad: +8801575115472');

    if ($days_left < 0) {
        $subject = "Action needed: {$domain} has expired";
        $ago     = abs($days_left);
        $line    = "Your service for {$domain}" . ($plan ? " ({$plan})" : '') . " expired on {$end_str} ({$ago} day(s) ago). Please renew soon so your website and email stay online.";
    } else {
        $subject = "Renewal notice ({$stage}): {$domain}";
        $line    = "Your service for {$domain}" . ($plan ? " ({$plan})" : '') . " expires on {$end_str} ({$days_left} day(s) left).";
    }

    $body  = "Hello {$owner},\n\n{$line}\n\n";
    $body .= "Amount due: {$amount}\n";
    $body .= "Pay online: {$pay_link}\n";
    if ($pay_inst) $body .= "\nOther ways to pay:\n{$pay_inst}\n";
    $body .= "\nQuestions? Message us on WhatsApp: " . ranshosting_wa_display() . "\n\nRegards,\nRansHosting by RansTech Digital";

    $headers = [];
    $bcc = get_option('rh_admin_email', '');
    if ($bcc) $headers[] = 'Bcc: ' . $bcc;

    $ok = wp_mail($to, $subject, $body, $headers);
    ranshosting_log_reminder($domain, $stage, $to, $ok);
    return (bool) $ok;
}

function ranshosting_process_renewal_reminders($force = false) {
    if (!$force && get_option('rh_auto_cron_enabled', '1') !== '1') return 0;

    $posts = get_posts([
        'post_type'      => 'rans_hosting_service',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $today = strtotime(wp_date('Y-m-d'));
    $sent_total = 0;

    foreach ($posts as $post) {
        if (ranshosting_get_meta($post->ID, 'demo')) continue;               // never email demo clients
        if (ranshosting_get_meta($post->ID, 'payment_status') === 'Paid') continue; // already settled
        $end_str = ranshosting_get_meta($post->ID, 'end_date');
        $to      = ranshosting_get_meta($post->ID, 'client_email');
        if (!$end_str || !$to) continue;

        $days_left = (int) ceil((strtotime($end_str) - $today) / DAY_IN_SECONDS);
        $stage = ranshosting_reminder_stage($days_left);
        if (!$stage) continue;

        $sent_list = ranshosting_get_meta($post->ID, 'sent_reminders', []);
        if (!is_array($sent_list)) $sent_list = [];
        if (in_array($stage, $sent_list, true)) continue;

        if (ranshosting_send_reminder($post->ID, $stage)) {
            $sent_list[] = $stage;
            update_post_meta($post->ID, '_rh_sent_reminders', $sent_list);
            $sent_total++;
        }
    }
    return $sent_total;
}

/* =========================================================================
   Upgrade from v2.3: create a Client record for every existing owner name,
   link each service to it, and turn the three fixed login sets into logins.
   Runs once, the first time an admin screen loads. Nothing is deleted.
   ========================================================================= */
function ranshosting_migrate_to_clients() {
    if (get_option('rh_db_version') === '2.4.0') return;

    $map = [];
    foreach (ranshosting_clients(['publish', 'draft', 'private', 'pending']) as $c) {
        $map[strtolower(trim($c->post_title))] = $c->ID;
    }

    $services = get_posts(['post_type' => 'rans_hosting_service', 'post_status' => 'any', 'posts_per_page' => -1]);
    foreach ($services as $p) {
        if (!(int) ranshosting_get_meta($p->ID, 'client_id')) {
            $owner = trim((string) ranshosting_get_meta($p->ID, 'owner'));
            if ($owner !== '') {
                $k = strtolower($owner);
                if (!isset($map[$k])) {
                    $cid = wp_insert_post(['post_type' => 'rans_client', 'post_status' => 'publish', 'post_title' => $owner]);
                    if ($cid && !is_wp_error($cid)) {
                        update_post_meta($cid, '_rh_c_email', wp_slash((string) ranshosting_get_meta($p->ID, 'client_email')));
                        update_post_meta($cid, '_rh_c_phone', wp_slash((string) ranshosting_get_meta($p->ID, 'client_phone')));
                        if (ranshosting_get_meta($p->ID, 'demo')) update_post_meta($cid, '_rh_demo', '1');
                        $map[$k] = $cid;
                    }
                }
                if (isset($map[$k])) update_post_meta($p->ID, '_rh_client_id', $map[$k]);
            }
        }
        if (!metadata_exists('post', $p->ID, '_rh_logins')) {
            update_post_meta($p->ID, '_rh_logins', wp_slash(ranshosting_legacy_logins($p->ID)));
        }
    }
    update_option('rh_db_version', '2.4.0');
}
add_action('admin_init', 'ranshosting_migrate_to_clients');

/* =========================================================================
   Demo data (fictional clients on reserved .example domains, fake credentials)
   Dates are relative to today so every status is represented.
   ========================================================================= */
function ranshosting_demo_data() {
    // domain, owner, email, phone, mailboxes, end offset (days), cost, currency, payment, ssl, plan, type, cycle, paid, notes
    return [
        ['brooksplumbing.example',     'Brooks & Sons Plumbing',   'daniel@brooksplumbing.example',   '+1 555 010 2201', 'info,bookings,daniel',        210, 18000, '৳', 'Paid',    'active',   'Business Hosting', 'hosting',     'yearly',    18000, 'Booking form plugin updated in October.'],
        ['clearflowhome.example',      'ClearFlow Home Services',  'priya@clearflowhome.example',     '+1 555 010 2202', 'hello,support,priya,billing', 38,  24000, '৳', 'Unpaid',  'active',   'Business Plus',    'hosting',     'yearly',    0,     'Wants a speed audit before renewing.'],
        ['webbheatingair.example',     'Webb Heating & Air',       'marcus@webbheatingair.example',   '+1 555 010 2203', 'office,marcus',               12,  12500, '৳', 'Unpaid',  'expiring', 'Starter Hosting',  'hosting',     'yearly',    0,     'SSL certificate renews with the term.'],
        ['whitlockdrainage.example',   'Whitlock Drainage',        'sarah@whitlockdrainage.example',  '+1 555 010 2204', 'sarah,jobs,accounts',         4,   15000, '৳', 'Partial', 'active',   'Business Hosting', 'hosting',     'yearly',    7500,  'Paid half upfront; balance promised this week.'],
        ['hendricksrooter.example',    'Hendricks Rooter & Repair','tom@hendricksrooter.example',     '+1 555 010 2205', 'tom,dispatch',                -9,  9500,  '৳', 'Unpaid',  'expiring', 'Starter Hosting',  'hosting',     'yearly',    0,     'Site is paused until payment arrives.'],
        ['rossipropertycare.example',  'Rossi Property Care',      'elena@rossipropertycare.example', '+1 555 010 2206', 'elena,maintenance,tenants,info', 320, 36000, '৳', 'Paid',  'active',   'Agency Pro',       'hosting',     'yearly',    36000, ''],
        ['maplestreetcafe.example',    'Maple Street Café',        'owner@maplestreetcafe.example',   '+1 555 010 2207', 'owner,orders',                1,   8000,  '৳', 'Unpaid',  'active',   'Starter Hosting',  'hosting',     'yearly',    0,     'Reminder sent; client said they will pay today.'],
        ['northwindlogistics.example', 'Northwind Logistics',      'it@northwindlogistics.example',   '+1 555 010 2208', 'it,ops,sales,support,hr',     95,  42000, '৳', 'Paid',    'active',   'Agency Pro',       'hosting',     'yearly',    42000, ''],
        ['ironpeakfitness.example',    'Ironpeak Fitness',         'hello@ironpeakfitness.example',   '+1 555 010 2209', '',                            27,  1800,  '$', 'Unpaid',  'none',     '.example registration', 'domain',  'yearly',    0,     'Domain only; website lives on Wix.'],
        ['harborviewdental.example',   'Harborview Dental',        'office@harborviewdental.example', '+1 555 010 2210', 'office,frontdesk,drlee,billing,recall', 160, 9600, '$', 'Paid', 'active', 'Business Email, 5 seats', 'email', 'yearly', 9600,  'Moved mail from a shared inbox in September.'],
        ['driftwoodstudio.example',    'Driftwood Design Studio',  'mia@driftwoodstudio.example',     '+1 555 010 2211', 'mia,projects',                18,  900,   '$', 'Unpaid',  'active',   'Cloud VPS 2 vCPU', 'vps',         'monthly',   0,     'Runs a staging site and client file server.'],
        ['pinecrestrealty.example',    'Pinecrest Realty',         'jon@pinecrestrealty.example',     '+1 555 010 2212', 'jon,listings,leads',          66,  3000,  '$', 'Paid',    'active',   'Care Plan: updates and backups', 'maintenance', 'quarterly', 3000, 'Weekly backups; monthly report on the 1st.'],
    ];
}

/** Extra services for some demo clients, so one client shows several service types. */
function ranshosting_demo_extras() {
    // client domain, type, plan, cycle, cost, currency, payment, paid, end offset, mailboxes, notes, ssl
    return [
        ['brooksplumbing.example',     'domain',      'brooksplumbing.example registration', 'yearly',    1800,  '৳', 'Paid',   1800, 140, '',                       'Auto-renew is on.',                  'active'],
        ['brooksplumbing.example',     'email',       'Business Email, 3 seats',            'yearly',    7200,  '৳', 'Paid',   7200, 205, 'info,bookings,daniel',   '',                                   'active'],
        ['clearflowhome.example',      'domain',      'clearflowhome.example registration', 'yearly',    1800,  '৳', 'Unpaid', 0,    38,  '',                       'Renew together with hosting.',       'active'],
        ['clearflowhome.example',      'maintenance', 'Care Plan: updates and backups',     'quarterly', 4500,  '৳', 'Paid',   4500, 61,  '',                       'Monthly report on the 1st.',         'active'],
        ['rossipropertycare.example',  'maintenance', 'Care Plan: updates and backups',     'quarterly', 6000,  '৳', 'Paid',   6000, 74,  '',                       '',                                   'active'],
        ['rossipropertycare.example',  'domain',      'rossipropertycare.example registration', 'biennial', 3200, '৳', 'Paid',  3200, 410, '',                       '',                                   'active'],
        ['northwindlogistics.example', 'email',       'Business Email, 5 seats',            'yearly',    15000, '৳', 'Paid',   15000, 95, 'it,ops,sales,support,hr', '',                                  'active'],
        ['northwindlogistics.example', 'maintenance', 'Care Plan: updates, backups, uptime', 'monthly',  3500,  '৳', 'Unpaid', 0,    9,   '',                       'Invoice sent this morning.',         'active'],
        ['webbheatingair.example',     'domain',      'webbheatingair.example registration', 'yearly',   1800,  '৳', 'Unpaid', 0,    12,  '',                       '',                                   'active'],
    ];
}

/** Believable fake logins for one demo service. */
function ranshosting_demo_logins($type, $domain, $slug, $boxes, $seed, $reg) {
    $pw  = function ($tag, $k) use ($seed) { return 'Demo#' . $tag . '-' . (100 + $seed * 4 + $k); };
    $u   = substr($slug, 0, 10);
    $reg_urls = [
        'Namecheap' => 'https://ap.www.namecheap.com/', 'GoDaddy' => 'https://sso.godaddy.com/', 'Hostinger' => 'https://hpanel.hostinger.com/',
        'Cloudflare Registrar' => 'https://dash.cloudflare.com/', 'Porkbun' => 'https://porkbun.com/account/login',
    ];
    $reg_url = $reg_urls[$reg] ?? 'https://ap.www.namecheap.com/';
    $L = [];
    if ($type === 'hosting') {
        $L[] = ['label' => 'cPanel', 'url' => 'https://' . $domain . ':2083', 'user' => $u, 'pass' => $pw('Cp', 0), 'note' => ''];
        $L[] = ['label' => 'WordPress admin', 'url' => 'https://' . $domain . '/wp-admin', 'user' => 'admin_' . substr($slug, 0, 8), 'pass' => $pw('Wp', 1), 'note' => 'Two-step login is off.'];
        $L[] = ['label' => 'FTP / SFTP', 'url' => '', 'user' => $u . '@' . $domain, 'pass' => $pw('Ft', 2), 'note' => 'Host: ftp.' . $domain];
    } elseif ($type === 'vps') {
        $L[] = ['label' => 'SSH', 'url' => '', 'user' => 'deploy', 'pass' => $pw('Ss', 0), 'note' => 'Key login preferred.'];
        $L[] = ['label' => 'Server panel', 'url' => 'https://panel.' . $domain, 'user' => $u, 'pass' => $pw('Pn', 1), 'note' => ''];
    } elseif ($type === 'domain') {
        $L[] = ['label' => 'Domain registrar', 'url' => $reg_url, 'user' => $u . '_dom', 'pass' => $pw('Dm', 0), 'note' => $reg . ' account'];
        $L[] = ['label' => 'DNS (Cloudflare etc.)', 'url' => 'https://dash.cloudflare.com/', 'user' => 'dns@' . $domain, 'pass' => $pw('Dn', 1), 'note' => ''];
    } elseif ($type === 'email') {
        $list = array_slice(array_filter(explode(',', $boxes)), 0, 3);
        foreach ($list as $k => $b) {
            $L[] = ['label' => 'Webmail: ' . $b . '@', 'url' => 'https://webmail.' . $domain, 'user' => $b . '@' . $domain, 'pass' => $pw('Ml', $k), 'note' => $k === 0 ? 'Main mailbox.' : ''];
        }
        $L[] = ['label' => 'Email admin panel', 'url' => 'https://mail-admin.' . $domain, 'user' => 'postmaster@' . $domain, 'pass' => $pw('Ad', 3), 'note' => 'Create or remove mailboxes here.'];
    } elseif ($type === 'maintenance') {
        $L[] = ['label' => 'WordPress admin', 'url' => 'https://' . $domain . '/wp-admin', 'user' => 'care_' . substr($slug, 0, 8), 'pass' => $pw('Wp', 0), 'note' => ''];
        $L[] = ['label' => 'Backup dashboard', 'url' => 'https://backups.example/' . $slug, 'user' => $u, 'pass' => $pw('Bk', 1), 'note' => 'Weekly full backup.'];
    }
    return $L;
}

function ranshosting_seed_demo_services() {
    $created = 0;
    $today   = strtotime(wp_date('Y-m-d'));
    $ips     = ['103.145.12.21', '103.145.12.34', '103.145.12.48', '103.145.12.52', '103.145.13.7', '103.145.13.19', '103.145.13.40', '103.145.14.5'];
    $regs    = ['Namecheap', 'GoDaddy', 'Hostinger', 'Namecheap', 'Cloudflare Registrar', 'GoDaddy', 'Namecheap', 'Porkbun'];

    // 1) Clients first.
    $clients = [];   // owner => client id
    $by_domain = []; // primary domain => client row
    foreach (ranshosting_demo_data() as $d) {
        list($domain, $owner, $email, $phone) = $d;
        $by_domain[$domain] = $d;
        $found = get_posts([
            'post_type' => 'rans_client', 'post_status' => 'any', 'title' => $owner,
            'meta_key' => '_rh_demo', 'meta_value' => '1', 'posts_per_page' => 1, 'fields' => 'ids',
        ]);
        if ($found) { $clients[$owner] = (int) $found[0]; continue; }
        $cid = wp_insert_post(['post_type' => 'rans_client', 'post_status' => 'publish', 'post_title' => $owner]);
        if (!$cid || is_wp_error($cid)) continue;
        update_post_meta($cid, '_rh_demo', '1');
        update_post_meta($cid, '_rh_c_email', $email);
        update_post_meta($cid, '_rh_c_phone', $phone);
        $clients[$owner] = $cid;
    }

    // 2) One list of services: the original rows, then the extras.
    $specs = [];
    foreach (ranshosting_demo_data() as $i => $d) {
        list($domain, $owner, , , $boxes, $off, $cost, $cur, $pay, $ssl, $plan, $type, $cycle, $paid, $notes) = $d;
        $specs[] = compact('domain', 'owner', 'boxes', 'off', 'cost', 'cur', 'pay', 'ssl', 'plan', 'type', 'cycle', 'paid', 'notes');
    }
    foreach (ranshosting_demo_extras() as $x) {
        list($cdomain, $type, $plan, $cycle, $cost, $cur, $pay, $paid, $off, $boxes, $notes, $ssl) = $x;
        if (!isset($by_domain[$cdomain])) continue;
        $specs[] = ['domain' => $cdomain, 'owner' => $by_domain[$cdomain][1], 'boxes' => $boxes, 'off' => $off, 'cost' => $cost, 'cur' => $cur,
                    'pay' => $pay, 'ssl' => $ssl, 'plan' => $plan, 'type' => $type, 'cycle' => $cycle, 'paid' => $paid, 'notes' => $notes];
    }

    // 3) Services, each with its own logins.
    foreach ($specs as $i => $s) {
        $cid = $clients[$s['owner']] ?? 0;
        if (!$cid) continue;

        $exists = get_posts([
            'post_type' => 'rans_hosting_service', 'post_status' => 'any', 'title' => $s['domain'], 'posts_per_page' => 1, 'fields' => 'ids',
            'meta_query' => [['key' => '_rh_demo', 'value' => '1'], ['key' => '_rh_service_type', 'value' => $s['type']]],
        ]);
        if ($exists) continue;

        $id = wp_insert_post(['post_type' => 'rans_hosting_service', 'post_status' => 'publish', 'post_title' => $s['domain']]);
        if (!$id || is_wp_error($id)) continue;

        $months = ranshosting_billing_cycles()[$s['cycle']]['months'];
        $end    = gmdate('Y-m-d', $today + $s['off'] * DAY_IN_SECONDS);
        $start  = gmdate('Y-m-d', strtotime('-' . $months . ' months', strtotime($end)));
        $box_list = array_filter(explode(',', $s['boxes']));
        $emails = implode(', ', array_map(function ($b) use ($s) { return $b . '@' . $s['domain']; }, $box_list));
        $slug   = preg_replace('/[^a-z0-9]/', '', strtolower(strtok($s['domain'], '.')));
        $reg    = $regs[$i % count($regs)];
        $no_server = in_array($s['type'], ['domain', 'email'], true);

        $meta = [
            'demo'           => '1',
            'service_type'   => $s['type'],
            'plan'           => $s['plan'],
            'billing_cycle'  => $s['cycle'],
            'notes'          => $s['notes'],
            'domain_emails'  => $emails,
            'start_date'     => $start,
            'end_date'       => $end,
            'domain_expiry'  => gmdate('Y-m-d', $today + ($s['off'] + 40 + $i * 9) * DAY_IN_SECONDS),
            'ssl_status'     => $s['ssl'],
            'server_ip'      => $no_server ? '' : $ips[$i % count($ips)],
            'registrar'      => $reg,
            'nameservers'    => 'ns1.ranshosting.example, ns2.ranshosting.example',
            'cost'           => $s['cost'],
            'paid'           => $s['paid'],
            'currency'       => $s['cur'],
            'payment_status' => $s['pay'],
            'invoice_no'     => 'INV-' . gmdate('Y', $today) . '-' . str_pad((string) (1001 + $i), 4, '0', STR_PAD_LEFT),
            'payment_link'   => 'https://example.com/pay/' . $slug . '-' . $s['type'],
            'logins'         => ranshosting_demo_logins($s['type'], $s['domain'], $slug, $s['boxes'], $i, $reg),
        ];
        foreach ($meta as $k => $v) {
            update_post_meta($id, '_rh_' . $k, is_array($v) ? wp_slash($v) : $v);
        }
        ranshosting_sync_client_to_service($id, $cid);
        $created++;
    }
    update_option('rh_db_version', '2.4.0'); // demo data is already in the new shape
    return $created;
}

function ranshosting_remove_demo_services() {
    $ids = get_posts([
        'post_type' => ['rans_hosting_service', 'rans_client'], 'post_status' => 'any',
        'meta_key' => '_rh_demo', 'meta_value' => '1', 'posts_per_page' => -1, 'fields' => 'ids',
    ]);
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    return count($ids);
}

/* Schedule cron, seed demo data once if the portal is empty, flush rewrite rules on activation. */
add_action('after_switch_theme', function () {
    if (!wp_next_scheduled('ranshosting_daily_reminder_cron')) {
        wp_schedule_event(time(), 'daily', 'ranshosting_daily_reminder_cron');
    }
    if (!get_option('rh_demo_seeded')) {
        $has = get_posts([
            'post_type' => 'rans_hosting_service', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids',
        ]);
        if (!$has) {
            ranshosting_seed_demo_services();
        }
        update_option('rh_demo_seeded', '1');
    }
    flush_rewrite_rules();
});
