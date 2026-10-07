# Panduan Belajar Presentasi Akhir: Inventory & Order Management (Intermediate)

Disusun 7 Oktober 2026 untuk presentasi Kamis, 8 Oktober 2026. Sumber: `Guidelines - Presentation Final Project (Peserta).pdf`, kode, test, dan dokumen di repo ini.

Fokus asesor: **practical thinking, komunikasi, problem solving, dan critical thinking**. Artinya, Anda dinilai dari cara menjelaskan *mengapa* sebuah keputusan diambil, *apa buktinya*, dan *apa trade-off-nya*, bukan dari menghafal kode.

---

## 0. Prioritas malam ini (lakukan dulu)

| # | Item | Kenapa kritis | Status saat panduan ini dibuat |
|---|---|---|---|
| 1 | **Laporan SonarQube** yang memenuhi ketentuan lulus | Wajib di guideline (bagian 1 dan 2). Asesor akan meminta Anda menunjukkan dan menjelaskannya | **Sudah ada.** Quality Gate Passed, semua rating A. Laporan: `docs/quality/sonarqube.md`, dashboard lokal `http://127.0.0.1:9100` (lihat bagian 4.8) |
| 2 | **Commit dan push** versi final | Guideline: repo yang dikumpulkan adalah versi yang dinilai. Demo harus sama dengan isi repo | Sekitar 224 file berubah dan belum di-commit sejak commit terakhir (7 Okt, 15:25). Batas pengumpulan repo adalah 4 Okt. Siapkan penjelasan jujur jika ada perubahan setelah batas |
| 3 | Uji README dari environment bersih | Checklist guideline | Jalankan `docker compose up -d --build` dan quality gate sekali lagi |
| 4 | Siapkan data demo (lihat bagian 3) | Demo 4 menit tidak cukup jika harus membuat data dari nol | Buat PO berstatus Ordered dan SO berstatus PendingApproval sebelum presentasi |
| 5 | Cadangan: screenshot atau rekaman layar alur demo | Guideline mengizinkan bukti cadangan jika perangkat bermasalah | 12 screenshot alur nyata ada di `docs/presentation/demo-cadangan/`. Rekaman video tetap disarankan |
| 6 | Latih presentasi dengan timer **10 menit** | Waktu tetap berjalan meskipun ada gangguan | Latih minimal 2 kali |

---

## 1. Peta penilaian → bukti di repo

| Yang diminta guideline (Intermediate) | Bukti yang bisa dibuka |
|---|---|
| Alur PO, SO, penerimaan dan pengeluaran barang | Menu Purchase Orders, Sales Orders; `app/Service/PurchaseOrderService.php`, `app/Service/SalesOrderService.php` |
| Perpindahan stok | Stock Operations → Transfer (`app/Service/BusinessOperationService.php`, ADR-009) |
| Stock ledger | Reports → Stock Movements; tabel `stock_ledger`; `docs/architecture/adr-003-stock-ledger-source-of-truth.md` |
| Pembatasan 3 role (Admin, Sales, WarehouseStaff) | Login dengan 3 akun demo; otorisasi di service (misalnya `assertCanIssue`) |
| Layered architecture dan dependency | `docs/architecture/adr-001-layered-repository.md`, `docs/architecture/class-diagram-as-built.md`, `public/index.php` (composition root) |
| Transaksi DB, integritas stok, anti stok negatif dan oversell | `app/Service/StockService.php` (`issue`, baris ±154), `app/Repository/MySql/MySqlStockRepository.php` (`FOR UPDATE`, baris ±34), `docs/architecture/adr-002-stock-concurrency.md` |
| Fetch API / JSON endpoint | `GET /api/products/{sku}/availability`; Fetch di `public/assets/js/inventory-operations.js`, `public/assets/js/form-drafts.js`, `public/assets/js/http.js` |
| Unit dan integration test | `tests/Unit` (59 file), `tests/Integration` (24 file), `tests/Integration/ParallelStockIntegrationTest.php` |
| Static analysis | PHPStan level 5 (`phpstan.neon`, `docs/quality/phpstan-report.txt`); SonarQube (`docs/quality/sonarqube.md`) |
| Docker Compose | `compose.yaml` (service `app`, `db`, `test`, `test-db`), `Dockerfile` |
| Class diagram, ADR, refactoring log, dokumentasi setup | `docs/architecture/` (ADR-001 s.d. ADR-014), `docs/quality/refactor-log.md`, `README.md` |

