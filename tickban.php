<?php
/**
 * Plugin Name: تیک‌بان
 * Description: افزونهٔ پایش کاریونیت. نام، آدرس، روز و ساعت بازدید سایت‌ها را می‌گیرد، سر موعد چک می‌کند و آخرین وضعیت را سبز یا قرمز نشان می‌دهد.
 * Version: 1.3.0
 * Author: کاریونیت
 * Author URI: https://carunit.ir
 * Text Domain: tickban
 */

if (!defined('ABSPATH')) {
    exit;
}

const TICKBAN_VERSION = '1.3.0';
const TICKBAN_MONITORS = 'tickban_monitors';
const TICKBAN_LOG = 'tickban_log';
const TICKBAN_SETTINGS = 'tickban_settings';

add_action('init', 'tickban_run_due');
add_action('tickban_cron', 'tickban_run_due');
add_action('admin_menu', 'tickban_menu');
add_action('admin_enqueue_scripts', 'tickban_assets');
add_action('admin_post_tickban_save_site', 'tickban_save_site');
add_action('admin_post_tickban_save_bale', 'tickban_save_bale');
add_action('admin_post_tickban_delete', 'tickban_delete');
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

function tickban_assets(string $hook): void
{
    if ($hook !== 'toplevel_page_tickban') {
        return;
    }
    wp_enqueue_style('tickban-font', 'https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700;800;900&display=swap', [], null);
}

function tickban_week(): array
{
    return ['6' => 'شنبه', '0' => 'یکشنبه', '1' => 'دوشنبه', '2' => 'سه‌شنبه', '3' => 'چهارشنبه', '4' => 'پنجشنبه', '5' => 'جمعه'];
}

function tickban_find(string $id): ?array
{
    foreach (tickban_monitors() as $m) {
        if (($m['id'] ?? '') === $id) {
            return $m;
        }
    }
    return null;
}

function tickban_history(string $id): array
{
    $rows = [];
    foreach (tickban_log() as $key => $row) {
        if (str_starts_with((string) $key, $id . '|') && is_array($row)) {
            $parts = explode('|', (string) $key);
            $row['date'] = $parts[1] ?? '';
            $rows[] = $row;
        }
    }
    return array_reverse($rows);
}

function tickban_latest(string $id): ?array
{
    $rows = tickban_history($id);
    return $rows[0] ?? null;
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

function tickban_days_label(array $m): string
{
    if (($m['mode'] ?? 'daily') !== 'pick' || empty($m['days'])) {
        return 'هر روز';
    }
    $week = tickban_week();
    $names = [];
    foreach ($week as $num => $title) {
        if (in_array((string) $num, $m['days'], true)) {
            $names[] = $title;
        }
    }
    return $names ? implode('، ', $names) : 'هر روز';
}

function tickban_save_site(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_save_site');
    $url = esc_url_raw(trim((string) wp_unslash($_POST['url'] ?? '')));
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        wp_safe_redirect(admin_url('admin.php?page=tickban&err=url'));
        exit;
    }
    $days = isset($_POST['days']) ? (array) wp_unslash($_POST['days']) : [];
    $days = array_values(array_intersect(array_map('strval', $days), ['0', '1', '2', '3', '4', '5', '6']));
    $mode = (($days && ($_POST['mode'] ?? '') === 'pick') || ($days && empty($_POST['everyday']))) ? 'pick' : 'daily';
    if (!empty($_POST['everyday'])) {
        $mode = 'daily';
        $days = [];
    }
    $times = tickban_parse_times((string) wp_unslash($_POST['times'] ?? '09:00'));
    if (!$times) {
        $times = ['09:00'];
    }
    $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
    $row = [
        'id' => $id !== '' ? $id : substr(md5($url . microtime(true)), 0, 8),
        'label' => sanitize_text_field(wp_unslash($_POST['label'] ?? '')),
        'url' => $url,
        'mode' => $mode,
        'days' => $days,
        'times' => $times,
    ];
    $monitors = tickban_monitors();
    $found = false;
    foreach ($monitors as $i => $m) {
        if (($m['id'] ?? '') === $row['id']) {
            $monitors[$i] = $row;
            $found = true;
        }
    }
    if (!$found) {
        $monitors[] = $row;
    }
    update_option(TICKBAN_MONITORS, array_values($monitors), false);
    wp_safe_redirect(admin_url('admin.php?page=tickban&saved=1'));
    exit;
}

