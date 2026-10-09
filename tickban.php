<?php
/**
 * Plugin Name: تیک‌بان
 * Description: آدرس‌هایی که می‌دهید را در روز و ساعت انتخابی چک می‌کند، در تقویم تیک می‌زند و در صورت موفقیت به بله خبر می‌دهد.
 * Version: 1.1.0
 * Author: Tickban
 * Text Domain: tickban
 */

if (!defined('ABSPATH')) {
    exit;
}

const TICKBAN_VERSION = '1.1.0';
const TICKBAN_MONITORS = 'tickban_monitors';
const TICKBAN_LOG = 'tickban_log';
const TICKBAN_SETTINGS = 'tickban_settings';

add_action('init', 'tickban_run_due');
add_action('tickban_cron', 'tickban_run_due');
add_action('admin_menu', 'tickban_menu');
add_action('admin_post_tickban_save', 'tickban_save');
add_action('admin_post_tickban_check', 'tickban_check_now');
register_activation_hook(__FILE__, 'tickban_activate');
register_deactivation_hook(__FILE__, 'tickban_deactivate');

function tickban_activate(): void
{
    if (!wp_next_scheduled('tickban_cron')) {
        wp_schedule_event(time() + 60, 'hourly', 'tickban_cron');
    }
}

function tickban_deactivate(): void
{
    wp_clear_scheduled_hook('tickban_cron');
}

function tickban_monitors(): array
{
    $rows = get_option(TICKBAN_MONITORS, []);
    return is_array($rows) ? $rows : [];
}

function tickban_log(): array
{
    $rows = get_option(TICKBAN_LOG, []);
    return is_array($rows) ? $rows : [];
}

function tickban_settings(): array
{
    $s = get_option(TICKBAN_SETTINGS, []);
    return is_array($s) ? $s : [];
}

function tickban_menu(): void
{
    add_menu_page('تیک‌بان', 'تیک‌بان', 'manage_options', 'tickban', 'tickban_page', 'dashicons-yes-alt', 58);
}

function tickban_parse_times(string $raw): array
{
    preg_match_all('/([01]?\d|2[0-3]):([0-5]\d)/', $raw, $m, PREG_SET_ORDER);
    $times = [];
    foreach ($m as $hit) {
        $times[] = sprintf('%02d:%02d', (int) $hit[1], (int) $hit[2]);
    }
    $times = array_values(array_unique($times));
    sort($times);
    $prev = -1;
    $clean = [];
    foreach ($times as $t) {
        [$h, $min] = array_map('intval', explode(':', $t));
        $mins = $h * 60 + $min;
        if ($prev >= 0 && ($mins - $prev) < 60) {
            continue;
        }
        $clean[] = $t;
        $prev = $mins;
    }
    return $clean;
}

function tickban_save(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_save');
    $settings = [
        'bale_token' => sanitize_text_field(wp_unslash($_POST['bale_token'] ?? '')),
        'bale_chat' => sanitize_text_field(wp_unslash($_POST['bale_chat'] ?? '')),
        'notify_ok' => !empty($_POST['notify_ok']),
        'notify_fail' => !empty($_POST['notify_fail']),
    ];
    update_option(TICKBAN_SETTINGS, $settings, false);

    $urls = isset($_POST['url']) ? (array) wp_unslash($_POST['url']) : [];
    $labels = isset($_POST['label']) ? (array) wp_unslash($_POST['label']) : [];
    $modes = isset($_POST['mode']) ? (array) wp_unslash($_POST['mode']) : [];
    $times = isset($_POST['times']) ? (array) wp_unslash($_POST['times']) : [];
    $days_in = isset($_POST['days']) ? (array) wp_unslash($_POST['days']) : [];
    $monitors = [];
    foreach ($urls as $i => $url) {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            continue;
        }
        $day_list = isset($days_in[$i]) ? (array) $days_in[$i] : [];
        $day_list = array_values(array_intersect(array_map('strval', $day_list), ['0', '1', '2', '3', '4', '5', '6']));
        $mode = (($modes[$i] ?? '') === 'pick') ? 'pick' : 'daily';
        $monitors[] = [
            'id' => substr(md5($url . $i), 0, 8),
            'label' => sanitize_text_field($labels[$i] ?? ''),
            'url' => $url,
            'mode' => $mode,
            'days' => $day_list,
            'times' => tickban_parse_times((string) ($times[$i] ?? '09:00')),
        ];
    }
    update_option(TICKBAN_MONITORS, $monitors, false);
    wp_safe_redirect(admin_url('admin.php?page=tickban&saved=1'));
    exit;
}

function tickban_check_now(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_check');
    $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
    foreach (tickban_monitors() as $m) {
        if ($m['id'] === $id) {
            tickban_hit($m, wp_date('H:i'), true);
        }
    }
    wp_safe_redirect(admin_url('admin.php?page=tickban&checked=1'));
    exit;
}