Angka kualitas terakhir yang benar-benar dijalankan (7 Okt):
- **428 test PHP / 1949 assertion OK**
- **PHPStan level 5 tanpa error (155 file)**
- **65 test JavaScript**
- **SonarQube: Quality Gate Passed; Security, Reliability, Maintainability A; coverage 69,9%**

Bukti log ada di `docs/testing/gap-closure-2026-10-07/quality.txt`. Jalankan ulang sebelum presentasi agar angkanya pasti: `docker compose --profile quality run --build --rm test`.

---

## 2. Skrip 10 menit

Tujuannya bukan dihafal kata per kata. Pegang struktur dan kalimat kuncinya.

### Pembukaan (1 menit)
> "Project saya adalah **Inventory & Order Management multi-warehouse**. Masalah yang diselesaikan: stok antar gudang sering tidak akurat, barang bisa terjual melebihi stok (*oversell*), dan tidak ada jejak siapa mengubah stok kapan.
> Penggunanya ada tiga role. **Admin** mengelola master data, menyetujui SO, dan memesan PO. **Sales** membuat dan mengajukan SO miliknya sendiri. **Warehouse Staff** menerima barang PO, mengeluarkan barang SO, dan memproses operasi stok.
> Status: semua requirement wajib selesai, ditambah beberapa fitur opsional seperti transfer, retur, adjustment dengan approval, dan work queue.
> Kontribusi saya: [sebutkan jujur bagian yang Anda rancang dan putuskan sendiri]. Saya juga memakai AI sebagai asisten, dan itu tercatat di `ai-usage-log.md`."

### Demo alur utama (4 menit)
Lihat bagian 3. Tunjukkan **hasil proses dan perubahan data**: status berubah, stok berubah, dan ledger bertambah.

### Implementasi teknis (3 menit)
Gunakan satu jalur kode sebagai sampel, yaitu **issue Sales Order**, lalu telusuri layernya (detail di bagian 4):
1. `public/index.php`: router dan composition root (manual dependency injection), CSRF dicek di front controller.
2. `SalesOrderController` hanya mengurus HTTP.
3. `SalesOrderService::issue` mengecek role, status Approved, dan idempotency.
4. `StockService::issue` membuka transaksi, mengunci baris stok `FOR UPDATE` berurutan per product id, mengecek stok setelah lock, mengurangi stok, menambah ledger, mengubah status SO menjadi Fulfilled, lalu commit. Semua di-rollback jika ada yang gagal.
5. `MySqlStockRepository` menjalankan prepared statement.

Tambahkan satu kalimat tentang frontend ("Vanilla JS + Fetch untuk endpoint JSON, tanpa framework"), Docker ("satu perintah compose menjalankan app dan MySQL"), dan keamanan (prepared statement, escaping output, CSRF, session timeout).

### Bukti kualitas (1 menit)
Buka hasil test: unit, integration (terutama test proses paralel anti-oversell), PHPStan, dan SonarQube. Sebutkan satu perbaikan nyata yang ditemukan oleh test (contoh di bagian 6).

### Refleksi (1 menit)
- **Kendala:** menjaga stok tetap konsisten saat ada akses bersamaan dan disiplin layer tanpa framework.
- **Solusi:** transaksi dengan lock berurutan, ledger, idempotency key, dan test proses paralel.
- **Keterbatasan:** belum ada reservasi stok; SLA/overdue menunggu definisi bisnis; belum diuji di perangkat fisik.
- **Prioritas berikutnya:** reservasi stok saat SO di-approve, notifikasi, dan draft di sisi server jika dibutuhkan lintas perangkat.

---

## 3. Skenario demo (siapkan sebelumnya)

Akun: `admin@example.test`, `sales1@example.test`, `warehouse1@example.test`, semuanya dengan password `password`.

**Tips:** buka 2 browser atau jendela incognito supaya bisa berganti role tanpa logout berulang. Siapkan data di tahap yang lambat sebelum presentasi.