function tickban_save_bale(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_save_bale');
    update_option(TICKBAN_SETTINGS, [
        'bale_token' => sanitize_text_field(wp_unslash($_POST['bale_token'] ?? '')),
        'bale_chat' => sanitize_text_field(wp_unslash($_POST['bale_chat'] ?? '')),
        'tg_token' => sanitize_text_field(wp_unslash($_POST['tg_token'] ?? '')),
        'tg_chat' => sanitize_text_field(wp_unslash($_POST['tg_chat'] ?? '')),
        'notify_ok' => !empty($_POST['notify_ok']),
        'notify_fail' => !empty($_POST['notify_fail']),
    ], false);
    wp_safe_redirect(admin_url('admin.php?page=tickban&bale=1'));
    exit;
}

function tickban_delete(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_delete');
    $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
    $monitors = array_values(array_filter(tickban_monitors(), static function ($m) use ($id) {
        return ($m['id'] ?? '') !== $id;
    }));
    update_option(TICKBAN_MONITORS, $monitors, false);
    wp_safe_redirect(admin_url('admin.php?page=tickban&deleted=1'));
    exit;
}

function tickban_check_now(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('مجوز ندارید');
    }
    check_admin_referer('tickban_check');
    $id = sanitize_text_field(wp_unslash($_GET['id'] ?? ''));
    $ok = true;
    foreach (tickban_monitors() as $m) {
        if ($m['id'] === $id) {
            $row = tickban_hit($m, wp_date('H:i'), true);
            $ok = !empty($row['ok']);
        }
    }
    $flag = $ok ? 'checked=1' : 'failed=1';
    wp_safe_redirect(admin_url('admin.php?page=tickban&view=' . rawurlencode($id) . '&' . $flag));
    exit;
}

function tickban_due(array $m, string $date, string $now): array
{
    $w = wp_date('w', strtotime($date));
    if (($m['mode'] ?? 'daily') === 'pick' && !in_array((string) $w, $m['days'] ?? [], true)) {
        return [];
    }
    $due = [];
    foreach ($m['times'] ?? [] as $t) {
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
    $res = tickban_request($url, $headers, '');
    $fail = tickban_fail($res);
    if ($fail !== '' && tickban_dns_fail($fail)) {
        $ip = tickban_resolve($url);
        if ($ip !== '') {
            $res = tickban_request($url, $headers, $ip);
            $fail = tickban_fail($res);
        }
    }
    if ($fail !== '') {
        return ['code' => 0, 'error' => $fail];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    if ($code === 403 || $code === 0) {
        $ip = tickban_resolve($url);
        $retry = tickban_request($url, $headers + ['Cache-Control' => 'no-cache'], $ip);
        if (!is_wp_error($retry)) {
            $code = (int) wp_remote_retrieve_response_code($retry);
            $fail = '';
        }
    }
    return ['code' => $code, 'error' => $fail];
}

function tickban_fail($res): string
{
    return is_wp_error($res) ? $res->get_error_message() : '';
}

function tickban_dns_fail(string $msg): bool
{
    return stripos($msg, 'Resolving timed out') !== false || stripos($msg, 'Could not resolve') !== false;
}

function tickban_request(string $url, array $headers, string $ip)
{
    $pin = null;
    if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        $port = wp_parse_url($url, PHP_URL_SCHEME) === 'http' ? 80 : 443;
        $pin = static function ($handle) use ($host, $port, $ip) {
            if (is_resource($handle) || $handle instanceof \CurlHandle) {
                curl_setopt($handle, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $ip]);
            }
        };
        add_action('http_api_curl', $pin);
    }
    $res = wp_remote_get($url, [
        'timeout' => 25,
        'redirection' => 5,
        'headers' => $headers,
        'sslverify' => true,
    ]);
    if ($pin) {
        remove_action('http_api_curl', $pin);
    }
    return $res;
}

