<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
date_default_timezone_set('Asia/Makassar');

$config = require __DIR__ . '/../config/config.php';
$pdo = null;
$dbError = null;
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_port'], $config['db_name']);
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

function db(): PDO {
    global $pdo;
    if (!$pdo) {
        throw new RuntimeException('Database belum tersambung. Periksa config/config.php dan pastikan database thriftapp sudah di-import.');
    }
    return $pdo;
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function route_url(string $route = 'home', array $params = []): string {
    return 'index.php?' . http_build_query(array_merge(['r' => $route], $params));
}
function redirect(string $route = 'home', array $params = []): never {
    header('Location: ' . route_url($route, $params));
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['_csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">'; }
function verify_csrf(): void {
    $sent = (string)($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $sent)) {
        throw new RuntimeException('Sesi formulir sudah kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}
function flash(string $message, string $type = 'info'): void { $_SESSION['_flash'][] = ['message' => $message, 'type' => $type]; }
function pull_flashes(): array { $v = $_SESSION['_flash'] ?? []; unset($_SESSION['_flash']); return $v; }
function money(mixed $amount): string { return 'Rp' . number_format((float)$amount, 0, ',', '.'); }
function dt(mixed $value, string $format = 'd M Y, H:i'): string {
    if (!$value) return '—';
    try { return (new DateTime((string)$value))->format($format); } catch (Throwable) { return (string)$value; }
}
function user(): ?array {
    static $loaded = false;
    static $current = null;
    if ($loaded) return $current;
    $loaded = true;
    if (empty($_SESSION['user_id']) || !$GLOBALS['pdo']) return null;
    $q = db()->prepare("SELECT id,name,email,phone,role,is_seller,status FROM users WHERE id=? LIMIT 1");
    $q->execute([(int)$_SESSION['user_id']]);
    $current = $q->fetch() ?: null;
    if ($current && $current['status'] !== 'active') {
        unset($_SESSION['user_id']);
        $current = null;
    }
    return $current;
}
function require_login(): array {
    $u = user();
    if (!$u) {
        flash('Silakan masuk terlebih dahulu untuk melanjutkan.', 'warn');
        redirect('login');
    }
    return $u;
}
function require_admin(): array {
    $u = require_login();
    if ($u['role'] !== 'admin') { flash('Halaman ini hanya untuk admin.', 'bad'); redirect('home'); }
    return $u;
}
function require_seller(): array {
    $u = require_login();
    if ((int)$u['is_seller'] !== 1) { flash('Aktifkan mode penjual terlebih dahulu.', 'warn'); redirect('account'); }
    $shop = shop_for_user((int)$u['id']);
    if (!$shop) { $shop = create_shop_for_user($u); }
    return $u;
}
function shop_for_user(int $userId): ?array {
    $q = db()->prepare('SELECT * FROM shops WHERE user_id=? LIMIT 1'); $q->execute([$userId]); return $q->fetch() ?: null;
}
function slugify(string $text): string {
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? 'toko';
    return trim($text, '-') ?: 'toko';
}
function create_shop_for_user(array $u, string $name = '', string $city = ''): array {
    $pdo = db();
    $name = trim($name) ?: ('Toko ' . $u['name']);
    $city = trim($city) ?: 'Belum diatur';
    $base = slugify($name); $slug = $base; $n = 2;
    $check = $pdo->prepare('SELECT id FROM shops WHERE slug=?');
    while (true) { $check->execute([$slug]); if (!$check->fetch()) break; $slug = $base . '-' . $n++; }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET is_seller=1 WHERE id=?')->execute([(int)$u['id']]);
        $pdo->prepare('INSERT INTO shops (user_id,name,slug,description,city) VALUES (?,?,?,?,?)')
            ->execute([(int)$u['id'], $name, $slug, 'Toko preloved di ThriftApp.', $city]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return shop_for_user((int)$u['id']) ?? [];
}
function setting(string $key, string $fallback = ''): string {
    $q = db()->prepare('SELECT value FROM system_settings WHERE setting_key=?'); $q->execute([$key]); $v = $q->fetchColumn(); return $v === false ? $fallback : (string)$v;
}
function product_photo_url(?string $path): string {
    if (!$path) return 'assets/img/hero.jpg';
    $path = str_replace('\\', '/', $path);
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
    if (str_starts_with($path, 'uploads/')) return $path;
    return 'assets/' . ltrim($path, '/');
}
function product_by_id(int $id): ?array {
    $q = db()->prepare("SELECT p.*,s.name AS shop_name,s.city AS shop_city,s.slug AS shop_slug,s.id AS shop_id,c.name AS category_name,c.parent_id,b.name AS brand_name,u.name AS seller_name FROM products p JOIN shops s ON s.id=p.shop_id JOIN users u ON u.id=s.user_id JOIN categories c ON c.id=p.category_id LEFT JOIN brands b ON b.id=p.brand_id WHERE p.id=? LIMIT 1");
    $q->execute([$id]); return $q->fetch() ?: null;
}
function product_photos(int $productId): array {
    $q = db()->prepare('SELECT * FROM product_photos WHERE product_id=? ORDER BY sort_order,id'); $q->execute([$productId]); return $q->fetchAll();
}
function product_card_image(array $p): string {
    if (!empty($p['main_photo'])) return product_photo_url($p['main_photo']);
    $photos = product_photos((int)$p['id']);
    return $photos ? product_photo_url($photos[0]['path']) : 'assets/img/hero.jpg';
}
function product_catalog_sql(): string {
    return "SELECT p.id,p.title,p.price,p.size_label,p.size_type,p.color,p.gender,p.condition_score,p.status,p.published_at,c.id AS category_id,c.name AS category_name,pc.name AS parent_category_name,b.name AS brand_name,s.id AS shop_id,s.name AS shop_name,s.city AS shop_city,(SELECT COUNT(*) FROM product_defects d WHERE d.product_id=p.id) AS defect_count,(SELECT ph.path FROM product_photos ph WHERE ph.product_id=p.id ORDER BY ph.sort_order,ph.id LIMIT 1) AS main_photo FROM products p JOIN categories c ON c.id=p.category_id LEFT JOIN categories pc ON pc.id=c.parent_id LEFT JOIN brands b ON b.id=p.brand_id JOIN shops s ON s.id=p.shop_id WHERE p.status IN ('active','reserved','sold') AND s.is_active=1";
}
function status_label(string $status): string {
    $map = [
        'draft'=>'Draft','pending_review'=>'Menunggu verifikasi','rejected'=>'Ditolak','active'=>'Aktif','reserved'=>'Dipesan','sold'=>'Terjual','inactive'=>'Nonaktif',
        'awaiting_payment'=>'Menunggu pembayaran','paid'=>'Dibayar','processing'=>'Diproses','shipped'=>'Dikirim','delivered'=>'Diterima','completed'=>'Selesai','complained'=>'Dalam komplain','refunded'=>'Refund','cancelled'=>'Dibatalkan','expired'=>'Kedaluwarsa',
        'waiting_seller'=>'Menunggu penjual','needs_decision'=>'Menunggu keputusan admin','resolved'=>'Selesai','requested'=>'Diajukan','rejected'=>'Ditolak','completed'=>'Selesai','pending'=>'Menunggu','processed'=>'Diproses','failed'=>'Gagal'
    ];
    return $map[$status] ?? ucfirst(str_replace('_', ' ', $status));
}
function status_class(string $status): string {
    if (in_array($status, ['active','paid','shipped','completed','resolved','approved','processed'], true)) return 'pill--ok';
    if (in_array($status, ['pending_review','awaiting_payment','reserved','waiting_seller','needs_decision','requested','processing','delivered'], true)) return 'pill--warn';
    if (in_array($status, ['rejected','cancelled','expired','refunded','blocked','failed'], true)) return 'pill--bad';
    return 'pill--mute';
}
function run_automatic_tasks(): void {
    $pdo = db();
    // Transaksi yang belum dibayar otomatis kedaluwarsa setelah batas pembayaran.
    $expired = $pdo->query("SELECT id FROM checkouts WHERE status='awaiting_payment' AND payment_expires_at < NOW()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($expired as $checkoutId) {
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT id FROM orders WHERE checkout_id=? AND status='awaiting_payment'"); $q->execute([(int)$checkoutId]); $orders = $q->fetchAll(PDO::FETCH_COLUMN);
            $pdo->prepare("UPDATE checkouts SET status='expired' WHERE id=? AND status='awaiting_payment'")->execute([(int)$checkoutId]);
            $pdo->prepare("UPDATE payments SET status='expired' WHERE checkout_id=? AND status='pending'")->execute([(int)$checkoutId]);
            foreach ($orders as $orderId) {
                $items = $pdo->prepare('SELECT product_id FROM order_items WHERE order_id=?'); $items->execute([(int)$orderId]);
                foreach ($items->fetchAll(PDO::FETCH_COLUMN) as $productId) $pdo->prepare("UPDATE products SET status='active',reserved_until=NULL WHERE id=? AND status='reserved'")->execute([(int)$productId]);
                $pdo->prepare("UPDATE orders SET status='expired',cancel_reason='payment_timeout',cancelled_at=NOW() WHERE id=?")->execute([(int)$orderId]);
                $pdo->prepare("INSERT INTO order_status_logs (order_id,status,note,actor_id) VALUES (?,'expired','Batas pembayaran 30 menit terlewati.',NULL)")->execute([(int)$orderId]);
            }
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('ThriftApp expire payment: '.$e->getMessage()); }
    }
    // Pesanan yang sudah dibayar tetapi tidak dikirim dalam 48 jam direfund otomatis.
    $late = $pdo->query("SELECT id FROM orders WHERE status IN ('paid','processing') AND ship_deadline_at IS NOT NULL AND ship_deadline_at < NOW()")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($late as $orderId) {
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE"); $q->execute([(int)$orderId]); $o = $q->fetch();
            if (!$o || !in_array($o['status'], ['paid','processing'], true)) { $pdo->commit(); continue; }
            $pdo->prepare("UPDATE orders SET status='refunded',cancel_reason='seller_not_shipped',cancelled_at=NOW() WHERE id=?")->execute([(int)$orderId]);
            $pay = $pdo->prepare('SELECT p.id FROM payments p WHERE p.checkout_id=? LIMIT 1'); $pay->execute([(int)$o['checkout_id']]); $paymentId = $pay->fetchColumn() ?: null;
            $pdo->prepare("INSERT INTO refunds (order_id,payment_id,type,amount,status,processed_at) VALUES (?,?, 'seller_not_shipped',?,'processed',NOW())")
                ->execute([(int)$orderId, $paymentId, (int)$o['total']]);
            if ((int)$o['seller_net'] > 0) $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'refund','held',?,?)")
                ->execute([(int)$o['shop_id'],(int)$orderId,-(int)$o['seller_net'],'Dana ditarik kembali karena penjual terlambat mengirim.']);
            $pdo->prepare("UPDATE products p JOIN order_items oi ON oi.product_id=p.id SET p.status='active',p.sold_at=NULL,p.reserved_until=NULL WHERE oi.order_id=?")->execute([(int)$orderId]);
            $pdo->prepare("INSERT INTO order_status_logs (order_id,status,note,actor_id) VALUES (?,'refunded','Refund otomatis: pesanan tidak dikirim dalam 2x24 jam.',NULL)")->execute([(int)$orderId]);
            $pdo->prepare("INSERT INTO notifications (user_id,type,title,body,related_type,related_id) VALUES (?,?,?,?, 'order',?)")
                ->execute([(int)$o['buyer_id'],'order_cancelled','Pesanan direfund','Penjual tidak mengirim pesanan dalam batas waktu. Dana diproses untuk refund.',(int)$orderId]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('ThriftApp seller deadline: '.$e->getMessage()); }
    }
    // Pesanan terkirim dan diterima otomatis selesai sesudah jendela komplain 48 jam.
    $done = $pdo->query("SELECT * FROM orders WHERE status='delivered' AND complaint_deadline_at IS NOT NULL AND complaint_deadline_at < NOW()")->fetchAll();
    foreach ($done as $o) {
        $pdo->beginTransaction();
        try {
            $has = $pdo->prepare('SELECT id FROM complaints WHERE order_id=? LIMIT 1'); $has->execute([(int)$o['id']]);
            if ($has->fetch()) { $pdo->commit(); continue; }
            $pdo->prepare("UPDATE orders SET status='completed',completion_type='auto',completed_at=NOW() WHERE id=? AND status='delivered'")->execute([(int)$o['id']]);
            if ((int)$o['seller_net'] > 0) {
                $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','held',?,'Dana dilepas otomatis setelah batas komplain berakhir')")
                    ->execute([(int)$o['shop_id'],(int)$o['id'],-(int)$o['seller_net']]);
                $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','available',?,'Dana dilepas otomatis setelah batas komplain berakhir')")
                    ->execute([(int)$o['shop_id'],(int)$o['id'],(int)$o['seller_net']]);
            }
            $pdo->prepare("INSERT INTO order_status_logs (order_id,status,note,actor_id) VALUES (?,'completed','Pesanan selesai otomatis setelah 2x24 jam.',NULL)")->execute([(int)$o['id']]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('ThriftApp auto-complete: '.$e->getMessage()); }
    }
}
