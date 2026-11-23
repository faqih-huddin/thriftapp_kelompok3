<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function api_json(int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_ok(array $data, int $status = 200, array $meta = []): never {
    api_json($status, ['success' => true, 'data' => $data, 'meta' => $meta]);
}

function api_fail(int $status, string $code, string $message, array $details = []): never {
    api_json($status, ['success' => false, 'error' => array_merge(['code' => $code, 'message' => $message], $details)]);
}

function api_base_path(): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
    $apiDirectory = rtrim(dirname($script), '/');
    $base = rtrim(dirname($apiDirectory), '/');
    return $base === '.' ? '' : $base;
}

function api_image_url(?string $path): string {
    $relative = product_photo_url($path);
    if (preg_match('~^https?://~i', $relative)) return $relative;
    return api_base_path() . '/' . ltrim($relative, '/');
}

function api_user(): array {
    $current = user();
    if (!$current) api_fail(401, 'AUTH_REQUIRED', 'Silakan masuk ke akun ThriftApp terlebih dahulu.');
    return $current;
}

function api_check_csrf(): void {
    $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        api_fail(419, 'CSRF_INVALID', 'Token keamanan tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.');
    }
}

function api_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return $_POST;
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) api_fail(400, 'INVALID_JSON', 'Body harus berupa JSON object yang valid.');
        return $decoded;
    }
    return $_POST;
}

function api_products(): never {
    $q = trim((string)($_GET['q'] ?? ''));
    $q = mb_substr_safe($q, 0, 100);
    $categoryId = max(0, (int)($_GET['category_id'] ?? 0));
    $minPrice = max(0, (int)($_GET['min_price'] ?? 0));
    $maxPrice = max(0, (int)($_GET['max_price'] ?? 0));
    $minCondition = max(0, min(10, (int)($_GET['min_condition'] ?? 0)));
    $size = trim((string)($_GET['size'] ?? ''));
    $sort = (string)($_GET['sort'] ?? 'newest');
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(60, (int)($_GET['per_page'] ?? 12)));

    $from = " FROM products p
        JOIN categories c ON c.id = p.category_id
        LEFT JOIN categories pc ON pc.id = c.parent_id
        LEFT JOIN brands b ON b.id = p.brand_id
        JOIN shops s ON s.id = p.shop_id
        WHERE p.status IN ('active','reserved','sold') AND s.is_active = 1";
    $where = '';
    $params = [];

    if ($q !== '') {
        $where .= " AND (p.title LIKE ? OR p.description LIKE ? OR b.name LIKE ? OR s.name LIKE ?)";
        $needle = '%' . $q . '%';
        array_push($params, $needle, $needle, $needle, $needle);
    }
    if ($categoryId > 0) {
        $where .= ' AND (c.id = ? OR c.parent_id = ?)';
        array_push($params, $categoryId, $categoryId);
    }
    if ($minPrice > 0) { $where .= ' AND p.price >= ?'; $params[] = $minPrice; }
    if ($maxPrice > 0) { $where .= ' AND p.price <= ?'; $params[] = $maxPrice; }
    if ($minCondition > 0) { $where .= ' AND p.condition_score >= ?'; $params[] = $minCondition; }
    if ($size !== '') { $where .= ' AND p.size_label = ?'; $params[] = $size; }

    $sortSql = match ($sort) {
        'price_low' => ' ORDER BY p.price ASC, p.published_at DESC, p.id DESC',
        'price_high' => ' ORDER BY p.price DESC, p.published_at DESC, p.id DESC',
        'condition' => ' ORDER BY p.condition_score DESC, p.published_at DESC, p.id DESC',
        default => " ORDER BY FIELD(p.status,'active','reserved','sold'), p.published_at DESC, p.created_at DESC, p.id DESC",
    };

    $count = db()->prepare('SELECT COUNT(*)' . $from . $where);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $lastPage = max(1, (int)ceil($total / $perPage));
    if ($page > $lastPage) $page = $lastPage;
    $offset = ($page - 1) * $perPage;

    $sql = "SELECT p.id, p.title, p.description, p.price, p.size_label, p.size_type,
                   p.color, p.gender, p.condition_score, p.status, p.published_at,
                   c.id AS category_id, c.name AS category_name, pc.name AS parent_category_name,
                   b.name AS brand_name, s.id AS shop_id, s.name AS shop_name, s.city AS shop_city,
                   (SELECT COUNT(*) FROM product_defects d WHERE d.product_id = p.id) AS defect_count,
                   (SELECT ph.path FROM product_photos ph WHERE ph.product_id = p.id ORDER BY ph.sort_order, ph.id LIMIT 1) AS main_photo"
        . $from . $where . $sortSql . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'description' => $row['description'],
            'price' => (int)$row['price'],
            'size_label' => $row['size_label'],
            'size_type' => $row['size_type'],
            'color' => $row['color'],
            'gender' => $row['gender'],
            'condition_score' => (int)$row['condition_score'],
            'status' => $row['status'],
            'category_id' => (int)$row['category_id'],
            'category_name' => $row['category_name'],
            'parent_category_name' => $row['parent_category_name'],
            'brand_name' => $row['brand_name'],
            'shop_id' => (int)$row['shop_id'],
            'shop_name' => $row['shop_name'],
            'shop_city' => $row['shop_city'],
            'defect_count' => (int)$row['defect_count'],
            'image_url' => api_image_url($row['main_photo']),
            'product_url' => api_base_path() . '/index.php?r=product&id=' . (int)$row['id'],
        ];
    }

    api_ok(['items' => $items], 200, [
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        'filters' => ['q' => $q, 'category_id' => $categoryId, 'min_price' => $minPrice, 'max_price' => $maxPrice, 'min_condition' => $minCondition, 'size' => $size, 'sort' => $sort],
    ]);
}