function tickban_resolve(string $url): string
{
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($host === '') {
        return '';
    }
    $cached = get_option('tickban_ips', []);
    $cached = is_array($cached) ? $cached : [];
    $res = wp_remote_get('https://1.1.1.1/dns-query?name=' . rawurlencode($host) . '&type=A', [
        'timeout' => 12,
        'headers' => ['Accept' => 'application/dns-json', 'User-Agent' => 'tickban'],
    ]);
    if (!is_wp_error($res)) {
        $data = json_decode((string) wp_remote_retrieve_body($res), true);
        foreach (($data['Answer'] ?? []) as $ans) {
            $ip = (string) ($ans['data'] ?? '');
            if ((int) ($ans['type'] ?? 0) === 1 && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $cached[$host] = $ip;
                update_option('tickban_ips', $cached, false);
                return $ip;
            }
        }
    }
    return (string) ($cached[$host] ?? '');
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

function tickban_fa_digits(string $value): string
{
    return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
}

function tickban_jalali(int $gy, int $gm, int $gd): string
{
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + (int) (($gy2 + 3) / 4) - (int) (($gy2 + 99) / 100) + (int) (($gy2 + 399) / 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * (int) ($days / 12053));
    $days %= 12053;
    $jy += 4 * (int) ($days / 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += (int) (($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int) ($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int) (($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function tickban_message(array $m, array $row): string
{
    $name = $m['label'] !== '' ? $m['label'] : 'بدون نام';
    $host = (string) wp_parse_url($m['url'], PHP_URL_HOST);
    $date = tickban_jalali((int) wp_date('Y'), (int) wp_date('n'), (int) wp_date('j'));
    $status = !empty($row['ok']) ? 'سالم' : 'خطا (کد ' . (int) $row['code'] . ')';
    $lines = [
        'تیک‌بان',
        'نام سایت: ' . $name,
        'دامنه: ' . ($host !== '' ? $host : $m['url']),
        'وضعیت: ' . $status,
        'ساعت: ' . ($row['at'] ?? ''),
        'تاریخ: ' . $date,
    ];
    if (empty($row['ok']) && !empty($row['error'])) {
        $lines[] = 'خطا: ' . $row['error'];
    }
    return tickban_fa_digits(implode("\n", $lines));
}

function tickban_notify(array $m, array $row): void
{
    $s = tickban_settings();
    if ($row['ok'] && empty($s['notify_ok'])) {
        return;
    }
    if (!$row['ok'] && empty($s['notify_fail'])) {
        return;
    }
    $text = tickban_message($m, $row);
    $bale_token = trim((string) ($s['bale_token'] ?? ''));
    $bale_chat = trim((string) ($s['bale_chat'] ?? ''));
    if ($bale_token !== '' && $bale_chat !== '') {
        wp_remote_post('https://tapi.bale.ai/bot' . rawurlencode($bale_token) . '/sendMessage', [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['chat_id' => $bale_chat, 'text' => $text]),
        ]);
    }
    $tg_token = trim((string) ($s['tg_token'] ?? ''));
    $tg_chat = trim((string) ($s['tg_chat'] ?? ''));
    if ($tg_token !== '' && $tg_chat !== '') {
        wp_remote_post('https://api.telegram.org/bot' . rawurlencode($tg_token) . '/sendMessage', [
            'timeout' => 12,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode(['chat_id' => $tg_chat, 'text' => $text]),
        ]);
    }
}

function tickban_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }
    $monitors = tickban_monitors();
    $s = tickban_settings();
    $edit_id = sanitize_text_field(wp_unslash($_GET['edit'] ?? ''));
    $view_id = sanitize_text_field(wp_unslash($_GET['view'] ?? ''));
    $edit = $edit_id !== '' ? tickban_find($edit_id) : null;
    $view = $view_id !== '' ? tickban_find($view_id) : null;
    $week = tickban_week();
    echo '<div class="wrap tb">';
    tickban_css();
    echo '<header class="tb-head"><span class="tb-mark">ت</span><div><h1>تیک‌بان</h1><p>پایش زمان‌بندی‌شدهٔ سایت‌ها، ساختهٔ کاریونیت</p></div></header>';
    echo '<section class="tb-intro"><p>تیک‌بان آدرس‌هایی که می‌دهید را در روز و ساعت انتخابی باز می‌کند. اگر سایت جواب بدهد وضعیت سبز می‌شود و اگر خطا بدهد قرمز. بین دو ساعت حداقل یک ساعت فاصله لازم است. چک وقتی انجام می‌شود که پیشخوان همان روز باز شود یا کرون ساعتی وردپرس اجرا شود.</p></section>';
    if (!empty($_GET['saved'])) {
        echo '<div class="tb-note ok">سایت ثبت شد.</div>';
    }
    if (!empty($_GET['deleted'])) {
        echo '<div class="tb-note ok">سایت حذف شد.</div>';
    }
    if (!empty($_GET['bale'])) {
        echo '<div class="tb-note ok">تنظیم ربات‌ها ذخیره شد.</div>';
    }
    if (!empty($_GET['err'])) {
        echo '<div class="tb-note bad">آدرس سایت معتبر نیست.</div>';
    }
    if (!empty($_GET['checked'])) {
        echo '<div class="tb-note ok">تست آنی انجام شد و سایت سالم بود.</div>';
    }
    if (!empty($_GET['failed'])) {
        echo '<div class="tb-note bad">تست آنی خطا داد. لاگ پایین همین رکورد است.</div>';
    }

    $label = $edit['label'] ?? '';
    $url = $edit['url'] ?? '';
    $times = implode(' ', $edit['times'] ?? ['12:00']);
    $everyday = !$edit || ($edit['mode'] ?? 'daily') !== 'pick';
    echo '<form class="tb-card" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('tickban_save_site');
    echo '<input type="hidden" name="action" value="tickban_save_site">';
    echo '<input type="hidden" name="id" value="' . esc_attr($edit['id'] ?? '') . '">';
    echo '<h2>' . ($edit ? 'ویرایش سایت' : 'ورود اطلاعات سایت') . '</h2>';
    echo '<div class="tb-grid">';
    echo '<label>نام سایت<input type="text" name="label" value="' . esc_attr($label) . '" placeholder="کلیدبان" required></label>';
    echo '<label>آدرس سایت<input type="url" name="url" value="' . esc_attr($url) . '" placeholder="https://" required></label>';
    echo '</div>';
    echo '<label class="tb-every"><input type="checkbox" name="everyday" value="1"' . ($everyday ? ' checked' : '') . '> هر روز</label>';
    echo '<div class="tb-days"><span>روزهای بازدید</span>';
    foreach ($week as $num => $title) {
        $on = $edit && in_array((string) $num, $edit['days'] ?? [], true) ? ' checked' : '';
        echo '<label><input type="checkbox" name="days[]" value="' . esc_attr($num) . '"' . $on . '> ' . esc_html($title) . '</label>';
    }
    echo '</div>';
    echo '<input type="hidden" name="mode" value="pick">';
    echo '<label>ساعت بازدید<input type="text" name="times" value="' . esc_attr($times) . '" placeholder="09:00 16:00"></label>';
    echo '<p class="tb-help">چند ساعت را با فاصله بنویسید. بین هر دو ساعت حداقل یک ساعت لازم است.</p>';
    echo '<div class="tb-actions"><button class="tb-btn" type="submit">' . ($edit ? 'ذخیره ویرایش' : 'ثبت سایت') . '</button>';
    if ($edit) {
        echo '<a class="tb-btn ghost" href="' . esc_url(admin_url('admin.php?page=tickban')) . '">انصراف</a>';
    }
    echo '</div></form>';

    echo '<section class="tb-list"><h2>سایت‌های ثبت‌شده</h2>';
    if (!$monitors) {
        echo '<p class="tb-help">هنوز سایتی ثبت نشده.</p>';
    }
    foreach ($monitors as $m) {
        $last = tickban_latest($m['id']);
        $state = $last ? (!empty($last['ok']) ? 'ok' : 'bad') : 'wait';
        $state_text = $state === 'ok' ? 'سالم' : ($state === 'bad' ? 'خطا' : 'هنوز چک نشده');
        $edit_url = admin_url('admin.php?page=tickban&edit=' . rawurlencode($m['id']));
        $view_url = admin_url('admin.php?page=tickban&view=' . rawurlencode($m['id']));
        $del_url = wp_nonce_url(admin_url('admin-post.php?action=tickban_delete&id=' . rawurlencode($m['id'])), 'tickban_delete');
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=tickban_check&id=' . rawurlencode($m['id'])), 'tickban_check');
        echo '<article class="tb-site ' . esc_attr($state) . '">';
        echo '<div class="tb-site-main"><span class="tb-dot" title="' . esc_attr($state_text) . '"></span><div>';
        echo '<strong>' . esc_html($m['label'] !== '' ? $m['label'] : 'بدون نام') . '</strong>';
        echo '<a class="tb-url" href="' . esc_url($m['url']) . '" target="_blank" rel="noopener">' . esc_html($m['url']) . '</a>';
        echo '<p>' . esc_html(tickban_days_label($m)) . ' · ' . esc_html(implode(' ، ', $m['times'] ?? [])) . '</p>';
        echo '<p class="tb-last">آخرین وضعیت: <b>' . esc_html($state_text) . '</b>';
        if ($last) {
            echo ' · ' . esc_html(($last['date'] ?? '') . ' ' . ($last['at'] ?? '')) . ' · کد ' . esc_html((string) ($last['code'] ?? ''));
        }
        echo '</p></div></div>';
        echo '<div class="tb-icons">';
        echo '<a class="tb-btn small" href="' . esc_url($view_url) . '">وضعیت</a>';
        echo '<a class="tb-btn small dark" href="' . esc_url($test_url) . '">تست آنی</a>';
        echo '<a class="tb-icon" href="' . esc_url($edit_url) . '" title="ویرایش"><span class="dashicons dashicons-edit"></span></a>';
        echo '<a class="tb-icon danger" href="' . esc_url($del_url) . '" title="حذف" onclick="return confirm(\'این سایت حذف شود؟\')"><span class="dashicons dashicons-trash"></span></a>';
        echo '</div></article>';
    }
    echo '</section>';

    if ($view) {
        $rows = tickban_history($view['id']);
        $last = $rows[0] ?? null;
        echo '<section class="tb-card tb-detail" id="tb-detail"><h2>مشخصات رکورد</h2>';
        echo '<dl>';
        echo '<div><dt>نام</dt><dd>' . esc_html($view['label']) . '</dd></div>';
        echo '<div><dt>آدرس</dt><dd><a href="' . esc_url($view['url']) . '" target="_blank" rel="noopener">' . esc_html($view['url']) . '</a></dd></div>';
        echo '<div><dt>روزها</dt><dd>' . esc_html(tickban_days_label($view)) . '</dd></div>';
        echo '<div><dt>ساعت‌ها</dt><dd>' . esc_html(implode(' ، ', $view['times'] ?? [])) . '</dd></div>';
        echo '</dl>';
        $test_url = wp_nonce_url(admin_url('admin-post.php?action=tickban_check&id=' . rawurlencode($view['id'])), 'tickban_check');
        echo '<p><a class="tb-btn dark" href="' . esc_url($test_url) . '">تست آنی وضعیت</a></p>';
        if ($last && empty($last['ok'])) {
            echo '<div class="tb-log"><h3>لاگ خطا</h3><p>کد ' . esc_html((string) $last['code']) . ' در ' . esc_html(($last['date'] ?? '') . ' ' . ($last['at'] ?? '')) . '</p>';
            echo '<pre>' . esc_html($last['error'] !== '' ? $last['error'] : 'پاسخ نامعتبر، بدون متن خطا') . '</pre></div>';
        } elseif ($last) {
            echo '<div class="tb-log ok"><h3>آخرین چک سالم بود</h3><p>کد ' . esc_html((string) $last['code']) . ' در ' . esc_html(($last['date'] ?? '') . ' ' . ($last['at'] ?? '')) . '</p></div>';
        }
        if ($rows) {
            echo '<h3>تاریخچه</h3><ul class="tb-hist">';
            foreach (array_slice($rows, 0, 12) as $row) {
                $cls = !empty($row['ok']) ? 'ok' : 'bad';
                echo '<li class="' . $cls . '">' . esc_html(($row['date'] ?? '') . ' ' . ($row['at'] ?? '') . ' · اسلات ' . ($row['slot'] ?? '') . ' · کد ' . ($row['code'] ?? ''));
                if (!empty($row['manual'])) {
                    echo ' · دستی';
                }
                if (empty($row['ok']) && !empty($row['error'])) {
                    echo '<br>' . esc_html($row['error']);
                }
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</section>';
    }

    echo '<form class="tb-card tb-bale" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('tickban_save_bale');
    echo '<input type="hidden" name="action" value="tickban_save_bale">';
    echo '<h2>ربات‌ها، یکی برای همهٔ سایت‌ها</h2>';
    echo '<p class="tb-help">یک ربات بله و یک ربات تلگرام کافی است. بعد از هر چک، برای هر سایت یک پیام جدا می‌رود: نام، دامنه، وضعیت، ساعت و تاریخ شمسی.</p>';
    echo '<h3>بله</h3><div class="tb-grid">';
    echo '<label>توکن ربات<input type="text" name="bale_token" value="' . esc_attr($s['bale_token'] ?? '') . '" placeholder="123456:ABC"></label>';
    echo '<label>آیدی عددی گیرنده<input type="text" name="bale_chat" value="' . esc_attr($s['bale_chat'] ?? '') . '"></label></div>';
    echo '<h3>تلگرام</h3><div class="tb-grid">';
    echo '<label>توکن ربات<input type="text" name="tg_token" value="' . esc_attr($s['tg_token'] ?? '') . '" placeholder="123456:ABC"></label>';
    echo '<label>آیدی عددی گیرنده<input type="text" name="tg_chat" value="' . esc_attr($s['tg_chat'] ?? '') . '" placeholder="-100..."></label></div>';
    echo '<label class="tb-every"><input type="checkbox" name="notify_ok"' . (!empty($s['notify_ok']) ? ' checked' : '') . '> بعد از چک موفق پیام بده</label>';
    echo '<label class="tb-every"><input type="checkbox" name="notify_fail"' . (!empty($s['notify_fail']) ? ' checked' : '') . '> اگر جواب نداد هم پیام بده</label>';
    echo '<button class="tb-btn" type="submit">ذخیره ربات‌ها</button></form>';
    echo '<p class="tb-foot">تیک‌بان ۱.۳.۰ · کاریونیت</p></div>';
}

function tickban_css(): void
{
    echo '<style>
    .tb{font-family:Vazirmatn,Tahoma,sans-serif;max-width:980px;color:#0D0D0D}
    .tb-head{display:flex;gap:14px;align-items:center;margin:18px 0 8px}
    .tb-mark{width:52px;height:52px;border-radius:14px;background:#F5C518;color:#0D0D0D;display:grid;place-items:center;font-weight:900;font-size:24px}
    .tb h1{font-size:28px;font-weight:900;margin:0}
    .tb h2{font-size:18px;font-weight:900;margin:0 0 12px}
    .tb-head p,.tb-intro p,.tb-help,.tb-foot{color:#6E6E6E}
    .tb-intro,.tb-card,.tb-site{background:#fff;border:1px solid #E8E8E8;border-radius:16px;padding:16px 18px;margin:12px 0;box-shadow:0 10px 30px rgba(13,13,13,.04)}
    .tb-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .tb label{display:block;font-weight:700;margin:8px 0}
    .tb input[type=text],.tb input[type=url]{width:100%;margin-top:6px;padding:10px 12px;border:1px solid #E8E8E8;border-radius:12px;background:#F4F4F4}
    .tb-days{display:flex;flex-wrap:wrap;gap:8px;margin:10px 0}
    .tb-days label,.tb-every{font-weight:500}
    .tb-btn{display:inline-flex;align-items:center;justify-content:center;height:42px;padding:0 16px;border-radius:12px;background:#F5C518;color:#0D0D0D;font-weight:800;text-decoration:none;border:0;cursor:pointer}
    .tb-btn.dark{background:#0D0D0D;color:#fff}
    .tb-btn.ghost{background:#F4F4F4}
    .tb-btn.small{height:36px;padding:0 12px}
    .tb-actions{display:flex;gap:8px;margin-top:12px}
    .tb-site{display:flex;justify-content:space-between;gap:12px;align-items:center}
    .tb-site-main{display:flex;gap:12px;align-items:flex-start}
    .tb-dot{width:14px;height:14px;border-radius:50%;margin-top:6px;background:#6E6E6E}
    .tb-site.ok .tb-dot,.tb-hist .ok{background:#1E9E5A}
    .tb-site.bad .tb-dot,.tb-hist .bad{background:#D93838}
    .tb-site.ok{border-color:#b7e4c7}
    .tb-site.bad{border-color:#f5b4b4}
    .tb-url{display:block;color:#5C4A00;direction:ltr;text-align:right}
    .tb-last b{font-weight:800}
    .tb-icons{display:flex;gap:8px;align-items:center}
    .tb-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;background:#F4F4F4;color:#0D0D0D;text-decoration:none}
    .tb-icon.danger{color:#D93838}
    .tb-note{border-radius:12px;padding:10px 12px;margin:10px 0}
    .tb-note.ok{background:#E6F6EE;color:#14663b}
    .tb-note.bad,.tb-log{background:#FDECEC;color:#8d1f1f}
    .tb-log.ok{background:#E6F6EE;color:#14663b}
    .tb-log pre{white-space:pre-wrap;background:#fff;border-radius:10px;padding:10px}
    .tb-detail dl{display:grid;grid-template-columns:1fr 1fr;gap:8px}
    .tb-detail dt{color:#6E6E6E}
    .tb-hist{list-style:none;padding:0}
    .tb-hist li{margin:6px 0;padding:8px 10px;border-radius:10px;background:#F4F4F4}
    .tb-hist li.ok{background:#E6F6EE}
    .tb-hist li.bad{background:#FDECEC}
    .tb-foot{font-size:12px}
    @media(max-width:700px){.tb-grid,.tb-detail dl,.tb-site{grid-template-columns:1fr;display:block}.tb-icons{margin-top:10px}}
    </style>';
}