### Demo A: Sales Order sampai barang keluar, termasuk percobaan oversell (±2,5 menit)
1. **Products → detail SKU-DEMO-001.** Tunjukkan stok per gudang (misalnya Main 25, Secondary 4). "Stok disimpan per pasangan produk–gudang."
2. **Login Sales → Create Sales Order.** Gudang Secondary, SKU-DEMO-001 qty **6** (melebihi stok 4). Submit.
   > "Saat SO dibuat, stok belum dipotong, karena SO masih bisa ditolak. Stok baru dicek dan dipotong saat barang benar-benar keluar."
3. **Login Admin → Approve** SO tersebut.
4. **Login Warehouse → Issue Goods.** Muncul **"Insufficient stock"**. Tunjukkan bahwa stok tetap 4, status SO tetap Approved, dan **tidak ada ledger baru**.
   > "Pengecekan terjadi di dalam transaksi setelah baris stok dikunci, jadi tidak mungkin oversell, termasuk saat dua orang menekan tombol bersamaan."
5. Lakukan alur yang sama dengan SO qty **2** (sudah disiapkan sampai Approved). Issue berhasil: status **Fulfilled**, stok menjadi 2, dan **Reports → Stock Movements** menampilkan baris `Issue` dengan referensi SO.

### Demo B (pilih salah satu, ±1,5 menit)
- **PO partial receipt.** PO berstatus Ordered (sudah disiapkan), qty 10 → Warehouse menerima 4 → status **PartiallyReceived**, stok bertambah 4, ledger `Receipt`. Coba terima 7 → ditolak ("cannot exceed remaining").
- **Transfer antar gudang** (perpindahan stok). Warehouse membuat proposal Transfer → Admin approve → Warehouse post. Ledger mencatat dua baris `Adjustment` (keluar di gudang asal, masuk di gudang tujuan). Total stok produk tetap.

### Pembatasan role (±20 detik, bisa disisipkan)
- Login Sales, buka `/purchase-orders` → **403**. Sales hanya melihat SO miliknya.
  > "Otorisasi ada di service, bukan cuma menyembunyikan tombol. URL yang diketik langsung tetap ditolak."

### Jika ditanya Fetch/JSON
- Buka `/api/products/SKU-DEMO-001/availability` saat sudah login → JSON stok per gudang.
- Tanpa login → `401 {"error":"Authentication required"}`.
- Di Stock Operations → Create (Adjustment), baseline stok diambil lewat `fetch('/inventory-operations/balance?...')`.

> **Jawaban jujur yang perlu disiapkan:** endpoint `/api/products/{sku}/availability` saat ini tidak dipanggil oleh halaman mana pun. Endpoint ini disediakan untuk integrasi atau klien lain dan diuji lewat test. Fetch di UI dipakai untuk endpoint JSON lain (`/inventory-operations/balance`, `/inventory-operations/source`, `/drafts/check`) dan untuk memuat form ke modal (`http.js`).

---

## 4. Penjelasan teknis yang wajib dikuasai

### 4.1 Layered architecture
```
Browser → public/index.php (router, CSRF, error handler, composition root)
        → Controller   (HTTP saja: baca request, panggil service, render/redirect)
        → Service      (aturan bisnis, otorisasi, transisi status, orkestrasi transaksi)
        → Repository Interface  ← diimplementasikan oleh MySql* (PDO, SQL) dan InMemory* (test)
        → MySQL 8
```
- **Kenapa interface repository?** Service tidak bergantung pada MySQL. Unit test memakai repository in-memory sehingga cepat dan tanpa DB. Integration test memakai MySQL asli. Implementasinya bisa diganti tanpa mengubah aturan bisnis.
- **Dependency injection manual:** semua objek dirakit di `public/index.php` lewat constructor, tanpa container framework, sesuai larangan di brief. Trade-off: file composition root jadi panjang, tetapi dependency eksplisit dan mudah ditelusuri.
- **Aturan layer** (dari `AGENTS.md`): controller tidak boleh berisi SQL atau logika bisnis; service tidak menyentuh PDO atau superglobal; repository tidak berisi HTML atau alur bisnis.