function api_cart(): never {
    $u = api_user();
    $stmt = db()->prepare("SELECT ci.id AS cart_item_id, p.id AS product_id, p.title, p.price, p.size_label,
                                  p.status, s.name AS shop_name, s.city AS shop_city,
                                  (SELECT ph.path FROM product_photos ph WHERE ph.product_id = p.id ORDER BY ph.sort_order, ph.id LIMIT 1) AS main_photo
                           FROM cart_items ci
                           JOIN products p ON p.id = ci.product_id
                           JOIN shops s ON s.id = p.shop_id
                           WHERE ci.user_id = ?
                           ORDER BY s.name, ci.created_at DESC");
    $stmt->execute([(int)$u['id']]);
    $rows = $stmt->fetchAll();
    $items = [];
    $subtotal = 0;
    foreach ($rows as $row) {
        $price = (int)$row['price'];
        if ($row['status'] === 'active') $subtotal += $price;
        $items[] = [
            'cart_item_id' => (int)$row['cart_item_id'],
            'product_id' => (int)$row['product_id'],
            'title' => $row['title'],
            'price' => $price,
            'size_label' => $row['size_label'],
            'status' => $row['status'],
            'shop_name' => $row['shop_name'],
            'shop_city' => $row['shop_city'],
            'image_url' => api_image_url($row['main_photo']),
        ];
    }
    api_ok(['items' => $items, 'item_count' => count($items), 'active_subtotal' => $subtotal]);
}

function mb_substr_safe(string $value, int $start, int $length): string {
    return function_exists('mb_substr') ? mb_substr($value, $start, $length, 'UTF-8') : substr($value, $start, $length);
}

try {
    if (!$GLOBALS['pdo']) api_fail(503, 'DATABASE_UNAVAILABLE', 'Database belum tersambung. Periksa config/config.php dan status MySQL.');

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'));
    $apiDirectory = rtrim(dirname($script), '/');
    $requestPath = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $routePath = str_starts_with($requestPath, $apiDirectory)
        ? substr($requestPath, strlen($apiDirectory))
        : (string)($_GET['path'] ?? '/');
    $routePath = '/' . trim($routePath, '/');
    if ($routePath === '/index.php') $routePath = '/' . trim((string)($_GET['path'] ?? ''), '/');
    if ($routePath === '/') $routePath = '/';

    if ($method === 'GET' && $routePath === '/session') {
        $u = user();
        api_ok([
            'authenticated' => (bool)$u,
            'user' => $u ? ['id' => (int)$u['id'], 'name' => $u['name'], 'role' => $u['role'], 'is_seller' => (bool)$u['is_seller']] : null,
            'csrf_token' => csrf_token(),
        ]);
    }

    if ($method === 'GET' && $routePath === '/products') api_products();
    if ($method === 'GET' && $routePath === '/cart') api_cart();

    if ($method === 'POST' && $routePath === '/cart/items') {
        $u = api_user();
        api_check_csrf();
        $body = api_body();
        $productId = filter_var($body['product_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$productId || $productId < 1) api_fail(422, 'INVALID_PRODUCT_ID', 'product_id wajib berupa bilangan bulat positif.');

        $check = db()->prepare('SELECT p.id, p.status, s.is_active, s.user_id AS seller_user_id FROM products p JOIN shops s ON s.id = p.shop_id WHERE p.id = ? LIMIT 1');
        $check->execute([(int)$productId]);
        $product = $check->fetch();
        if (!$product || (int)$product['is_active'] !== 1) api_fail(404, 'PRODUCT_NOT_FOUND', 'Produk tidak ditemukan atau toko sedang tidak aktif.');
        if ($product['status'] !== 'active') api_fail(409, 'PRODUCT_UNAVAILABLE', 'Produk sudah dipesan, terjual, atau belum tersedia.');
        if ((int)$product['seller_user_id'] === (int)$u['id']) api_fail(403, 'OWN_PRODUCT', 'Kamu tidak dapat memasukkan barang dari tokomu sendiri ke keranjang.');

        $insert = db()->prepare('INSERT IGNORE INTO cart_items (user_id, product_id) VALUES (?, ?)');
        $insert->execute([(int)$u['id'], (int)$productId]);
        $added = $insert->rowCount() === 1;
        $count = db()->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?');
        $count->execute([(int)$u['id']]);
        api_ok(['product_id' => (int)$productId, 'added' => $added, 'item_count' => (int)$count->fetchColumn(), 'message' => $added ? 'Produk berhasil ditambahkan ke keranjang.' : 'Produk sudah ada di keranjang.'], $added ? 201 : 200);
    }

    if ($method === 'DELETE' && preg_match('~^/cart/items/(\\d+)$~', $routePath, $match)) {
        $u = api_user();
        api_check_csrf();
        $cartItemId = (int)$match[1];
        $delete = db()->prepare('DELETE FROM cart_items WHERE id = ? AND user_id = ?');
        $delete->execute([$cartItemId, (int)$u['id']]);
        if ($delete->rowCount() < 1) api_fail(404, 'CART_ITEM_NOT_FOUND', 'Item keranjang tidak ditemukan.');
        $count = db()->prepare('SELECT COUNT(*) FROM cart_items WHERE user_id = ?');
        $count->execute([(int)$u['id']]);
        api_ok(['cart_item_id' => $cartItemId, 'item_count' => (int)$count->fetchColumn(), 'message' => 'Item berhasil dihapus dari keranjang.']);
    }

    api_fail(404, 'ENDPOINT_NOT_FOUND', 'Endpoint API tidak ditemukan.', ['method' => $method, 'path' => $routePath]);
} catch (Throwable $e) {
    error_log('ThriftApp API error: ' . $e->getMessage());
    $debug = (bool)($config['debug'] ?? false);
    api_fail(500, 'INTERNAL_ERROR', 'Terjadi kesalahan pada server API.' . ($debug ? ' ' . $e->getMessage() : ''));
}
