<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

if (!$pdo) {
    http_response_code(503);
    ?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup ThriftApp</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/app.css"></head><body><main class="page" style="max-width:900px;margin:0 auto"><h1 class="h1">THRIFTAPP · SETUP</h1><p class="lead">Aplikasi belum dapat tersambung ke database MySQL.</p><div class="panel" style="margin-top:22px"><div class="panel__h"><h3>Langkah perbaikan</h3></div><div class="panel__b"><ol style="padding-left:22px;line-height:2"><li>Pastikan Apache dan MySQL sudah berjalan di XAMPP.</li><li>Buka phpMyAdmin dan import file <b>thriftapp_database.sql</b> yang disertakan bersama proyek.</li><li>Pastikan database bernama <b>thriftapp</b> telah terbentuk.</li><li>Sesuaikan host, username, dan password pada <code>config/config.php</code>.</li><li>Muat ulang halaman ini.</li></ol><p class="small muted" style="margin-top:12px">Pesan koneksi: <?=h($config['debug'] ? $dbError : 'Koneksi database gagal. Periksa konfigurasi.'); ?></p></div></div></main></body></html><?php
    exit;
}

try { run_automatic_tasks(); } catch (Throwable $e) { error_log('ThriftApp scheduled workflow: '.$e->getMessage()); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/includes/actions.php';
    handle_post_action();
}

$route = (string)($_GET['r'] ?? 'home');
if ($route === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) { $params=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$params['path'],$params['domain'],$params['secure'],$params['httponly']); }
    session_destroy();
    session_start(); flash('Kamu berhasil keluar dari ThriftApp.','ok'); redirect('home');
}

require __DIR__ . '/includes/views_common.php';
require __DIR__ . '/includes/views_customer.php';
require __DIR__ . '/includes/views_staff.php';

switch ($route) {
    case 'home': render_home(); break;
    case 'catalog': render_catalog(); break;
    case 'product': render_product(); break;
    case 'shop': render_shop(); break;
    case 'login': render_login(); break;
    case 'register': render_register(); break;
    case 'account': render_account(); break;
    case 'cart': render_cart(); break;
    case 'favorites': render_favorites(); break;
    case 'checkout': render_checkout(); break;
    case 'payment': render_payment(); break;
    case 'success': render_success(); break;
    case 'orders': render_orders(); break;
    case 'order': render_order_detail(); break;
    case 'complaint': render_complaint(); break;
    case 'seller_dashboard': render_seller_dashboard(); break;
    case 'seller_products': render_seller_products(); break;
    case 'seller_upload': render_seller_upload(); break;
    case 'seller_orders': render_seller_orders(); break;
    case 'seller_wallet': render_seller_wallet(); break;
    case 'admin_dashboard': render_admin_dashboard(); break;
    case 'admin_verification': render_admin_verification(); break;
    case 'admin_complaints': render_admin_complaints(); break;
    case 'admin_users': render_admin_users(); break;
    case 'admin_categories': render_admin_categories(); break;
    case 'admin_withdrawals': render_admin_withdrawals(); break;
    default:
        http_response_code(404);
        customer_start('Halaman tidak ditemukan');
        echo '<main class="page"><div class="banner-empty"><h1 class="h2">HALAMAN TIDAK DITEMUKAN</h1><p style="margin:12px 0">Halaman yang kamu cari tidak tersedia.</p><a class="btn" href="'.h(route_url('home')).'">Kembali ke beranda</a></div></main>';
        customer_end();
}
