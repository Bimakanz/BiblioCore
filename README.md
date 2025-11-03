<<<<<<< HEAD
# BiblioCore — School Library Management
=======
# BiblioCore — School Simple E-book
>>>>>>> 9fe1208b0c9a12fce021d7b4d16fe261627e766e


## Fitur Utama

- **Autentikasi Berbasis Role** – Admin dan siswa mendapatkan alur kerja yang berbeda, dibangun menggunakan Laravel Breeze.
- **Manajemen Koleksi** – CRUD buku, unggah sampul, pengaturan kategori, dan monitoring stok secara real-time.
- **Kontrol Peminjaman** – Admin dapat memproses status _processing_, _borrowed_, hingga _returned_ tanpa meninggalkan halaman.
- **Katalog Publik Modern** – Landing page baru dengan pencarian adaptif, kartu buku bergaya editorial, dan CTA yang jelas.
- **Form Peminjaman Dinamis** – Validasi stok otomatis dengan pengalaman form baru yang ringkas untuk siswa.
- **Dashboard Modular** – Statistik utama dipresentasikan dengan kartu warna-warni yang mudah dipindai.


- Laravel 12 + PHP 8.2
- Laravel Breeze (Blade + TailwindCSS)
- TailwindCSS + Vite
- SQLite (default) / MySQL
- PHPUnit & Pest

## Instalasi Cepat

```bash
git clone https://github.com/username/BiblioCore.git
cd BiblioCore

composer install
cp .env.example .env    
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed

npm install
npm run dev  # atau npm run build untuk produksi

php artisan serve
```

### Kredensial Default

| Peran | Username | Password   |
| ----- | -------- | ---------- |
| Admin | `admin`  | `password` |

## Testing

```bash
php artisan test
```

## Kontribusi

Temukan bug atau ingin mengusulkan peningkatan? Buat _issue_ atau _pull request_ di repository ini.

— Selamat datang di BiblioCore. Semoga pustaka digital sekolahmu semakin hidup!