### 4.2 Integritas stok (pertanyaan paling mungkin)
Lapisan perlindungan di `StockService::issue` dan `SalesOrderService::issue`:
1. **Baris dokumen SO ikut dikunci** (`SalesOrderService::findOrder` → `lockById`, hanya boleh di dalam transaksi). Otorisasi dan status dicek setelah lock ini; hanya SO **Approved** yang bisa di-issue. Lock dokumen inilah yang membuat issue ganda bersamaan aman: proses kedua menunggu, lalu melihat status sudah Fulfilled.
2. **Transaksi DB:** `beginTransaction`; jika ada exception, `rollBack` semuanya (`StockService::transaction`).
3. **Row lock `SELECT … FOR UPDATE`** pada `product_stocks` per (produk, gudang). Proses lain yang ingin mengubah baris yang sama harus menunggu.
4. **Urutan lock deterministik** (diurutkan per product id) agar dua transaksi tidak saling menunggu dalam urutan terbalik (*deadlock*).
5. **Cek kecukupan stok setelah lock**, bukan sebelumnya. Mengecek sebelum lock rawan *race condition* (check-then-act).
6. Kurangi stok, lalu **append ke `stock_ledger`**, lalu ubah status SO menjadi Fulfilled. Semuanya dalam **satu transaksi**, jadi semua berhasil atau tidak ada yang berubah.
7. **Pertahanan terakhir di database:** `quantity INT UNSIGNED` dan `CHECK (quantity >= 0)` pada `product_stocks`.
8. **Idempotency key:** klik ganda atau retry jaringan tidak memotong stok dua kali (ADR-008).

Bukti: `tests/Integration/ParallelStockIntegrationTest.php` menjalankan **proses PHP paralel sungguhan**. Contohnya `testDifferentOrdersCompetingForSameStockCannotOversell` dan `testTwoProcessesIssueSameOrderOnlyOnce`.

### 4.3 Ledger sebagai sumber kebenaran
- `product_stocks` berisi saldo saat ini: cepat dibaca dan menjadi objek yang dikunci.
- `stock_ledger` berisi riwayat setiap pergerakan (`Receipt`, `Issue`, `Adjustment`) dengan referensi dokumen dan pelaku. Ledger tidak pernah diedit; koreksi dilakukan lewat movement baru.
- Rekonsiliasi: saldo = jumlah semua movement. Stok tidak boleh diubah di luar StockService dan ledger.

### 4.4 Proses bisnis dan status
- **PO:** Draft → Ordered (Admin) → PartiallyReceived → Received; Cancelled hanya dari Draft/Ordered. Penerimaan tidak boleh melebihi sisa qty.
- **SO:** Draft → PendingApproval (submit) → Approved (Admin) → Fulfilled (issue oleh Warehouse); reject atau cancel → Cancelled dengan alasan.
- **Segregation of duties:** yang menjual (Sales), yang menyetujui (Admin), dan yang mengeluarkan barang (Warehouse) berbeda. Proposal stok opsional (adjustment, transfer, retur) wajib di-approve oleh Admin lain, bukan pembuatnya.
- **Low stock:** dihitung per pasangan produk–gudang terhadap reorder point produk (keputusan D-05, menunggu konfirmasi trainer).
- **Replenishment:** saran = reorder point − stok − pasokan PO yang masih terbuka. Hanya saran; PO tetap direview manusia.

### 4.5 Keamanan
- **SQL injection:** PDO prepared statement. Kolom sort memakai **allow-list** dan dipetakan ke ekspresi SQL tetap; teks dari user tidak pernah masuk `ORDER BY`.
- **XSS:** output di view di-escape dengan `htmlspecialchars`.
- **CSRF:** token di session, divalidasi untuk setiap POST di `public/index.php` dengan `hash_equals`.
- **Session:** idle dan absolute timeout, rotasi ID, cookie aman (ADR-005). Role dibaca ulang dari DB setiap request, sehingga user yang dinonaktifkan langsung kehilangan akses.
- **Password:** `password_hash`/`password_verify`, plus rate limit login.
- **CSV injection:** nilai yang diawali `= + - @` di-escape saat ekspor.

### 4.6 Frontend
- HTML + custom CSS + Vanilla JS. Tidak ada framework atau jQuery, sesuai brief.
- Fetch API untuk endpoint JSON dan memuat form ke modal. Progressive enhancement: tanpa JS, form tetap bisa dikirim biasa.
- Responsif (360px sampai desktop). Tabel menggulir di dalam wadahnya sendiri.

