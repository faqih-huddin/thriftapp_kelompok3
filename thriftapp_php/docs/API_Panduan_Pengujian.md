# ThriftApp — Panduan Backend API dan Frontend Katalog

Dokumen ini menjelaskan implementasi milestone backend dan frontend pada proyek PHP native ThriftApp.

## 1. Komponen yang ditambahkan

- `api/index.php`: router REST-style yang mengembalikan JSON.
- `api/.htaccess`: meneruskan URL `/api/...` ke router API.
- `assets/catalog-api.js`: mengambil data katalog dari API, menampilkan kartu, melakukan pencarian/filter, dan mengirim produk ke keranjang.
- `assets/cart-api.js`: menghapus item keranjang lewat endpoint DELETE dengan fallback ke form PHP jika API tidak tersedia.
- `assets/app.css`: gaya tambahan untuk tombol, pagination, dan notifikasi API.
- `docs/ThriftApp_API.postman_collection.json`: koleksi request untuk pengujian manual.

## 2. Endpoint

Base URL pada instalasi XAMPP default:

`http://localhost/thriftapp_php/api`

| Method | Endpoint | Login | Fungsi |
|---|---|---:|---|
| GET | `/session` | Tidak | Status login dan token CSRF untuk request perubahan data |
| GET | `/products` | Tidak | Daftar produk katalog dari MySQL |
| GET | `/cart` | Ya | Membaca item keranjang milik akun aktif |
| POST | `/cart/items` | Ya | Menambahkan produk ke keranjang |
| DELETE | `/cart/items/{cart_item_id}` | Ya | Menghapus item keranjang milik akun aktif |

### Filter katalog

`GET /products` mendukung query parameter:

- `q`: kata pencarian pada judul, deskripsi, merek, atau nama toko.
- `category_id`: ID kategori utama atau subkategori.
- `min_price` dan `max_price`: batas harga rupiah.
- `min_condition`: skor kondisi minimum.
- `size`: ukuran label, misalnya M atau 41.
- `sort`: `newest`, `price_low`, `price_high`, atau `condition`.
- `page` dan `per_page`: pagination. Nilai `per_page` dibatasi maksimal 60.

Contoh:

`GET /api/products?q=denim&min_price=50000&max_price=200000&sort=price_low&page=1&per_page=12`

### Contoh response GET /products

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": 1,
        "title": "Westbay Denim Jacket",
        "price": 140000,
        "size_label": "M",
        "condition_score": 7,
        "status": "active",
        "shop_name": "Toko Dimas Thrift",
        "image_url": "/thriftapp_php/assets/img/product-01.jpg",
        "product_url": "/thriftapp_php/index.php?r=product&id=1"
      }
    ]
  },
  "meta": {
    "pagination": {"page": 1, "per_page": 12, "total": 1, "last_page": 1}
  }
}
```

## 3. Keamanan API

- Endpoint perubahan data membutuhkan login menggunakan session PHP yang sama dengan aplikasi web.
- `POST` dan `DELETE` memerlukan token CSRF pada header `X-CSRF-Token`.
- Token diambil dari `GET /api/session` setelah login.
- Penambahan keranjang hanya menerima produk berstatus `active` dan toko aktif.
- Pengguna tidak dapat menambahkan produk dari tokonya sendiri.
- `cart_items` mempunyai unique key `(user_id, product_id)`, sehingga produk yang sama tidak akan diduplikasi pada keranjang akun yang sama.
- Penghapusan memeriksa `cart_item_id` dan `user_id` bersamaan. Pengguna tidak bisa menghapus item milik akun lain.
- SQL menggunakan PDO prepared statements.

## 4. Menjalankan di XAMPP

1. Ekstrak folder `thriftapp_php` ke `C:\xampp\htdocs\thriftapp_php`.
2. Jalankan Apache dan MySQL.
3. Import `thriftapp_database.sql` lewat phpMyAdmin. Database yang dibuat bernama `thriftapp`.
4. Pastikan `config/config.php` sesuai koneksi MySQL lokal. Jika file belum ada, salin `config/config.example.php` menjadi `config/config.php`.
5. Buka `http://localhost/thriftapp_php/`.
6. Buka menu Katalog. Produk harus muncul setelah halaman meminta data dari `/api/products`.

Akun demo pembeli:

- Email: `rani@contoh.id`
- Password: `password`

## 5. Uji cepat lewat browser

Buka URL berikut:

- `http://localhost/thriftapp_php/api/products`
- `http://localhost/thriftapp_php/api/products?q=denim`
- `http://localhost/thriftapp_php/api/session`

Endpoint `/api/cart` memerlukan login. Jika dibuka tanpa login, API mengembalikan status `401` dengan kode `AUTH_REQUIRED`.

## 6. Uji lewat Postman

1. Import file `docs/ThriftApp_API.postman_collection.json`.
2. Sesuaikan variable `base_url` jika nama folder proyek berbeda.
3. Jalankan request **1. Buka Form Login (ambil CSRF)**.
4. Jalankan **2. Login Pembeli Demo**. Postman perlu menyimpan cookie session dari respons login.
5. Jalankan **3. Ambil Produk**.
6. Jalankan **4. Ambil Session & CSRF**.
7. Jalankan **5. Tambahkan Produk ke Keranjang**.
8. Jalankan **6. Lihat Keranjang**.
9. Jalankan **7. Hapus Item Keranjang**.

Jika Postman tidak membawa cookie antar-request, pastikan cookie jar untuk `localhost` aktif. Token CSRF tidak menggantikan cookie session; keduanya dibutuhkan.

## 7. Kriteria berhasil milestone

- `GET /api/products` mengembalikan JSON dengan produk yang ada pada tabel `products`.
- Katalog web tidak lagi mengandalkan kartu produk yang di-render PHP; kartu diisi menggunakan `fetch()` dari endpoint API.
- Filter pencarian, kategori, harga, kondisi, ukuran, dan pengurutan dikirim sebagai query parameter API.
- Setelah login, tombol **Tambah ke keranjang** memanggil `POST /api/cart/items`.
- Halaman keranjang dapat menghapus item menggunakan `DELETE /api/cart/items/{cart_item_id}`.
- Seluruh perubahan data tetap dibatasi oleh session, CSRF, dan kepemilikan item.

## 8. Catatan pengembangan

- Endpoint tambah dan hapus keranjang menggunakan rancangan REST-style yang diusulkan untuk memenuhi kebutuhan milestone. Jika dosen/tim menentukan format URL berbeda, route dapat disesuaikan tanpa mengubah skema database.
- Endpoint belum menerapkan token API publik, CORS lintas domain, rate limit, atau autentikasi mobile. Untuk proyek lokal dengan frontend dan backend pada origin yang sama, session cookie dan CSRF digunakan.
- Tes runtime terhadap MySQL harus dilakukan pada komputer yang menjalankan XAMPP karena lingkungan pengembangan ini tidak menyediakan ekstensi `pdo_mysql` dan layanan MySQL aktif.

### Jika URL tanpa `index.php` belum berjalan

Pastikan modul `mod_rewrite` Apache aktif dan file `.htaccess` di folder `api` diizinkan oleh konfigurasi Apache (`AllowOverride All`). Untuk tes sementara tanpa URL rewrite, gunakan:

- `GET http://localhost/thriftapp_php/api/index.php?path=/products`
- `GET http://localhost/thriftapp_php/api/index.php?path=/session`

URL cantik `/api/products` tetap menjadi URL yang dipakai frontend setelah rewrite aktif.
