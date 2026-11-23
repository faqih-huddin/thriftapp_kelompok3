<?php
declare(strict_types=1);

function postv(string $key, mixed $default = ''): mixed { return $_POST[$key] ?? $default; }
function post_int(string $key, int $default = 0): int { return filter_var($_POST[$key] ?? $default, FILTER_VALIDATE_INT) !== false ? (int)$_POST[$key] : $default; }
function need_user_for_action(): array { return require_login(); }
function notify_user(int $userId, string $type, string $title, string $body, ?string $relatedType = null, ?int $relatedId = null): void {
    db()->prepare('INSERT INTO notifications (user_id,type,title,body,related_type,related_id) VALUES (?,?,?,?,?,?)')->execute([$userId,$type,$title,$body,$relatedType,$relatedId]);
}
function add_status_log(int $orderId, string $status, string $note, ?int $actorId): void {
    db()->prepare('INSERT INTO order_status_logs (order_id,status,note,actor_id) VALUES (?,?,?,?)')->execute([$orderId,$status,$note,$actorId]);
}
function own_order(int $orderId, int $userId, string $side = 'buyer'): ?array {
    $sql = "SELECT o.* FROM orders o";
    if ($side === 'seller') $sql .= ' JOIN shops s ON s.id=o.shop_id';
    $sql .= $side === 'seller' ? ' WHERE o.id=? AND s.user_id=? LIMIT 1' : ' WHERE o.id=? AND o.buyer_id=? LIMIT 1';
    $q = db()->prepare($sql); $q->execute([$orderId,$userId]); return $q->fetch() ?: null;
}
function upload_image(string $field, string $folder, int $index = 0, int $maxBytes = 5242880): ?string {
    if (!isset($_FILES[$field])) return null;
    $f = $_FILES[$field];
    if (is_array($f['name'] ?? null)) {
        if (!isset($f['name'][$index]) || ($f['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        $name=$f['name'][$index]; $tmp=$f['tmp_name'][$index]; $error=$f['error'][$index]; $size=(int)$f['size'][$index];
    } else {
        $name=$f['name'] ?? ''; $tmp=$f['tmp_name'] ?? ''; $error=$f['error'] ?? UPLOAD_ERR_NO_FILE; $size=(int)($f['size'] ?? 0);
        if ($error === UPLOAD_ERR_NO_FILE) return null;
    }
    if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Ada foto yang gagal diunggah. Coba pilih file kembali.');
    if ($size <= 0 || $size > $maxBytes) throw new RuntimeException('Ukuran setiap foto maksimal 5 MB.');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('File foto harus JPG, PNG, atau WEBP yang valid.');
    $ext = match ($info['mime']) { 'image/jpeg'=>'jpg', 'image/png'=>'png', 'image/webp'=>'webp', default=>'jpg' };
    $dir = dirname(__DIR__) . '/' . trim($folder, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Folder upload tidak dapat dibuat.');
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $dir . '/' . $filename)) throw new RuntimeException('Foto tidak bisa disimpan. Pastikan folder uploads memiliki izin tulis.');
    return trim($folder, '/') . '/' . $filename;
}
function resolve_root_category_id(int $categoryId): int {
    $q = db()->prepare('SELECT id,parent_id FROM categories WHERE id=? AND is_active=1'); $q->execute([$categoryId]); $c=$q->fetch();
    if (!$c) throw new RuntimeException('Kategori yang dipilih tidak tersedia.');
    return $c['parent_id'] ? (int)$c['parent_id'] : (int)$c['id'];
}
function handle_post_action(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    try { verify_csrf(); } catch (Throwable $e) { flash('Token formulir tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.','bad'); redirect((string)postv('back_route','home')); }
    $action = (string)postv('action');
    $pdo = db();
    try {
        switch ($action) {
            case 'login': {
                $email = strtolower(trim((string)postv('email')));
                $password = (string)postv('password');
                $q = $pdo->prepare('SELECT * FROM users WHERE email=? LIMIT 1'); $q->execute([$email]); $u=$q->fetch();
                if (!$u || !password_verify($password, $u['password_hash'])) throw new RuntimeException('Email atau kata sandi tidak sesuai.');
                if ($u['status'] !== 'active') throw new RuntimeException('Akun sedang diblokir. Hubungi admin untuk informasi lebih lanjut.');
                session_regenerate_id(true); $_SESSION['user_id']=(int)$u['id'];
                $pdo->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([(int)$u['id']]);
                flash('Selamat datang kembali, ' . $u['name'] . '!', 'ok');
                if ($u['role']==='admin') redirect('admin_dashboard');
                redirect('home');
            }
            case 'register': {
                $name=trim((string)postv('name')); $email=strtolower(trim((string)postv('email'))); $phone=trim((string)postv('phone'));
                $pass=(string)postv('password'); $confirm=(string)postv('password_confirm');
                if (mb_strlen($name)<2 || !filter_var($email,FILTER_VALIDATE_EMAIL) || $phone==='') throw new RuntimeException('Nama, email yang valid, dan nomor telepon wajib diisi.');
                if (strlen($pass)<8) throw new RuntimeException('Kata sandi minimal 8 karakter.');
                if ($pass!==$confirm) throw new RuntimeException('Konfirmasi kata sandi belum sama.');
                if (empty($_POST['terms'])) throw new RuntimeException('Centang persetujuan syarat penggunaan terlebih dahulu.');
                $q=$pdo->prepare('SELECT id FROM users WHERE email=?'); $q->execute([$email]); if ($q->fetch()) throw new RuntimeException('Email sudah terdaftar. Silakan masuk.');
                $pdo->prepare('INSERT INTO users (name,email,phone,password_hash,terms_accepted_at) VALUES (?,?,?,?,NOW())')->execute([$name,$email,$phone,password_hash($pass,PASSWORD_DEFAULT)]);
                $uid=(int)$pdo->lastInsertId();
                session_regenerate_id(true); $_SESSION['user_id']=$uid;
                if (!empty($_POST['seller'])) create_shop_for_user(['id'=>$uid,'name'=>$name], trim((string)postv('shop_name')), trim((string)postv('city')));
                flash('Akun berhasil dibuat. Selamat datang di ThriftApp!', 'ok'); redirect('home');
            }
            case 'toggle_favorite': {
                $u=need_user_for_action(); $pid=post_int('product_id');
                $p=product_by_id($pid); if (!$p) throw new RuntimeException('Produk tidak ditemukan.');
                $q=$pdo->prepare('SELECT user_id FROM favorites WHERE user_id=? AND product_id=?'); $q->execute([(int)$u['id'],$pid]);
                if ($q->fetch()) { $pdo->prepare('DELETE FROM favorites WHERE user_id=? AND product_id=?')->execute([(int)$u['id'],$pid]); flash('Produk dihapus dari favorit.','ok'); }
                else { $pdo->prepare('INSERT INTO favorites (user_id,product_id) VALUES (?,?)')->execute([(int)$u['id'],$pid]); flash('Produk disimpan ke favorit.','ok'); }
                redirect((string)postv('return_route','product'), (array)(postv('return_params',[]) ?: ['id'=>$pid]));
            }
            case 'add_cart': {
                $u=need_user_for_action(); $pid=post_int('product_id'); $p=product_by_id($pid);
                if (!$p || $p['status']!=='active') throw new RuntimeException('Produk ini sudah dipesan atau tidak tersedia.');
                $q=$pdo->prepare('INSERT IGNORE INTO cart_items (user_id,product_id) VALUES (?,?)'); $q->execute([(int)$u['id'],$pid]);
                flash($q->rowCount() ? 'Produk ditambahkan ke keranjang.' : 'Produk sudah ada di keranjang.', 'ok'); redirect('cart');
            }
            case 'remove_cart': {
                $u=need_user_for_action(); $pdo->prepare('DELETE FROM cart_items WHERE user_id=? AND product_id=?')->execute([(int)$u['id'],post_int('product_id')]); flash('Produk dihapus dari keranjang.','ok'); redirect('cart');
            }
            case 'save_address': {
                $u=need_user_for_action();
                $id=post_int('address_id'); $label=trim((string)postv('label','Rumah')); $recipient=trim((string)postv('recipient_name')); $phone=trim((string)postv('phone')); $street=trim((string)postv('street')); $district=trim((string)postv('district')); $city=trim((string)postv('city')); $province=trim((string)postv('province')); $postal=trim((string)postv('postal_code'));
                if (!$recipient||!$phone||!$street||!$city||!$province||!$postal) throw new RuntimeException('Lengkapi penerima, telepon, alamat, kota, provinsi, dan kode pos.');
                $pdo->beginTransaction();
                if (!empty($_POST['is_default'])) $pdo->prepare('UPDATE addresses SET is_default=0 WHERE user_id=?')->execute([(int)$u['id']]);
                if ($id) {
                    $ownsAddress=$pdo->prepare('SELECT id FROM addresses WHERE id=? AND user_id=? FOR UPDATE');
                    $ownsAddress->execute([$id,(int)$u['id']]);
                    if (!$ownsAddress->fetch()) throw new RuntimeException('Alamat tidak ditemukan atau bukan milik akun Anda.');
                    $q=$pdo->prepare('UPDATE addresses SET label=?,recipient_name=?,phone=?,street=?,district=?,city=?,province=?,postal_code=?,is_default=? WHERE id=? AND user_id=?');
                    $q->execute([$label,$recipient,$phone,$street,$district?:null,$city,$province,$postal,!empty($_POST['is_default'])?1:0,$id,(int)$u['id']]);
                } else {
                    $pdo->prepare('INSERT INTO addresses (user_id,label,recipient_name,phone,street,district,city,province,postal_code,is_default) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([(int)$u['id'],$label,$recipient,$phone,$street,$district?:null,$city,$province,$postal,!empty($_POST['is_default'])?1:0]);
                }
                $pdo->commit(); flash('Alamat berhasil disimpan.','ok'); redirect('account');
            }
            case 'enable_seller': {
                $u=need_user_for_action();
                $shop=shop_for_user((int)$u['id']);
                if (!$shop) $shop=create_shop_for_user($u,trim((string)postv('shop_name')),trim((string)postv('city')));
                else { $pdo->prepare('UPDATE users SET is_seller=1 WHERE id=?')->execute([(int)$u['id']]); }
                flash('Mode penjual aktif. Lengkapi profil toko dan mulai unggah barang.','ok'); redirect('seller_dashboard');
            }
            case 'checkout_create': {
                $u=need_user_for_action(); $addressId=post_int('address_id'); $courierId=post_int('courier_id',1);
                $aq=$pdo->prepare('SELECT * FROM addresses WHERE id=? AND user_id=?'); $aq->execute([$addressId,(int)$u['id']]); $address=$aq->fetch();
                if (!$address) throw new RuntimeException('Pilih alamat pengiriman milik akun Anda.');
                $cq=$pdo->prepare('SELECT id,name FROM couriers WHERE id=? AND is_active=1'); $cq->execute([$courierId]); $courier=$cq->fetch(); if (!$courier) throw new RuntimeException('Kurir tidak tersedia.');
                $q=$pdo->prepare("SELECT p.*,s.user_id AS seller_user_id FROM cart_items ci JOIN products p ON p.id=ci.product_id JOIN shops s ON s.id=p.shop_id WHERE ci.user_id=? AND p.status='active' AND s.is_active=1 ORDER BY p.shop_id"); $q->execute([(int)$u['id']]); $items=$q->fetchAll();
                if (!$items) throw new RuntimeException('Keranjang kosong atau barang sudah tidak tersedia.');
                $byShop=[]; $itemsTotal=0; $weightTotal=0;
                foreach ($items as $it) { $byShop[(int)$it['shop_id']][]=$it; $itemsTotal+=(int)$it['price']; $weightTotal+=(int)$it['weight_gram']; }
                $shipPerShop=18000*count($byShop); $fee=(int)setting('service_fee','3000'); $grand=$itemsTotal+$shipPerShop+$fee;
                $expire=(new DateTime('+30 minutes'))->format('Y-m-d H:i:s');
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("INSERT INTO checkouts (buyer_id,address_id,items_total,shipping_total,service_fee,grand_total,status,payment_expires_at) VALUES (?,?,?,?,?,?,'awaiting_payment',?)")->execute([(int)$u['id'],$addressId,$itemsTotal,$shipPerShop,$fee,$grand,$expire]);
                    $checkoutId=(int)$pdo->lastInsertId();
                    $methodId=post_int('payment_method_id',1); $mq=$pdo->prepare('SELECT id FROM payment_methods WHERE id=? AND is_active=1'); $mq->execute([$methodId]); if (!$mq->fetch()) $methodId=1;
                    $va='88'.str_pad((string)random_int(100000000000,999999999999),14,'0',STR_PAD_LEFT);
                    $pdo->prepare("INSERT INTO payments (checkout_id,payment_method_id,gateway_ref,va_number,amount,status,is_sandbox,expires_at) VALUES (?,?,?, ?,?,'pending',1,?)")->execute([$checkoutId,$methodId,'SBX-'.$checkoutId.'-'.random_int(1000,9999),$va,$grand,$expire]);
                    $orderSeq=0;
                    foreach ($byShop as $shopId=>$shopItems) {
                        $shopTotal=0; $shopWeight=0; foreach ($shopItems as $it) { $shopTotal+=(int)$it['price']; $shopWeight+=(int)$it['weight_gram']; }
                        $shipping=18000; $commissionRate=(float)setting('commission_percent','5'); $commission=(int)round($shopTotal*$commissionRate/100); $net=$shopTotal-$commission;
                        $addressText=$address['street'].($address['district']?', '.$address['district']:'').', '.$address['city'].', '.$address['province'].' '.$address['postal_code'];
                        $temporaryNumber='TMP-'.bin2hex(random_bytes(6));
                        $pdo->prepare("INSERT INTO orders (order_number,checkout_id,buyer_id,shop_id,recipient_name,phone,address_text,destination_city,courier_id,courier_name,shipping_etd,total_weight_gram,items_total,shipping_cost,commission_rate,commission,seller_net,total,status,payment_expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'awaiting_payment',?)")
                            ->execute([$temporaryNumber,$checkoutId,(int)$u['id'],$shopId,$address['recipient_name'],$address['phone'],$addressText,$address['city'],$courierId,$courier['name'],'3-4 hari',$shopWeight,$shopTotal,$shipping,$commissionRate,$commission,$net,$shopTotal+$shipping,$expire]);
                        $orderId=(int)$pdo->lastInsertId();
                        $orderNumber='TA-'.date('ymd').'-'.str_pad((string)$orderId,5,'0',STR_PAD_LEFT);
                        $pdo->prepare('UPDATE orders SET order_number=? WHERE id=?')->execute([$orderNumber,$orderId]);
                        foreach ($shopItems as $it) {
                            $pdo->prepare('INSERT INTO order_items (order_id,product_id,title_snapshot,price_snapshot,size_snapshot,condition_snapshot) VALUES (?,?,?,?,?,?)')->execute([$orderId,(int)$it['id'],$it['title'],(int)$it['price'],$it['size_label'],(int)$it['condition_score']]);
                            $pdo->prepare("UPDATE products SET status='reserved',reserved_until=? WHERE id=? AND status='active'")->execute([$expire,(int)$it['id']]);
                        }
                        add_status_log($orderId,'awaiting_payment','Pesanan dibuat. Barang ditahan selama 30 menit.',(int)$u['id']);
                        $orderSeq++;
                    }
                    $pdo->prepare('DELETE FROM cart_items WHERE user_id=?')->execute([(int)$u['id']]);
                    $pdo->commit(); flash('Checkout dibuat. Selesaikan pembayaran sandbox dalam 30 menit.','ok'); redirect('payment',['id'=>$checkoutId]);
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            case 'pay_sandbox': {
                $u=need_user_for_action(); $checkoutId=post_int('checkout_id');
                $q=$pdo->prepare('SELECT * FROM checkouts WHERE id=? AND buyer_id=?'); $q->execute([$checkoutId,(int)$u['id']]); $co=$q->fetch();
                if (!$co) throw new RuntimeException('Checkout tidak ditemukan.');
                if ($co['status']==='paid') { flash('Pembayaran ini sudah tercatat.','ok'); redirect('success',['id'=>$checkoutId]); }
                if ($co['status']!=='awaiting_payment' || strtotime($co['payment_expires_at'])<time()) throw new RuntimeException('Batas pembayaran habis. Silakan checkout ulang.');
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE payments SET status='paid',paid_at=NOW() WHERE checkout_id=? AND status='pending'")->execute([$checkoutId]);
                    $pdo->prepare("UPDATE checkouts SET status='paid' WHERE id=?")->execute([$checkoutId]);
                    $q=$pdo->prepare("SELECT * FROM orders WHERE checkout_id=? AND status='awaiting_payment'"); $q->execute([$checkoutId]); $orders=$q->fetchAll();
                    foreach ($orders as $o) {
                        $pdo->prepare("UPDATE orders SET status='paid',paid_at=NOW(),ship_deadline_at=DATE_ADD(NOW(),INTERVAL 48 HOUR),processing_at=NOW() WHERE id=?")->execute([(int)$o['id']]);
                        $pdo->prepare("UPDATE products p JOIN order_items oi ON oi.product_id=p.id SET p.status='sold',p.sold_at=NOW(),p.reserved_until=NULL WHERE oi.order_id=?")->execute([(int)$o['id']]);
                        $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'hold','held',?,?)")->execute([(int)$o['shop_id'],(int)$o['id'],(int)$o['items_total'],'Dana pesanan '.$o['order_number'].' ditahan platform']);
                        if ((int)$o['commission']>0) $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'commission','held',?,?)")->execute([(int)$o['shop_id'],(int)$o['id'],-(int)$o['commission'],'Komisi platform 5% '.$o['order_number']]);
                        add_status_log((int)$o['id'],'paid','Pembayaran diterima. Dana ditahan platform.',null);
                        notify_user((int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$o['shop_id'])->fetchColumn(),'order_paid','Pesanan baru dibayar','Pesanan '.$o['order_number'].' sudah dibayar. Kirim dalam 2x24 jam.','order',(int)$o['id']);
                    }
                    $pdo->commit(); flash('Pembayaran sandbox berhasil. Dana ditahan sampai pesanan selesai.','ok'); redirect('success',['id'=>$checkoutId]);
                } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            case 'cancel_order': {
                $u=need_user_for_action(); $id=post_int('order_id'); $o=own_order($id,(int)$u['id'],'buyer');
                if (!$o || !in_array($o['status'],['awaiting_payment','paid'],true)) throw new RuntimeException('Pesanan tidak bisa dibatalkan pada status ini.');
                $pdo->beginTransaction();
                if ($o['status']==='awaiting_payment') {
                    // Pembayaran berlaku untuk seluruh checkout multi-toko, jadi pembatalan pra-bayar membatalkan semua order pada checkout tersebut.
                    $other=$pdo->prepare("SELECT id FROM orders WHERE checkout_id=? AND status='awaiting_payment'"); $other->execute([(int)$o['checkout_id']]); $allOrderIds=array_map('intval',$other->fetchAll(PDO::FETCH_COLUMN));
                    $pdo->prepare("UPDATE checkouts SET status='cancelled' WHERE id=? AND status='awaiting_payment'")->execute([(int)$o['checkout_id']]);
                    $pdo->prepare("UPDATE payments SET status='failed' WHERE checkout_id=? AND status='pending'")->execute([(int)$o['checkout_id']]);
                    foreach ($allOrderIds as $cancelId) {
                        $pdo->prepare("UPDATE orders SET status='cancelled',cancel_reason='buyer_cancelled',cancelled_at=NOW() WHERE id=?")->execute([$cancelId]);
                        $pdo->prepare("UPDATE products p JOIN order_items oi ON oi.product_id=p.id SET p.status='active',p.reserved_until=NULL WHERE oi.order_id=? AND p.status='reserved'")->execute([$cancelId]);
                        add_status_log($cancelId,'cancelled','Checkout dibatalkan oleh pembeli sebelum pembayaran.',(int)$u['id']);
                    }
                } else {
                    $pdo->prepare("UPDATE orders SET status='cancelled',cancel_reason='buyer_cancelled',cancelled_at=NOW() WHERE id=?")->execute([$id]);
                    $pay=$pdo->prepare('SELECT id FROM payments WHERE checkout_id=? LIMIT 1'); $pay->execute([(int)$o['checkout_id']]); $payId=$pay->fetchColumn()?:null;
                    $pdo->prepare("INSERT INTO refunds (order_id,payment_id,type,amount,status,processed_at) VALUES (?,?,'cancelled',?,'processed',NOW())")->execute([$id,$payId,(int)$o['total']]);
                    if ((int)$o['seller_net']>0) $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'refund','held',?,'Refund karena pembatalan pesanan')")->execute([(int)$o['shop_id'],$id,-(int)$o['seller_net']]);
                    $pdo->prepare("UPDATE products p JOIN order_items oi ON oi.product_id=p.id SET p.status='active',p.sold_at=NULL WHERE oi.order_id=?")->execute([$id]);
                    add_status_log($id,'cancelled','Pesanan dibatalkan; refund sandbox diproses.',(int)$u['id']);
                }
                $pdo->commit(); flash('Pesanan dibatalkan. Jika sudah dibayar, refund sandbox tercatat.','ok'); redirect('orders');
            }
            case 'mark_delivered': {
                $u=need_user_for_action(); $id=post_int('order_id'); $o=own_order($id,(int)$u['id'],'buyer');
                if (!$o || $o['status']!=='shipped') throw new RuntimeException('Pesanan belum berstatus dikirim.');
                $pdo->prepare("UPDATE orders SET status='delivered',delivered_at=NOW(),complaint_deadline_at=DATE_ADD(NOW(),INTERVAL 48 HOUR) WHERE id=?")->execute([$id]);
                add_status_log($id,'delivered','Pembeli mengonfirmasi barang telah diterima. Masa komplain 2x24 jam dimulai.',(int)$u['id']);
                $sellerId=(int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$o['shop_id'])->fetchColumn(); notify_user($sellerId,'order_delivered','Pesanan diterima','Pesanan '.$o['order_number'].' telah diterima. Dana akan cair setelah masa komplain berakhir.','order',$id);
                flash('Penerimaan barang tercatat. Periksa barang dan ajukan komplain dalam 2x24 jam jika ada masalah.','ok'); redirect('order',['id'=>$id]);
            }
            case 'complete_order': {
                $u=need_user_for_action(); $id=post_int('order_id'); $o=own_order($id,(int)$u['id'],'buyer');
                if (!$o || $o['status']!=='delivered') throw new RuntimeException('Pesanan belum bisa diselesaikan.');
                $q=$pdo->prepare('SELECT id FROM complaints WHERE order_id=?'); $q->execute([$id]); if ($q->fetch()) throw new RuntimeException('Pesanan memiliki komplain yang belum selesai.');
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE orders SET status='completed',completion_type='buyer_confirmed',completed_at=NOW() WHERE id=?")->execute([$id]);
                if ((int)$o['seller_net']>0) {
                    $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','held',?,'Dana dilepas setelah pembeli menyelesaikan pesanan')")->execute([(int)$o['shop_id'],$id,-(int)$o['seller_net']]);
                    $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','available',?,'Dana dilepas setelah pembeli menyelesaikan pesanan')")->execute([(int)$o['shop_id'],$id,(int)$o['seller_net']]);
                }
                add_status_log($id,'completed','Pembeli menyelesaikan pesanan.',(int)$u['id']); $pdo->commit(); flash('Pesanan selesai dan dana dilepas ke saldo penjual.','ok'); redirect('order',['id'=>$id]);
            }
            case 'submit_review': {
                $u=need_user_for_action(); $id=post_int('order_id'); $rating=post_int('rating',5); $comment=trim((string)postv('comment'));
                $o=own_order($id,(int)$u['id'],'buyer'); if (!$o || $o['status']!=='completed') throw new RuntimeException('Ulasan hanya dapat diberikan pada pesanan yang selesai.');
                if ($rating<1||$rating>5) throw new RuntimeException('Rating harus 1–5.');
                $q=$pdo->prepare('SELECT id FROM reviews WHERE order_id=?'); $q->execute([$id]); if ($q->fetch()) throw new RuntimeException('Pesanan ini sudah diberi ulasan.');
                $pdo->prepare('INSERT INTO reviews (order_id,buyer_id,shop_id,rating,comment) VALUES (?,?,?,?,?)')->execute([$id,(int)$u['id'],(int)$o['shop_id'],$rating,$comment?:null]);
                $pdo->prepare("UPDATE shops s SET rating_avg=(SELECT ROUND(AVG(r.rating),1) FROM reviews r WHERE r.shop_id=s.id),review_count=(SELECT COUNT(*) FROM reviews r WHERE r.shop_id=s.id) WHERE s.id=?")->execute([(int)$o['shop_id']]);
                flash('Terima kasih, ulasan berhasil disimpan.','ok'); redirect('order',['id'=>$id]);
            }
            case 'submit_complaint': {
                $u=need_user_for_action(); $orderId=post_int('order_id'); $o=own_order($orderId,(int)$u['id'],'buyer');
                if (!$o || !in_array($o['status'],['delivered'],true)) throw new RuntimeException('Komplain hanya dapat diajukan setelah barang diterima dan sebelum pesanan selesai.');
                if (!empty($o['complaint_deadline_at']) && strtotime($o['complaint_deadline_at'])<time()) throw new RuntimeException('Batas pengajuan komplain sudah berakhir.');
                $reason=(string)postv('reason'); $allowed=['undisclosed_defect','size_mismatch','color_mismatch','wrong_item','damaged_in_shipping','not_arrived','other'];
                if (!in_array($reason,$allowed,true)) throw new RuntimeException('Pilih alasan komplain yang tersedia.');
                $desc=trim((string)postv('description')); if (mb_strlen($desc)<10) throw new RuntimeException('Jelaskan masalah barang minimal 10 karakter.');
                $q=$pdo->prepare('SELECT id FROM complaints WHERE order_id=?'); $q->execute([$orderId]); if ($q->fetch()) throw new RuntimeException('Satu pesanan hanya bisa memiliki satu komplain.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO complaints (order_id,order_item_id,buyer_id,reason,description,seller_deadline_at,status,decision) VALUES (?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 48 HOUR),'waiting_seller','pending')")
                    ->execute([$orderId,post_int('order_item_id')?:null,(int)$u['id'],$reason,$desc]);
                $complaintId=(int)$pdo->lastInsertId();
                if (isset($_FILES['evidence'])) {
                    $names=$_FILES['evidence']['name'] ?? [];
                    for ($i=0;$i<count($names);$i++) {
                        if (($names[$i]??'')==='') continue;
                        $path=upload_image('evidence','uploads/evidence',$i);
                        if ($path) $pdo->prepare('INSERT INTO complaint_evidence (complaint_id,uploaded_by,side,media_type,path,caption) VALUES (?,?,\'buyer\',\'photo\',?,?)')->execute([$complaintId,(int)$u['id'],$path,trim((string)($_POST['captions'][$i]??''))?:null]);
                    }
                }
                $pdo->prepare("UPDATE orders SET status='complained' WHERE id=?")->execute([$orderId]); add_status_log($orderId,'complained','Pembeli mengajukan komplain.',(int)$u['id']);
                $sellerId=(int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$o['shop_id'])->fetchColumn();
                notify_user($sellerId,'complaint_filed','Komplain baru','Pesanan '.$o['order_number'].' memiliki komplain. Silakan beri tanggapan dalam 2x24 jam.','complaint',$complaintId);
                $pdo->commit(); flash('Komplain dikirim. Penjual memiliki waktu 2x24 jam untuk merespons.','ok'); redirect('order',['id'=>$orderId]);
            }
            case 'seller_save_product': {
                $u=require_seller(); $shop=shop_for_user((int)$u['id']); $productId=post_int('product_id');
                if ($productId) { $pq=$pdo->prepare('SELECT * FROM products WHERE id=? AND shop_id=?'); $pq->execute([$productId,(int)$shop['id']]); $old=$pq->fetch(); if (!$old) throw new RuntimeException('Produk tidak ditemukan atau bukan milik toko Anda.'); }
                $title=trim((string)postv('title')); $desc=trim((string)postv('description')); $categoryId=post_int('category_id'); $brandId=post_int('brand_id')?:null; $price=post_int('price'); $weight=post_int('weight_gram'); $size=trim((string)postv('size_label')); $sizeType=(string)postv('size_type','letter'); $score=post_int('condition_score',8); $gender=(string)postv('gender','unisex');
                if (mb_strlen($title)<3||!$categoryId||$price<1||$weight<1||$size===''||$score<6||$score>10) throw new RuntimeException('Lengkapi judul, kategori, harga, berat, ukuran, dan skor kondisi 6–10.');
                if (!in_array($sizeType,['letter','number','shoe','one_size'],true)||!in_array($gender,['male','female','unisex'],true)) throw new RuntimeException('Jenis ukuran atau gender tidak valid.');
                $status=(string)postv('submit_mode','draft')==='submit'?'pending_review':'draft';
                $root=resolve_root_category_id($categoryId);
                $pdo->beginTransaction();
                if ($productId) {
                    $pdo->prepare("UPDATE products SET category_id=?,brand_id=?,title=?,description=?,material=?,color=?,gender=?,size_type=?,size_label=?,price=?,weight_gram=?,seller_condition_score=?,condition_score=?,status=?,submitted_at=? WHERE id=? AND shop_id=?")
                      ->execute([$categoryId,$brandId,$title,$desc?:null,trim((string)postv('material'))?:null,trim((string)postv('color'))?:null,$gender,$sizeType,$size,$price,$weight,$score,$score,$status,$status==='pending_review'?date('Y-m-d H:i:s'):null,$productId,(int)$shop['id']]);
                    $id=$productId;
                    $pdo->prepare('DELETE FROM product_measurements WHERE product_id=?')->execute([$id]); $pdo->prepare('DELETE FROM product_defects WHERE product_id=?')->execute([$id]);
                } else {
                    $pdo->prepare('INSERT INTO products (shop_id,category_id,brand_id,title,description,material,color,gender,size_type,size_label,price,weight_gram,seller_condition_score,condition_score,status,submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                      ->execute([(int)$shop['id'],$categoryId,$brandId,$title,$desc?:null,trim((string)postv('material'))?:null,trim((string)postv('color'))?:null,$gender,$sizeType,$size,$price,$weight,$score,$score,$status,$status==='pending_review'?date('Y-m-d H:i:s'):null]);
                    $id=(int)$pdo->lastInsertId();
                }
                $types=$pdo->query('SELECT id FROM measurement_types')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($types as $typeId) { $v=trim((string)($_POST['measurements'][$typeId]??'')); if ($v!=='' && is_numeric($v) && (float)$v>0) $pdo->prepare('INSERT INTO product_measurements (product_id,measurement_type_id,value_cm) VALUES (?,?,?)')->execute([$id,(int)$typeId,(float)$v]); }
                $uploadedIds=[]; $photoCount=0;
                $files=$_FILES['photos']['name']??[];
                for ($i=0;$i<count($files);$i++) {
                    if (($files[$i]??'')==='') continue;
                    $path=upload_image('photos','uploads/products',$i); if (!$path) continue;
                    $type=(string)($_POST['photo_types'][$i]??'extra'); if (!in_array($type,['front','back','label','defect','extra'],true)) $type='extra';
                    $ord=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM product_photos WHERE product_id='.(int)$id)->fetchColumn();
                    $pdo->prepare('INSERT INTO product_photos (product_id,path,type,sort_order) VALUES (?,?,?,?)')->execute([$id,$path,$type,$ord]); $uploadedIds[$type][]=(int)$pdo->lastInsertId(); $photoCount++;
                }
                // Cacat ditandai dengan foto close-up pertama yang diunggah pada formulir.
                $defectIds=array_values(array_unique(array_map('intval',(array)($_POST['defect_tags']??[]))));
                foreach ($defectIds as $tagId) {
                    $tagQ=$pdo->prepare('SELECT id FROM defect_tags WHERE id=?'); $tagQ->execute([$tagId]); if (!$tagQ->fetch()) continue;
                    $photoId=$uploadedIds['defect'][0]??null;
                    $pdo->prepare('INSERT INTO product_defects (product_id,defect_tag_id,photo_id,note) VALUES (?,?,?,?)')->execute([$id,$tagId,$photoId,trim((string)($_POST['defect_note']??''))?:null]);
                }
                $existingCount=(int)$pdo->query('SELECT COUNT(*) FROM product_photos WHERE product_id='.(int)$id)->fetchColumn();
                if ($status==='pending_review') {
                    if ($existingCount<4) throw new RuntimeException('Listing harus memiliki minimal 4 foto sebelum dikirim untuk verifikasi. Foto saat ini: '.$existingCount.'.');
                    $labelQ=$pdo->prepare("SELECT id FROM product_photos WHERE product_id=? AND type='label' LIMIT 1"); $labelQ->execute([$id]); if (!$labelQ->fetch()) throw new RuntimeException('Minimal satu foto wajib bertipe label.');
                    $missingDefects=$pdo->prepare('SELECT COUNT(*) FROM product_defects WHERE product_id=? AND photo_id IS NULL'); $missingDefects->execute([$id]); if ((int)$missingDefects->fetchColumn()>0) throw new RuntimeException('Setiap tag cacat harus memiliki foto close-up. Unggah foto bertipe cacat dan kirim ulang.');
                    $required=$pdo->prepare('SELECT mt.name,mt.id FROM category_measurements cm JOIN measurement_types mt ON mt.id=cm.measurement_type_id WHERE cm.category_id=? AND cm.is_required=1'); $required->execute([$root]);
                    foreach ($required->fetchAll() as $req) { $has=$pdo->prepare('SELECT value_cm FROM product_measurements WHERE product_id=? AND measurement_type_id=?'); $has->execute([$id,(int)$req['id']]); if (!$has->fetch()) throw new RuntimeException('Ukuran wajib belum diisi: '.$req['name'].'.'); }
                }
                $pdo->commit(); flash($status==='pending_review'?'Listing dikirim ke antrean verifikasi admin.':'Draft produk berhasil disimpan.','ok'); redirect('seller_products');
            }
            case 'seller_ship_order': {
                $u=require_seller(); $id=post_int('order_id'); $o=own_order($id,(int)$u['id'],'seller');
                if (!$o || !in_array($o['status'],['paid','processing'],true)) throw new RuntimeException('Pesanan tidak bisa diperbarui ke status dikirim.');
                $tracking=trim((string)postv('tracking_number')); if (mb_strlen($tracking)<5) throw new RuntimeException('Nomor resi wajib diisi (minimal 5 karakter).');
                $pdo->prepare("UPDATE orders SET status='shipped',tracking_number=?,shipped_at=NOW(),estimated_arrival_date=DATE_ADD(CURDATE(),INTERVAL 4 DAY) WHERE id=?")->execute([$tracking,$id]);
                add_status_log($id,'shipped','Dikirim melalui '.$o['courier_name'].' dengan resi '.$tracking,(int)$u['id']);
                notify_user((int)$o['buyer_id'],'order_shipped','Pesanan dikirim','Pesanan '.$o['order_number'].' telah dikirim. Resi: '.$tracking,'order',$id);
                flash('Nomor resi tersimpan dan status pesanan diperbarui.','ok'); redirect('seller_orders');
            }
            case 'seller_save_bank': {
                $u=require_seller(); $shop=shop_for_user((int)$u['id']); $bank=trim((string)postv('bank_name')); $number=trim((string)postv('account_number')); $holder=trim((string)postv('account_holder'));
                if (!$bank||!$number||!$holder) throw new RuntimeException('Nama bank, nomor rekening, dan nama pemilik wajib diisi.');
                if (!empty($_POST['is_default'])) $pdo->prepare('UPDATE bank_accounts SET is_default=0 WHERE shop_id=?')->execute([(int)$shop['id']]);
                $pdo->prepare('INSERT INTO bank_accounts (shop_id,bank_name,account_number,account_holder,is_default) VALUES (?,?,?,?,?)')->execute([(int)$shop['id'],$bank,$number,$holder,!empty($_POST['is_default'])?1:0]);
                flash('Rekening tujuan berhasil ditambahkan.','ok'); redirect('seller_wallet');
            }
            case 'seller_withdraw': {
                $u=require_seller(); $shop=shop_for_user((int)$u['id']); $amount=post_int('amount'); $bankId=post_int('bank_account_id');
                $bq=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND shop_id=?'); $bq->execute([$bankId,(int)$shop['id']]); $bank=$bq->fetch(); if (!$bank) throw new RuntimeException('Pilih rekening bank yang terdaftar.');
                $bal=$pdo->prepare('SELECT available_balance FROM v_shop_balances WHERE shop_id=?'); $bal->execute([(int)$shop['id']]); $available=(int)($bal->fetchColumn()?:0);
                if ($amount<10000) throw new RuntimeException('Minimal penarikan Rp10.000.');
                if ($amount>$available) throw new RuntimeException('Saldo tersedia tidak mencukupi.');
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO withdrawals (shop_id,bank_account_id,amount,bank_name,account_number,account_holder,status) VALUES (?,?,?,?,?,?,'requested')")->execute([(int)$shop['id'],$bankId,$amount,$bank['bank_name'],$bank['account_number'],$bank['account_holder']]);
                $wid=(int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO wallet_transactions (shop_id,withdrawal_id,type,bucket,amount,note) VALUES (?,?,'withdrawal','available',?,'Penarikan saldo diajukan')")->execute([(int)$shop['id'],$wid,-$amount]);
                $pdo->commit(); flash('Permintaan penarikan saldo dibuat. Dana akan diproses admin.','ok'); redirect('seller_wallet');
            }
            case 'seller_reply_complaint': {
                $u=require_seller(); $complaintId=post_int('complaint_id'); $stance=(string)postv('seller_stance'); $response=trim((string)postv('seller_response'));
                if (!in_array($stance,['agree','dispute'],true)||mb_strlen($response)<5) throw new RuntimeException('Pilih sikap dan tulis tanggapan penjual.');
                $q=$pdo->prepare('SELECT c.*,o.shop_id,o.order_number,s.user_id AS seller_user_id FROM complaints c JOIN orders o ON o.id=c.order_id JOIN shops s ON s.id=o.shop_id WHERE c.id=? AND s.user_id=?'); $q->execute([$complaintId,(int)$u['id']]); $c=$q->fetch();
                if (!$c || $c['status']==='resolved') throw new RuntimeException('Komplain tidak ditemukan atau sudah selesai.');
                $pdo->prepare("UPDATE complaints SET seller_stance=?,seller_response=?,seller_replied_at=NOW(),status='needs_decision',decision_deadline_at=DATE_ADD(NOW(),INTERVAL 48 HOUR) WHERE id=?")
                    ->execute([$stance,$response,$complaintId]);
                notify_user((int)$c['buyer_id'],'complaint_replied','Penjual menanggapi komplain','Penjual sudah memberi tanggapan untuk pesanan '.$c['order_number'].'.','complaint',$complaintId);
                flash('Tanggapan tersimpan dan komplain diteruskan ke admin.','ok'); redirect('seller_orders');
            }
            case 'admin_decide_complaint': {
                $u=require_admin(); $id=post_int('complaint_id'); $decision=(string)postv('decision'); $note=trim((string)postv('decision_note')); $amount=post_int('refund_amount');
                if (!in_array($decision,['full_refund','partial_refund','rejected'],true)) throw new RuntimeException('Keputusan komplain tidak valid.');
                $q=$pdo->prepare('SELECT c.id AS complaint_id,c.order_id,c.buyer_id,c.status AS complaint_status,o.id AS actual_order_id FROM complaints c JOIN orders o ON o.id=c.order_id WHERE c.id=?'); $q->execute([$id]); $row=$q->fetch(); if (!$row || $row['complaint_status']==='resolved') throw new RuntimeException('Komplain tidak ditemukan atau telah diputuskan.');
                $o=$pdo->prepare('SELECT * FROM orders WHERE id=?'); $o->execute([(int)$row['actual_order_id']]); $order=$o->fetch();
                $refund=0; $payout=0;
                if ($decision==='full_refund') { $refund=(int)$order['total']; $payout=0; }
                elseif ($decision==='partial_refund') { if ($amount<1 || $amount>(int)$order['seller_net']) throw new RuntimeException('Refund sebagian harus lebih dari 0 dan tidak melebihi nilai bersih penjual ('.money($order['seller_net']).').'); $refund=$amount; $payout=(int)$order['seller_net']-$refund; }
                else { $refund=0; $payout=(int)$order['seller_net']; }
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE complaints SET status='resolved',decision=?,refund_amount=?,seller_payout=?,decision_note=?,decided_by=?,resolved_at=NOW() WHERE id=?")
                    ->execute([$decision,$refund,$payout,$note?:null,(int)$u['id'],$id]);
                if ($decision==='full_refund') {
                    $pdo->prepare("UPDATE orders SET status='refunded' WHERE id=?")->execute([(int)$order['id']]);
                    $pay=$pdo->prepare('SELECT id FROM payments WHERE checkout_id=? LIMIT 1'); $pay->execute([(int)$order['checkout_id']]); $pid=$pay->fetchColumn()?:null;
                    $pdo->prepare("INSERT INTO refunds (order_id,payment_id,complaint_id,type,amount,status,processed_at) VALUES (?,?,?,'full',?,'processed',NOW())")->execute([(int)$order['id'],$pid,$id,$refund]);
                    if ((int)$order['seller_net']>0) $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'refund','held',?,'Refund penuh diputuskan admin')")->execute([(int)$order['shop_id'],(int)$order['id'],-(int)$order['seller_net']]);
                } elseif ($decision==='partial_refund') {
                    $pdo->prepare("UPDATE orders SET status='completed',completion_type='buyer_confirmed',completed_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
                    $pay=$pdo->prepare('SELECT id FROM payments WHERE checkout_id=? LIMIT 1'); $pay->execute([(int)$order['checkout_id']]); $pid=$pay->fetchColumn()?:null;
                    $pdo->prepare("INSERT INTO refunds (order_id,payment_id,complaint_id,type,amount,status,processed_at) VALUES (?,?,?,'partial',?,'processed',NOW())")->execute([(int)$order['id'],$pid,$id,$refund]);
                    if ($refund>0) $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'refund','held',?,'Refund sebagian diputuskan admin')")->execute([(int)$order['shop_id'],(int)$order['id'],-$refund]);
                    if ($payout>0) {
                        $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','held',?,'Sisa dana dirilis setelah refund sebagian')")->execute([(int)$order['shop_id'],(int)$order['id'],-$payout]);
                        $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','available',?,'Sisa dana dirilis setelah refund sebagian')")->execute([(int)$order['shop_id'],(int)$order['id'],$payout]);
                    }
                } else {
                    $pdo->prepare("UPDATE orders SET status='completed',completion_type='buyer_confirmed',completed_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
                    if ($payout>0) {
                        $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','held',?,'Dana dirilis setelah komplain ditolak')")->execute([(int)$order['shop_id'],(int)$order['id'],-$payout]);
                        $pdo->prepare("INSERT INTO wallet_transactions (shop_id,order_id,type,bucket,amount,note) VALUES (?,?,'release','available',?,'Dana dirilis setelah komplain ditolak')")->execute([(int)$order['shop_id'],(int)$order['id'],$payout]);
                    }
                }
                add_status_log((int)$order['id'],$decision==='full_refund'?'refunded':'completed','Keputusan admin atas komplain: '.status_label($decision).($note?' — '.$note:''),(int)$u['id']);
                notify_user((int)$row['buyer_id'],'complaint_decided','Keputusan komplain tersedia','Admin memutuskan komplain untuk pesanan '.$order['order_number'].': '.status_label($decision).'.','complaint',$id);
                $sellerId=(int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$order['shop_id'])->fetchColumn(); notify_user($sellerId,'complaint_decided','Keputusan komplain tersedia','Admin memutuskan komplain untuk pesanan '.$order['order_number'].': '.status_label($decision).'.','complaint',$id);
                $pdo->commit(); flash('Keputusan komplain disimpan.','ok'); redirect('admin_complaints');
            }
            case 'admin_verify_product': {
                $u=require_admin(); $id=post_int('product_id'); $decision=(string)postv('decision'); $note=trim((string)postv('note')); $score=post_int('adjusted_score',0); $reasonId=post_int('rejection_reason_id')?:null;
                if (!in_array($decision,['approved','rejected'],true)) throw new RuntimeException('Keputusan verifikasi tidak valid.');
                $q=$pdo->prepare("SELECT p.*,s.user_id AS seller_id FROM products p JOIN shops s ON s.id=p.shop_id WHERE p.id=? AND p.status='pending_review'"); $q->execute([$id]); $p=$q->fetch(); if (!$p) throw new RuntimeException('Listing tidak ada di antrean verifikasi.');
                if ($decision==='approved' && ($score<6||$score>10)) throw new RuntimeException('Skor kondisi admin harus antara 6–10.');
                if ($decision==='rejected' && !$reasonId) throw new RuntimeException('Pilih alasan penolakan.');
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO verifications (product_id,admin_id,decision,adjusted_score,rejection_reason_id,note) VALUES (?,?,?,?,?,?)')->execute([$id,(int)$u['id'],$decision,$decision==='approved'&&$score!==(int)$p['seller_condition_score']?$score:null,$decision==='rejected'?$reasonId:null,$note?:null]);
                $verificationId=(int)$pdo->lastInsertId();
                $rules=$pdo->query('SELECT id FROM verification_rules WHERE is_active=1 ORDER BY sort_order')->fetchAll(PDO::FETCH_COLUMN);
                $photoQ=$pdo->prepare('SELECT COUNT(*) FROM product_photos WHERE product_id=?'); $photoQ->execute([$id]); $photoCount=(int)$photoQ->fetchColumn();
                $labelQ=$pdo->prepare("SELECT COUNT(*) FROM product_photos WHERE product_id=? AND type='label'"); $labelQ->execute([$id]); $hasLabel=(int)$labelQ->fetchColumn()>0;
                $defQ=$pdo->prepare('SELECT COUNT(*) FROM product_defects WHERE product_id=? AND photo_id IS NOT NULL'); $defQ->execute([$id]); $defectPhotoCount=(int)$defQ->fetchColumn();
                $totalDefQ=$pdo->prepare('SELECT COUNT(*) FROM product_defects WHERE product_id=?'); $totalDefQ->execute([$id]); $totalDefectCount=(int)$totalDefQ->fetchColumn();
                $measureQ=$pdo->prepare('SELECT COUNT(*) FROM product_measurements WHERE product_id=?'); $measureQ->execute([$id]); $measureCount=(int)$measureQ->fetchColumn();
                $checks=[1=>$photoCount>=4,2=>$hasLabel,3=>$defectPhotoCount===$totalDefectCount,4=>$measureCount>0,5=>true,6=>!empty($_POST['not_prohibited']),7=>!empty($_POST['price_reasonable'])];
                foreach ($rules as $ruleId) {
                    $ruleId=(int)$ruleId;
                    // Syarat objektif (foto, label, tag cacat, ukuran) tidak boleh dilewati hanya dengan mencentang kotak.
                    if (in_array($ruleId,[1,2,3,4],true)) $passed=!empty($checks[$ruleId]) ? 1 : 0;
                    else $passed=isset($_POST['checks'][$ruleId]) ? 1 : (!empty($checks[$ruleId]) ? 1 : 0);
                    if ($decision==='approved' && $passed!==1) throw new RuntimeException('Listing belum lolos seluruh checklist. Perbaiki data yang belum terpenuhi, lalu kirim ulang atau tolak dengan alasan.');
                    $pdo->prepare('INSERT INTO verification_checks (verification_id,rule_id,is_passed,note) VALUES (?,?,?,?)')->execute([$verificationId,$ruleId,$passed,trim((string)($_POST['check_notes'][$ruleId]??''))?:null]);
                }
                if ($decision==='approved') $pdo->prepare("UPDATE products SET status='active',condition_score=?,published_at=NOW(),reserved_until=NULL WHERE id=?")->execute([$score,$id]);
                else $pdo->prepare("UPDATE products SET status='rejected',reserved_until=NULL WHERE id=?")->execute([$id]);
                notify_user((int)$p['seller_id'],$decision==='approved'?'listing_approved':'listing_rejected',$decision==='approved'?'Listing disetujui':'Listing ditolak',$decision==='approved'?$p['title'].' telah tayang di katalog.':$p['title'].' ditolak. '.($note?:'Buka halaman barang untuk memperbaiki listing.'),'product',$id);
                $pdo->commit(); flash($decision==='approved'?'Listing disetujui dan ditayangkan.':'Listing ditolak dan catatan dikirim ke penjual.','ok'); redirect('admin_verification');
            }
            case 'admin_process_withdrawal': {
                $u=require_admin(); $id=post_int('withdrawal_id'); $decision=(string)postv('decision'); $reason=trim((string)postv('reject_reason'));
                if (!in_array($decision,['processing','completed','rejected'],true)) throw new RuntimeException('Status penarikan tidak valid.');
                $pdo->beginTransaction();
                $q=$pdo->prepare('SELECT * FROM withdrawals WHERE id=? FOR UPDATE'); $q->execute([$id]); $w=$q->fetch();
                if (!$w || !in_array($w['status'],['requested','processing'],true)) throw new RuntimeException('Permintaan penarikan tidak ditemukan atau sudah selesai.');
                if ($decision==='rejected' && $reason==='') throw new RuntimeException('Alasan penolakan penarikan wajib diisi.');
                if ($decision==='rejected') {
                    $pdo->prepare("UPDATE withdrawals SET status='rejected',reject_reason=?,processed_at=NOW() WHERE id=?")->execute([$reason,$id]);
                    $pdo->prepare("INSERT INTO wallet_transactions (shop_id,withdrawal_id,type,bucket,amount,note) VALUES (?,?,'withdrawal_reversal','available',?,'Saldo dikembalikan setelah penarikan ditolak: ')")->execute([(int)$w['shop_id'],$id,(int)$w['amount']]);
                    notify_user((int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$w['shop_id'])->fetchColumn(),'withdrawal_processed','Penarikan ditolak','Penarikan '.money($w['amount']).' ditolak. Alasan: '.$reason,'withdrawal',$id);
                } else {
                    $pdo->prepare('UPDATE withdrawals SET status=?,processed_at=? WHERE id=?')->execute([$decision,$decision==='completed'?date('Y-m-d H:i:s'):null,$id]);
                    if ($decision==='completed') notify_user((int)$pdo->query('SELECT user_id FROM shops WHERE id='.(int)$w['shop_id'])->fetchColumn(),'withdrawal_processed','Penarikan selesai','Penarikan '.money($w['amount']).' ditandai selesai oleh admin.','withdrawal',$id);
                }
                $pdo->commit(); flash($decision==='rejected'?'Penarikan ditolak dan saldo dikembalikan.':'Status penarikan diperbarui.','ok'); redirect('admin_withdrawals');
            }
            case 'admin_toggle_user': {
                $u=require_admin(); $id=post_int('user_id'); $mode=(string)postv('mode');
                $q=$pdo->prepare('SELECT * FROM users WHERE id=?'); $q->execute([$id]); $target=$q->fetch(); if (!$target||$target['role']==='admin'||$id===(int)$u['id']) throw new RuntimeException('Akun ini tidak dapat diubah statusnya.');
                if ($mode==='block') { $reason=trim((string)postv('block_reason')); if (!$reason) throw new RuntimeException('Alasan blokir wajib diisi.'); $pdo->prepare("UPDATE users SET status='blocked',block_reason=?,blocked_at=NOW(),blocked_by=? WHERE id=?")->execute([$reason,(int)$u['id'],$id]); flash('Akun berhasil diblokir.','ok'); }
                else { $pdo->prepare("UPDATE users SET status='active',block_reason=NULL,blocked_at=NULL,blocked_by=NULL WHERE id=?")->execute([$id]); flash('Akun berhasil dibuka blokirnya.','ok'); }
                redirect('admin_users');
            }
            case 'admin_add_category': {
                require_admin(); $name=trim((string)postv('name')); $parent=post_int('parent_id')?:null;
                if (mb_strlen($name)<2) throw new RuntimeException('Nama kategori minimal 2 karakter.');
                $slug=slugify($name); $base=$slug; $n=2; $q=$pdo->prepare('SELECT id FROM categories WHERE slug=?'); while(true){$q->execute([$slug]);if(!$q->fetch())break;$slug=$base.'-'.$n++;}
                $sort=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM categories'.($parent?' WHERE parent_id='.(int)$parent:' WHERE parent_id IS NULL'))->fetchColumn();
                $pdo->prepare('INSERT INTO categories (parent_id,name,slug,sort_order,is_active,is_new) VALUES (?,?,?,?,1,1)')->execute([$parent,$name,$slug,$sort]); flash('Kategori berhasil ditambahkan.','ok'); redirect('admin_categories');
            }
            case 'admin_add_prohibited': {
                require_admin(); $name=trim((string)postv('name')); if (mb_strlen($name)<3) throw new RuntimeException('Nama barang terlarang terlalu pendek.');
                $q=$pdo->prepare('SELECT id FROM prohibited_items WHERE name=?'); $q->execute([$name]); if (!$q->fetch()) { $sort=(int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM prohibited_items')->fetchColumn(); $pdo->prepare('INSERT INTO prohibited_items (name,sort_order) VALUES (?,?)')->execute([$name,$sort]); }
                flash('Daftar barang terlarang diperbarui.','ok'); redirect('admin_categories');
            }
            case 'newsletter': {
                $email=strtolower(trim((string)postv('email'))); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Masukkan alamat email yang valid.'); $u=user();
                $pdo->prepare('INSERT INTO newsletter_subscribers (email,user_id) VALUES (?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),unsubscribed_at=NULL')->execute([$email,$u ? (int)$u['id'] : null]);
                flash('Terima kasih sudah berlangganan kabar ThriftApp.','ok'); redirect('home');
            }
            default:
                throw new RuntimeException('Aksi formulir tidak dikenali.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $debug=(bool)($GLOBALS['config']['debug']??false);
        $msg=$debug ? $e->getMessage() : 'Permintaan belum dapat diproses. Periksa data lalu coba lagi.';
        error_log('ThriftApp action '.$action.': '.$e->getMessage());
        flash($msg, 'bad');
        $back=(string)postv('back_route', $_GET['r'] ?? 'home');
        $params=[];
        if (post_int('product_id')) $params['id']=post_int('product_id');
        elseif (post_int('order_id')) $params['id']=post_int('order_id');
        elseif (post_int('checkout_id')) $params['id']=post_int('checkout_id');
        redirect($back,$params);
    }
}