### 4.7 Testing dan kualitas
- **Unit test:** service dengan repository in-memory. Contoh: transisi status, otorisasi, validasi.
- **Integration test:** MySQL asli (database `_test` terpisah dan di-reset). Contoh: query laporan, rollback, proses paralel.
- **Static analysis:** PHPStan level 5. **SonarQube harus disiapkan.**
- **Quality gate satu perintah:** `docker compose --profile quality run --build --rm test`.

### 4.8 SonarQube (cara menjelaskan dalam 1 menit)
- **Cara menjalankan:** SonarQube Community lokal di Docker (`http://127.0.0.1:9100`). Coverage dari PHPUnit (PCOV) dan Node, lalu SonarScanner. Langkah lengkap ada di `docs/quality/sonarqube.md`.
- **Kondisi awal (jujur):** Security D (9 vulnerability), Reliability C, 501 code smell, coverage 58,5%.
- **Perbaikan utama:**
  - Password di script verifikasi diganti nilai acak per run, dan TLS minimal 1.2.
  - Kurung kurawal di semua struktur kontrol PHP (61 file, perilaku tidak berubah, regresi penuh dijalankan ulang).
  - Label aksesibel pada input receive.
  - Perbaikan kecil JS.
  - Coverage view ikut diukur.
- **Kondisi akhir:** Quality Gate Passed; Security, Reliability, Maintainability **A**; 0 hotspot; coverage 69,9% (PHP dan view 79,4%); duplikasi 5,6%.
- **26 issue di-accept, masing-masing dengan alasan tertulis.** Ini sinyal critical thinking, bukan menyembunyikan masalah:
  - Cookie `secure` diatur lewat konfigurasi (wajib di production).
  - `require` untuk file yang mengembalikan nilai.
  - `tabindex` pada wadah tabel yang bisa di-scroll, yang justru diwajibkan WCAG agar bisa diakses keyboard.
  - `session_write_close` mengembalikan bool sejak PHP 7.2 (false positive).
- **Jika ditanya "kenapa Quality Gate lulus padahal ada 280 code smell?"**
  > Gate default menilai new code; yang saya tunjukkan adalah rating keseluruhan, dan semuanya A. Code smell yang tersisa tidak mengubah perilaku dan sudah masuk tech-debt (TD-020 s.d. TD-023), misalnya memecah `BusinessOperationService::propose` yang kompleksitasnya 60.

### 4.9 Docker
- `compose.yaml`: `app` (PHP 8.3) dan `db` (MySQL 8, schema dan seed otomatis). Profil `quality` menambahkan `test` dan `test-db`.
- Data MySQL disimpan di named volume, sehingga tidak hilang saat container dibuat ulang.

---

## 5. Bank pertanyaan dan jawaban model

Pola jawaban yang disukai asesor: **Inti (1 kalimat) → Bukti (buka kode/test/data) → Trade-off atau batasan.**

### A. Teknis
**1. Bagaimana mencegah stok negatif atau oversell?**
> Pengecekan dilakukan di dalam transaksi setelah baris stok dikunci `FOR UPDATE`. Jika kurang, transaksi di-rollback. Database juga punya `CHECK quantity >= 0` sebagai pertahanan terakhir. Buktinya ada di test proses paralel. *(Buka `StockService::issue` dan `ParallelStockIntegrationTest`.)*

**2. Kenapa tidak cek stok dulu baru update?**
> Itu pola check-then-act. Di antara cek dan update, proses lain bisa mengambil stok yang sama. Dengan lock, cek dan update menjadi satu unit yang terserialisasi.

**3. Kenapa pessimistic lock (`FOR UPDATE`), bukan optimistic locking (kolom versi)?**
> Konflik pada stok barang laris cukup sering, dan transaksi ini pendek. Pessimistic lock memberi jaminan langsung tanpa logika retry. Trade-off-nya, request lain menunggu sebentar. Optimistic lock lebih cocok jika konflik jarang.

**4. Bagaimana mencegah deadlock?**
> Semua baris dikunci dalam urutan yang sama (diurutkan per product id, atau per (produk, gudang) untuk transfer), jadi dua transaksi tidak saling menunggu secara silang. Jika MySQL tetap mendeteksi deadlock, salah satu transaksi di-rollback utuh dan user bisa mencoba lagi tanpa data setengah jadi.

