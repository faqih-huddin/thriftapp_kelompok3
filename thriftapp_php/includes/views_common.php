<?php
declare(strict_types=1);

function page_head(string $title, string $area = 'customer'): void {
    $extra = $area === 'admin' ? '<link rel="stylesheet" href="assets/admin.css">' : ($area === 'seller' ? '<link rel="stylesheet" href="assets/seller.css">' : '');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#f4f4f2"><title>' . h($title) . ' — ThriftApp</title><link rel="stylesheet" href="assets/style.css"><link rel="stylesheet" href="assets/app.css">' . $extra . '</head><body>';
}
function render_flashes(): void {
    $flashes = pull_flashes();
    if (!$flashes) return;
    echo '<div class="flash-wrap" role="status">';
    foreach ($flashes as $f) echo '<div class="flash flash--' . h($f['type']) . '">' . h($f['message']) . '</div>';
    echo '</div>';
}
function customer_header(string $active = 'home'): void {
    $u = user();
    $cartCount = 0;
    if ($u) { try { $q=db()->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id=?'); $q->execute([(int)$u['id']]); $cartCount=(int)$q->fetchColumn(); } catch(Throwable){} }
    echo '<header class="top"><a class="logo" href="' . route_url('home') . '">THRIFTAPP</a>';
    echo '<nav class="nav nav--main" aria-label="Utama"><a class="' . ($active==='catalog'?'is-on':'') . '" href="' . route_url('catalog') . '">Katalog</a><a href="' . route_url('catalog',['sort'=>'newest']) . '">Baru masuk</a><a href="' . route_url($u&&((int)$u['is_seller']===1)?'seller_dashboard':'account') . '">Jual barang</a><a href="' . route_url('home') . '#alur">Cara kerja</a></nav>';
    echo '<nav class="nav nav--tools" aria-label="Akun"><a class="hide-s" href="' . route_url($u?'account':'login') . '">' . ($u ? h(explode(' ',$u['name'])[0]) : 'Akun') . '</a><a href="' . route_url('catalog') . '">Cari</a><a class="hide-s" href="' . route_url('favorites') . '">Favorit</a><a href="' . route_url('cart') . '">Keranjang <b data-cart-count>(' . $cartCount . ')</b></a><button class="burger" type="button">Menu</button></nav></header>';
}
function customer_footer(): void {
    echo '<footer class="foot"><div class="foot__mark">THRIFTAPP</div><div class="foot__cols"><div><h4>TENTANG</h4><p>Preloved dengan kondisi, ukuran, dan riwayat yang dijelaskan apa adanya.</p></div><div><h4>PEMBELI</h4><ul><li><a href="' . route_url('catalog') . '">Katalog</a></li><li><a href="' . route_url('orders') . '">Pesanan saya</a></li><li><a href="' . route_url('favorites') . '">Favorit</a></li></ul></div><div><h4>PENJUAL</h4><ul><li><a href="' . route_url('seller_dashboard') . '">Dashboard toko</a></li><li><a href="' . route_url('seller_upload') . '">Upload barang</a></li><li><a href="' . route_url('seller_wallet') . '">Saldo dan penarikan</a></li></ul></div><div><h4>LAYANAN</h4><ul><li>Komplain maksimal 2x24 jam setelah barang diterima</li><li>Dana ditahan sampai pesanan selesai</li></ul></div></div><div class="foot__bar"><span>© ' . date('Y') . ' ThriftApp · Proyek demonstrasi</span><span>Mode pembayaran sandbox · Tidak memproses uang asli</span></div></footer>';
}
function customer_start(string $title, string $active = 'home'): void { page_head($title,'customer'); customer_header($active); render_flashes(); }
function customer_end(): void { customer_footer(); echo '<script>document.querySelector(".burger")?.addEventListener("click",()=>document.querySelector(".nav--main")?.classList.toggle("is-mobile-open"));</script></body></html>'; }
function staff_start(string $title, string $area, string $active): void {
    $u=user(); page_head($title,$area);
    $admin = $area==='admin';
    $links = $admin ? [
        ['admin_dashboard','Dashboard','admin_dashboard'],['admin_verification','Verifikasi listing','admin_verification'],['admin_complaints','Komplain','admin_complaints'],['admin_users','Pengguna','admin_users'],['admin_categories','Kategori','admin_categories'],['admin_withdrawals','Penarikan penjual','admin_withdrawals']
    ] : [
        ['seller_dashboard','Dashboard','seller_dashboard'],['seller_upload','Upload barang','seller_upload'],['seller_products','Barang saya','seller_products'],['seller_orders','Pesanan masuk','seller_orders'],['seller_wallet','Saldo dan penarikan','seller_wallet']
    ];
    $role = $admin?'Area admin':'Area penjual';
    echo '<div class="app"><aside class="side"><a class="logo" href="' . route_url($admin?'admin_dashboard':'seller_dashboard') . '">THRIFTAPP</a><div class="side__role">' . $role . '</div>';
    foreach($links as $l) {
        $count='';
        if ($admin && $l[0]==='admin_verification') { try{$count=(string)db()->query("SELECT COUNT(*) FROM products WHERE status='pending_review'")->fetchColumn();}catch(Throwable){} }
        if ($admin && $l[0]==='admin_complaints') { try{$count=(string)db()->query("SELECT COUNT(*) FROM complaints WHERE status<>'resolved'")->fetchColumn();}catch(Throwable){} }
        if (!$admin && $l[0]==='seller_products') { try{$q=db()->prepare('SELECT COUNT(*) FROM products p JOIN shops s ON s.id=p.shop_id WHERE s.user_id=?');$q->execute([(int)$u['id']]);$count=(string)$q->fetchColumn();}catch(Throwable){} }
        if (!$admin && $l[0]==='seller_orders') { try{$q=db()->prepare("SELECT COUNT(*) FROM orders o JOIN shops s ON s.id=o.shop_id WHERE s.user_id=? AND o.status IN ('paid','processing')");$q->execute([(int)$u['id']]);$count=(string)$q->fetchColumn();}catch(Throwable){} }
        echo '<a class="' . ($active===$l[0]?'is-on':'') . '" href="' . route_url($l[0]) . '"><span>' . h($l[1]) . '</span>' . ($count!==''?'<em>'.h($count).'</em>':'') . '</a>';
    }
    echo '<div class="side__foot">' . h($u['name']??'Pengguna') . '<br><a href="' . route_url('account') . '" style="padding:8px 0 0;color:#d4d4d2">Akun</a><a href="' . route_url('logout') . '" style="padding:8px 0 0;color:#d4d4d2">Keluar</a></div></aside><div class="main"><div class="apphead"><span class="muted">' . $role . ' / ' . h($title) . '</span><div class="row"><a href="' . route_url('home') . '">Lihat sebagai pembeli</a><span>' . h($u['name']??'') . '</span></div></div><div class="content">';
    render_flashes();
}
function staff_end(): void { echo '</div></div></div></body></html>'; }
function pill(string $status): string { return '<span class="pill ' . status_class($status) . '">' . h(status_label($status)) . '</span>'; }
function page_title(string $kicker, string $title, string $sub = ''): void {
    echo '<div class="page-head"><div><div class="crumbs">ThriftApp <span>/</span> <b>' . h($kicker) . '</b></div><h1 class="h1">' . h($title) . '</h1>' . ($sub?'<p class="lead">'.h($sub).'</p>':'') . '</div></div>';
}
function product_cards(array $products): void {
    if (!$products) { echo '<div class="banner-empty">Belum ada barang yang cocok dengan pencarian ini.</div>'; return; }
    echo '<div class="grid products">';
    foreach ($products as $p) {
        $status=(string)($p['status']??'active'); $class=$status==='sold'?' card--sold':($status==='reserved'?' card--reserved':'');
        $img=product_card_image($p);
        echo '<a class="card' . $class . '" href="' . route_url('product',['id'=>(int)$p['id']]) . '"><div class="card__img"><span class="badge">' . (int)$p['condition_score'] . '/10</span><img src="' . h($img) . '" alt="' . h($p['title']) . '" loading="lazy">';
        if($status==='reserved') echo '<span class="sold">DIPESAN</span>'; elseif($status==='sold') echo '<span class="sold">TERJUAL</span>';
        echo '</div><div class="card__info"><span class="card__name">' . h($p['title']) . '</span><span class="plus">+</span><span class="card__price">' . money($p['price']) . '</span><span class="card__meta">Ukuran ' . h($p['size_label']) . ', ' . h($p['shop_city']??'') . '<br>' . h($p['shop_name']??'') . '</span></div></a>';
    }
    echo '</div>';
}
function get_product_cards(int $limit = 12, array $filters = []): array {
    $sql=product_catalog_sql(); $params=[];
    if (!empty($filters['q'])) { $sql.=' AND (p.title LIKE ? OR p.description LIKE ? OR b.name LIKE ? OR s.name LIKE ?)'; $needle='%'.$filters['q'].'%'; array_push($params,$needle,$needle,$needle,$needle); }
    if (!empty($filters['category_id'])) { $sql.=' AND (c.id=? OR c.parent_id=?)'; array_push($params,(int)$filters['category_id'],(int)$filters['category_id']); }
    if (!empty($filters['min_price'])) { $sql.=' AND p.price>=?'; $params[]=(int)$filters['min_price']; }
    if (!empty($filters['max_price'])) { $sql.=' AND p.price<=?'; $params[]=(int)$filters['max_price']; }
    if (!empty($filters['min_condition'])) { $sql.=' AND p.condition_score>=?'; $params[]=(int)$filters['min_condition']; }
    if (!empty($filters['size'])) { $sql.=' AND p.size_label=?'; $params[]=$filters['size']; }
    if (!empty($filters['shop_id'])) { $sql.=' AND s.id=?'; $params[]=(int)$filters['shop_id']; }
    $sort=$filters['sort']??'newest';
    if ($sort==='price_low') $sql.=" ORDER BY p.price ASC,p.published_at DESC";
    elseif ($sort==='price_high') $sql.=" ORDER BY p.price DESC,p.published_at DESC";
    elseif ($sort==='condition') $sql.=" ORDER BY p.condition_score DESC,p.published_at DESC";
    else $sql.=" ORDER BY FIELD(p.status,'active','reserved','sold'),p.published_at DESC,p.created_at DESC";
    $sql.=' LIMIT '.max(1,min(60,$limit));
    $q=db()->prepare($sql); $q->execute($params); return $q->fetchAll();
}