function tickban_due(array $m, string $date, string $now): array
{
    $w = wp_date('w', strtotime($date));
    if ($m['mode'] === 'pick' && !in_array((string) $w, $m['days'], true)) {
        return [];
    }
    $due = [];
    foreach ($m['times'] as $t) {
        if ($t <= $now) {
            $due[] = $t;
        }
    }
    return $due;
}

function tickban_run_due(): void
{
    $date = wp_date('Y-m-d');
    $now = wp_date('H:i');
    $log = tickban_log();
    foreach (tickban_monitors() as $m) {
        foreach (tickban_due($m, $date, $now) as $t) {
            $key = $m['id'] . '|' . $date . '|' . $t;
            if (isset($log[$key])) {
                continue;
            }
            tickban_hit($m, $t, false);
            $log = tickban_log();
        }
    }
}

function tickban_fetch(string $url): array
{
    $headers = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'fa,en;q=0.8',
    ];
    $res = wp_remote_get($url, [
        'timeout' => 25,
        'redirection' => 5,
        'headers' => $headers,
        'sslverify' => true,
    ]);
    if (is_wp_error($res)) {
        return ['code' => 0, 'error' => $res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 403 || $code === 0) {
        $res = wp_remote_get($url, [
            'timeout' => 25,
            'redirection' => 5,
            'headers' => $headers + ['Cache-Control' => 'no-cache'],
        ]);
        if (!is_wp_error($res)) {
            $code = (int) wp_remote_retrieve_response_code($res);
        }
    }
    return ['code' => $code, 'error' => ''];
}

function tickban_hit(array $m, string $slot, bool $manual): array
{
    $fetched = tickban_fetch($m['url']);
    $code = (int) $fetched['code'];
    $ok = $code >= 200 && $code < 400;
    $date = wp_date('Y-m-d');
    $key = $m['id'] . '|' . $date . '|' . $slot;
    $row = [
        'ok' => $ok,
        'code' => $code,
        'error' => $fetched['error'],
        'at' => wp_date('H:i'),
        'url' => $m['url'],
        'slot' => $slot,
        'manual' => $manual,
    ];
    $log = tickban_log();
    $log[$key] = $row;
    if (count($log) > 400) {
        $log = array_slice($log, -250, null, true);
    }
    update_option(TICKBAN_LOG, $log, false);
    tickban_notify($m, $row);
    return $row;
}

function tickban_notify(array $m, array $row): void
{
    $s = tickban_settings();
    $token = trim((string) ($s['bale_token'] ?? ''));
    $chat = trim((string) ($s['bale_chat'] ?? ''));
    if ($token === '' || $chat === '') {
        return;
    }
    if ($row['ok'] && empty($s['notify_ok'])) {
        return;
    }
    if (!$row['ok'] && empty($s['notify_fail'])) {
        return;
    }
    $name = $m['label'] !== '' ? $m['label'] : $m['url'];
    $text = $row['ok']
        ? "تیک‌بان\n{$name}\nساعت {$row['slot']} سالم بود."
        : "تیک‌بان\n{$name}\nساعت {$row['slot']} جواب نداد (کد {$row['code']}).";
    wp_remote_post('https://tapi.bale.ai/bot' . rawurlencode($token) . '/sendMessage', [
        'timeout' => 12,
        'headers' => ['Content-Type' => 'application/json'],
        'body' => wp_json_encode(['chat_id' => $chat, 'text' => $text]),
    ]);
}