**5. Apa yang terjadi kalau server mati di tengah issue?**
> Transaksi yang belum commit dibatalkan oleh MySQL, jadi stok, ledger, dan status tidak berubah sebagian. Itu sifat atomik transaksi InnoDB.

**6. Kalau user klik Issue dua kali atau jaringan me-retry?**
> Form membawa idempotency key. Request kedua dengan key yang sama dikenali sebagai replay dan tidak memotong stok lagi (ADR-008, test `testConcurrentDuplicateIssueBothSucceedWithOneMovement`).

**7. Kenapa ada `product_stocks` dan `stock_ledger` sekaligus?**
> Ledger adalah riwayat dan bukti audit. `product_stocks` adalah saldo cepat yang bisa dikunci. Tanpa saldo, setiap cek stok harus menjumlah seluruh ledger dan sulit dikunci. Tanpa ledger, tidak ada jejak audit.

**8. Kenapa memakai repository interface?**
> Supaya service tidak terikat ke MySQL. Unit test memakai versi in-memory, dan dependency terlihat jelas di constructor.

**9. Di mana otorisasi dilakukan?**
> Di service, misalnya `assertCanIssue`, dan di guard. UI hanya menyembunyikan tombol demi kenyamanan; keamanannya tetap di server. *(Demo: Sales membuka `/purchase-orders` → 403.)*

**10. Bagaimana alur request sampai response?**
> `public/index.php` membuat Request, mengecek CSRF untuk POST, router memilih controller, controller memanggil service, service memanggil repository, lalu controller merender view atau redirect. Error ditangani `ErrorResponder` (HTML atau JSON untuk `/api`).

**11. Bagaimana mencegah SQL injection pada sorting dan pencarian?**
> Nilai pencarian di-bind sebagai parameter. Kolom sort hanya boleh dari allow-list yang dipetakan ke ekspresi SQL tetap; nilai lain jatuh ke default.

**12. Laporan dengan data besar bagaimana?**
> Preview memakai pagination 10 baris dan agregasi di SQL. Ekspor CSV di-stream baris per baris (unbuffered query) sehingga memori tidak meledak. Kolom filter penting diberi index.

**13. Apa beda unit test dan integration test di project ini?**
> Unit test memakai repository in-memory dan menguji aturan bisnis. Integration test memakai MySQL asli dan menguji SQL, transaksi, rollback, serta konkurensi dengan proses paralel.

**14. Kenapa PHPStan level 5, bukan 8 atau 9?**
> Level 5 mewajibkan pengecekan tipe argumen dan return yang cukup ketat untuk menangkap bug nyata, tanpa menghabiskan waktu pada anotasi generik yang sangat detail. Menaikkan level adalah perbaikan bertahap berikutnya.

### B. Proses bisnis
**15. Kenapa stok tidak dipotong saat SO dibuat atau di-approve?**
> SO masih bisa ditolak atau dibatalkan. Stok dipotong saat barang fisik benar-benar keluar (issue), sehingga stok sistem sama dengan stok fisik. Konsekuensinya, dua SO yang sudah Approved bisa berebut stok yang sama, dan yang kedua akan ditolak saat issue. Solusi berikutnya adalah reservasi stok saat approve.

**16. Kenapa SO butuh approval Admin?**
> Untuk kontrol harga, kredit pelanggan, dan pemisahan tugas: penjual tidak menyetujui penjualannya sendiri.

**17. Bagaimana jika PO hanya datang sebagian?**
> Status menjadi PartiallyReceived, stok bertambah sesuai yang diterima, dan sisanya tetap terbuka. Jika supplier tidak akan mengirim sisanya, Admin bisa *close remainder* dengan alasan. Barang yang sudah diterima tetap ada dan ledger tidak dihapus (D-01, menunggu konfirmasi trainer).

**18. Bagaimana memperbaiki stok yang salah karena hitung fisik?**
> Tidak dengan mengedit angka langsung. Staff mengajukan **Adjustment** (stock count) dengan alasan, Admin lain menyetujui, lalu diposting lewat StockService dan tercatat di ledger. Jika stok sudah berubah sejak penghitungan (baseline basi), posting ditolak supaya tidak menimpa transaksi yang terjadi di antaranya.

