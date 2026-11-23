# ThriftApp PHP + MySQL

## Akun contoh

Semua akun contoh pada SQL memakai kata sandi `password`.

| Peran | Email |
| --- | --- |
| Admin | `admin@contoh.id` |
| Pembeli | `rani@contoh.id` |
| Penjual | `dimas@contoh.id` |
| Penjual | `sari@contoh.id` |
| Penjual | `bayu@contoh.id` |

Data akun, nomor telepon, alamat, nomor rekening, pesanan, dan bukti dalam database ini adalah data contoh/fiktif.

## Fitur yang tersedia

### Pembeli
- Beranda, katalog, pencarian dan filter berdasarkan kategori, harga, ukuran, dan kondisi.
- Detail barang, foto, ukuran asli, tag cacat, toko, favorit, dan keranjang.
- Registrasi, login, logout, alamat pengiriman, dan aktivasi mode penjual.
- Checkout multi-toko, pemilihan alamat/kurir/metode bayar, checkout berjangka 30 menit, dan **pembayaran sandbox**.
- Daftar pesanan, riwayat status, resi, konfirmasi barang diterima, komplain berbukti, dan ulasan.

### Penjual
- Dashboard listing dan pesanan; membuat draft/listing untuk diverifikasi; unggah foto; ukuran asli; dan tag cacat.
- Menyimpan nomor resi, menanggapi komplain, mengatur rekening bank, melihat saldo/mutasi, dan meminta penarikan.

### Admin
- Dashboard, antrean verifikasi listing, setujui/tolak dengan alasan dan koreksi skor.
- Penanganan komplain dan keputusan refund penuh/sebagian/ditolak.
- Kelola pengguna/blokir, kategori, skala kondisi, daftar barang terlarang, dan permintaan penarikan penjual.

### Aturan otomatis
- Checkout kedaluwarsa setelah 30 menit.
- Pesanan yang dibayar tetapi belum dikirim dalam 48 jam ditandai refund otomatis.
- Pesanan yang sudah diterima otomatis diselesaikan setelah jendela komplain 48 jam jika tidak ada komplain.

Pemeriksaan aturan otomatis dijalankan saat halaman aplikasi dimuat. Untuk hosting produksi, pindahkan proses ini ke cron/worker terjadwal.

## Pembayaran, unggahan, dan keamanan

- Pembayaran, virtual account, refund, dan penarikan **hanya simulasi aplikasi**. Tidak terhubung dengan bank/payment gateway sungguhan.
- Data formulir memakai prepared statement PDO, token CSRF, validasi sisi server, password hashing, pembatasan peran, dan validasi MIME foto.
- Foto pengguna disimpan pada `uploads/products/` dan `uploads/evidence/`. Pastikan folder `uploads` punya izin tulis untuk proses PHP.
- Pada pemasangan publik/produksi, ubah `debug` menjadi `false`, gunakan HTTPS, batasi izin file, tambahkan perlindungan rate-limit, dan integrasikan payment gateway resmi sebelum memproses uang sungguhan.

## Catatan implementasi

Prototipe asli merupakan desain statis. Beberapa operasi yang tak dapat melakukan transaksi sungguhan (payment gateway, transfer bank, pelacakan kurir aktual) ditampilkan sebagai simulasi atau input manual. Tarif demo yang dipakai checkout adalah Rp18.000 per toko dan biaya layanan mengikuti nilai `service_fee` pada tabel `system_settings`.

## API dan frontend katalog (Milestone Pertemuan 9)

Proyek ini menyediakan endpoint JSON di folder `api/` dan katalog dinamis berbasis `fetch()`.

| Method | Endpoint | Keterangan |
| --- | --- | --- |
| GET | `/api/products` | Data katalog dengan filter dan pagination |
| GET | `/api/session` | Status login dan token CSRF |
| GET | `/api/cart` | Item keranjang akun aktif (login diperlukan) |
| POST | `/api/cart/items` | Tambah produk ke keranjang (login + CSRF) |
| DELETE | `/api/cart/items/{cart_item_id}` | Hapus item milik akun aktif (login + CSRF) |

Panduan endpoint, contoh response, urutan uji Postman, dan kriteria selesai tersedia pada `docs/API_Panduan_Pengujian.md`. Koleksi Postman tersedia di `docs/ThriftApp_API.postman_collection.json`.

Untuk instalasi baru, salin `config/config.example.php` menjadi `config/config.php`, lalu sesuaikan kredensial MySQL lokal.