function tickban_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $monitors = tickban_monitors();
    if (!$monitors) {
        $monitors[] = ['label' => 'کلیدبان', 'url' => 'https://kelidban.wuaze.com/', 'mode' => 'daily', 'days' => [], 'times' => ['16:41']];
    }
    $s = tickban_settings();
    $log = tickban_log();
    $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : wp_date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = wp_date('Y-m');
    }
    $start = strtotime($month . '-01');
    $count = (int) wp_date('t', $start);
    $pad = (int) wp_date('w', $start);
    $names = ['ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش'];
    $week = ['0' => 'یکشنبه', '1' => 'دوشنبه', '2' => 'سه‌شنبه', '3' => 'چهارشنبه', '4' => 'پنجشنبه', '5' => 'جمعه', '6' => 'شنبه'];
    echo '<div class="wrap tb">';
    echo '<style>
      .tb{font-family:tahoma,sans-serif;max-width:980px}
      .tb h1{font-weight:700}
      .tb-card{background:#fff;border:1px solid #e7e5e4;border-radius:16px;padding:16px 18px;margin:14px 0}
      .tb-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
      .tb label{display:block;font-size:13px;margin:8px 0 4px}
      .tb input[type=text],.tb input[type=url]{width:100%;padding:8px 10px;border:1px solid #d6d3d1;border-radius:10px}
      .tb-mon{border:1px dashed #d6d3d1;border-radius:14px;padding:12px;margin:10px 0}
      .tb-days label{display:inline-flex;gap:4px;margin-left:8px}
      .tb-cal{border-collapse:collapse;width:100%;max-width:640px}
      .tb-cal th,.tb-cal td{border:1px solid #e7e5e4;text-align:center;height:58px;width:14%}
      .tb-ok{background:#dcfce7}.tb-bad{background:#fee2e2}
      .tb-note{color:#57534e}
    </style>';
    echo '<h1>تیک‌بان</h1><p class="tb-note">آدرس‌ها را سر ساعت انتخابی چک می‌کند. بین دو ساعت حداقل یک ساعت فاصله لازم است. تیک فقط وقتی می‌خورد که سایت وردپرس همان روز باز شود یا کرون ساعتی اجرا شود.</p>';
    if (!empty($_GET['saved'])) {
        echo '<div class="notice notice-success"><p>ذخیره شد.</p></div>';
    }
    if (!empty($_GET['checked'])) {
        echo '<div class="notice notice-success"><p>چک دستی انجام شد.</p></div>';
    }
    echo '<div class="tb-card"><h2>تقویم این ماه</h2><table class="tb-cal"><tr>';
    foreach ($names as $n) {
        echo '<th>' . $n . '</th>';
    }
    echo '</tr><tr>';
    for ($i = 0; $i < $pad; $i++) {
        echo '<td></td>';
    }
    for ($d = 1; $d <= $count; $d++) {
        if (($pad + $d - 1) % 7 === 0 && $d !== 1) {
            echo '</tr><tr>';
        }
        $key = $month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT);
        $marks = [];
        $bad = false;
        foreach ($log as $lk => $row) {
            if (str_contains($lk, '|' . $key . '|')) {
                $marks[] = $row['slot'] . (!empty($row['ok']) ? ' ✓' : ' !');
                if (empty($row['ok'])) {
                    $bad = true;
                }
            }
        }
        $cls = $marks ? ($bad ? 'tb-bad' : 'tb-ok') : '';
        echo '<td class="' . $cls . '"><div>' . $d . '</div><div style="font-size:11px">' . esc_html(implode(' ', $marks)) . '</div></td>';
    }
    echo '</tr></table></div>';

    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('tickban_save');
    echo '<input type="hidden" name="action" value="tickban_save">';
    echo '<div class="tb-card"><h2>بله</h2><div class="tb-grid">';
    echo '<div><label>توکن ربات</label><input type="text" name="bale_token" value="' . esc_attr($s['bale_token'] ?? '') . '" placeholder="123456:ABC"></div>';
    echo '<div><label>آیدی عددی گیرنده</label><input type="text" name="bale_chat" value="' . esc_attr($s['bale_chat'] ?? '') . '"></div></div>';
    echo '<label><input type="checkbox" name="notify_ok" ' . (!empty($s['notify_ok']) ? 'checked' : '') . '> بعد از چک موفق پیام بده</label>';
    echo '<label><input type="checkbox" name="notify_fail" ' . (!empty($s['notify_fail']) ? 'checked' : '') . '> اگر جواب نداد هم پیام بده</label></div>';

    echo '<div class="tb-card"><h2>آدرس‌ها</h2>';
    foreach ($monitors as $i => $m) {
        $times = implode(' ، ', $m['times'] ?? ['09:00']);
        echo '<div class="tb-mon">';
        echo '<label>نام</label><input type="text" name="label[' . $i . ']" value="' . esc_attr($m['label'] ?? '') . '">';
        echo '<label>آدرس</label><input type="url" name="url[' . $i . ']" value="' . esc_attr($m['url'] ?? '') . '">';
        echo '<label>بازه</label><select name="mode[' . $i . ']"><option value="daily"' . (($m['mode'] ?? '') !== 'pick' ? ' selected' : '') . '>هر روز</option><option value="pick"' . (($m['mode'] ?? '') === 'pick' ? ' selected' : '') . '>روزهای انتخابی</option></select>';
        echo '<div class="tb-days">';
        foreach ($week as $num => $title) {
            $on = in_array((string) $num, $m['days'] ?? [], true) ? ' checked' : '';
            echo '<label><input type="checkbox" name="days[' . $i . '][]" value="' . $num . '"' . $on . '> ' . $title . '</label>';
        }
        echo '</div>';
        echo '<label>ساعت‌ها، با فاصله حداقل یک ساعت. مثال: 09:00 16:41</label><input type="text" name="times[' . $i . ']" value="' . esc_attr($times) . '">';
        if (!empty($m['id'])) {
            $url = wp_nonce_url(admin_url('admin-post.php?action=tickban_check&id=' . $m['id']), 'tickban_check');
            echo '<p><a href="' . esc_url($url) . '">چک همین حالا</a></p>';
        }
        echo '</div>';
    }
    $n = count($monitors);
    echo '<div class="tb-mon"><label>آدرس تازه</label><input type="url" name="url[' . $n . ']" placeholder="https://"><label>نام</label><input type="text" name="label[' . $n . ']"><input type="hidden" name="mode[' . $n . ']" value="daily"><label>ساعت</label><input type="text" name="times[' . $n . ']" placeholder="09:00"></div>';
    submit_button('ذخیره تنظیمات');
    echo '</div></form></div>';
}