**19. Kenapa transfer langsung (atomik), tanpa status "in transit"?**
> Scope project tidak mencakup pengiriman antar gudang. Transfer atomik menjaga total stok tetap konsisten: keluar dan masuk dalam satu transaksi. Jika butuh barang di perjalanan, perlu status dan dokumen tambahan.

**20. Apa arti low stock jika ada banyak gudang?**
> Dihitung per gudang, karena stok di gudang lain tidak bisa langsung dipakai tanpa transfer. Threshold reorder point masih satu per produk (D-05, menunggu konfirmasi).

**21. Siapa pengguna dashboard dan apa gunanya?**
> Admin: nilai inventori, low stock, dokumen menunggu. Sales: status order miliknya. Warehouse: antrean receipt dan issue. Angka dihitung dari data nyata, bukan hardcode.

### C. Critical thinking dan problem solving (pertanyaan "bagaimana jika")
**22. Dua staff gudang meng-issue SO yang sama bersamaan?**
> Keduanya mengunci baris SO dan stok. Yang pertama mengubah status menjadi Fulfilled. Yang kedua, setelah lock dilepas, melihat status bukan Approved lagi dan ditolak. Stok hanya terpotong sekali. *(Test: `testTwoProcessesIssueSameOrderOnlyOnce`.)*

**23. Produk dinonaktifkan saat masih ada di SO?**
> Pembuatan SO baru menolak produk nonaktif. SO yang sudah ada tetap menyimpan item historisnya.

**24. Kalau requirement berubah, misalnya ada status "Rejected" untuk SO?**
> Saya tidak menambah status tanpa keputusan. Saat ini reject dipetakan ke Cancelled dengan alasan tersimpan (D-02). Jika disetujui, saya menambah status di enum dan transisinya di service, lalu memperbarui test transisi dan laporan.

**25. Bagaimana memastikan perubahan tidak merusak fitur lain?**
> Quality gate penuh (unit, integration, PHPStan, JS), ditambah suite HTTP dan browser end-to-end di stack Docker yang bersih. Contoh nyata: setelah menambah form PO multi-item, suite bisnis lama menangkap regresi (prefill produk tertimpa oleh template tersembunyi), lalu saya perbaiki dan tambahkan test.

**26. Apa yang paling berisiko di sistem ini?**
> Konsistensi stok saat konkurensi, karena itu dijaga berlapis. Risiko lain: keputusan bisnis yang belum dikonfirmasi trainer (D-01 s.d. D-08) dan draft form yang disimpan di browser pada komputer bersama.

**27a. Bagaimana hasil SonarQube dan apa yang Anda perbaiki?**
> Lihat bagian 4.8: dari Security D/Reliability C menjadi semua A. Yang benar-benar cacat saya perbaiki; yang memang disengaja saya accept dengan alasan tertulis di Sonar. Contoh paling menarik adalah `tabindex` pada tabel: Sonar menganggapnya salah, padahal itu yang membuat tabel bisa di-scroll dengan keyboard.

**27. Kalau Anda punya waktu 1 minggu lagi?**
> Reservasi stok saat SO di-approve, SLA dan overdue setelah target bisnis ditetapkan, serta menaikkan level PHPStan dan menindaklanjuti temuan SonarQube.

### D. Permintaan perubahan kecil langsung (live change)
| Kemungkinan permintaan | Di mana mengubah |
|---|---|
| Ubah jumlah baris per halaman | `app/Support/Pagination.php` (`PER_PAGE = 10`) |
| Tambah validasi input (misalnya qty maksimal) | `app/Validation/InputValidator.php` atau `assertItems` di service terkait, lalu tambah unit test |
| Tambah kolom di daftar | View di `views/<modul>/index.php`; jika perlu di-sort, tambah key ke allow-list `*SearchCriteria` dan map di `MySql*Repository::SORT_COLUMNS` |
| Ubah aturan siapa boleh melakukan aksi | Method `assertCan…` di service, bukan di view |
| Ubah pesan error | Exception di service; controller hanya meneruskan |

Saat diminta live change, **ucapkan langkah Anda**: "Saya ubah di service karena aturan bisnis tinggal di sana, lalu saya jalankan unit test terkait."

---

## 6. Cerita problem solving (siapkan 2–3)

Gunakan pola **Situasi → Diagnosis → Tindakan → Hasil → Pelajaran**. Ceritakan hanya yang benar-benar Anda pahami. Jika dibantu AI, sebutkan.

1. **Regresi tertangkap test lama.** Setelah form PO menjadi multi-item, suite HTTP bisnis gagal 1 dari 76 check. Diagnosis: template baris tersembunyi punya atribut `name`, sehingga parser membaca `product_id` kedua yang kosong. Tindakan: template tanpa `name`, ditambah unit test "hanya satu `product_id`". Hasil: 76/76. Pelajaran: test regresi lama harus dijalankan ulang setelah fitur baru.
2. **Draft hilang saat konfirmasi dibatalkan.** Draft awalnya dihapus pada event `submit`, padahal submit bisa dibatalkan oleh validasi atau dialog konfirmasi. Diagnosis lewat test browser. Tindakan: hapus draft pada event `formdata`, yang hanya terjadi saat data benar-benar dikirim. Pelajaran: pahami siklus event, jangan berasumsi.
3. **Error konsol saat pindah halaman.** Chrome melaporkan `InvalidStateError` dari view transition. Diagnosis dengan probe: terjadi juga di link lama, dan promise-nya tidak terjangkau JS. Tindakan: handler `unhandledrejection` yang sangat sempit. Hasil: 0 dari 15 navigasi uji. Pelajaran: buktikan dulu sumber masalahnya sebelum mengubah kode.
4. **Cerita versi Anda sendiri** dari fase awal, misalnya membangun router tanpa framework atau desain transaksi stok. Ini yang paling kuat karena asesor ingin melihat pemahaman Anda.

---

## 7. Komunikasi

- **Mulai dari jawaban, baru detail.** "Ya, dicegah dengan lock di dalam transaksi. Saya tunjukkan kodenya."
- **Selalu tawarkan bukti:** buka file, test, atau data. Itu menunjukkan practical thinking.
- **Sebutkan trade-off sendiri** sebelum ditanya. Itu sinyal critical thinking.
- **Jika tidak tahu:** "Saya belum yakin. Yang saya tahu…, dan cara saya memastikannya adalah…". Jangan menebak atau mengarang.
- **Bedakan fakta dan rencana:** "Sudah ada dan diuji" berbeda dengan "belum ada, rencananya…".
- **Keputusan yang belum dikonfirmasi trainer** (D-01 s.d. D-08 di `docs/planning/DECISIONS_PENDING.md`): sebut sebagai *default sementara*, bukan aturan resmi.
- **Penggunaan AI** (guideline mewajibkan keterbukaan):
  > "Saya memakai AI (Codex dan Claude) sebagai asisten untuk implementasi, review, dan pengujian. Semua penggunaan tercatat di `ai-usage-log.md`. Keputusan arsitektur dan bisnis saya review, dan saya bisa menjelaskan serta mengubah kodenya."
  Pastikan kalimat terakhir benar dengan mempelajari jalur kode di bagian 4.

---

## 8. Latihan mandiri malam ini (cek pemahaman)

Jawab tanpa melihat, lalu cocokkan dengan kode:
1. Telusuri klik "Issue Goods" dari tombol sampai SQL. Sebutkan nama file dan method di setiap layer.
2. Mengapa `usort` dipanggil sebelum lock di `StockService::issue`?
3. Apa yang terjadi pada ledger jika `markFulfilled` melempar exception?
4. Di mana token CSRF divalidasi? Apa yang terjadi jika token salah?
5. Mengapa Sales tidak bisa membuka report Stock Movements, dan di layer mana itu dicegah?
6. Bagaimana menjalankan seluruh test dengan satu perintah? Apa isi output-nya?
7. Sebutkan 3 ADR dan keputusan masing-masing dalam satu kalimat.
8. Jelaskan rumus replenishment dan kenapa hanya saran.
9. Apa batasan terbesar project ini dan bagaimana Anda akan mengatasinya?
10. Tunjukkan class diagram dan jelaskan hubungan Controller–Service–RepositoryInterface.

Latih juga presentasi lengkap dengan timer. Targetnya **9 menit 30 detik**, supaya ada cadangan waktu jika demo melambat.
