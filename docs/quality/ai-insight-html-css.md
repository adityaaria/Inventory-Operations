# AI Insight: HTML and CSS Scope

Date: 2026-09-04  
Source context: `Training Programmer - CSS & Styling Frameworks.pdf`, `AGENTS.md`, `SDD.md`, current implementation

## Purpose

Dokumen ini merangkum insight yang bisa digunakan untuk menjelaskan scope HTML dan CSS pada project Inventory & Order Management System. Fokus penjelasannya bukan hanya "tampilan sudah dibuat", tetapi bagaimana keputusan HTML/CSS mendukung requirement, maintainability, responsive behavior, dan technical defense.

## Main Insight

Scope HTML dan CSS pada project ini dikerjakan sebagai bagian dari interface operasional, bukan landing page atau template admin siap pakai. Karena brief dan `AGENTS.md` melarang CSS framework, admin template, React, Vue, Angular, jQuery, dan CSS framework, pendekatan yang dipilih adalah semantic HTML, custom CSS, dan Vanilla JavaScript.

Keputusan ini selaras dengan materi CSS & Styling Frameworks. Materi training menekankan bahwa programmer intermediate harus bisa memprediksi, membuktikan, memperbaiki, dan menjelaskan keputusan styling. Framework seperti Tailwind atau Bootstrap bukan tujuan utama; framework hanya alat. Dalam project ini, custom CSS menjadi pilihan yang paling tepat karena memberi kontrol penuh atas cascade, specificity, layout, responsive behavior, dan konsistensi komponen tanpa melanggar constraint assessment.

## What Was Implemented

### 1. Semantic HTML For Operational Pages

Setiap halaman utama dibuat dengan struktur HTML yang langsung mewakili kebutuhan operasional:

- header halaman untuk judul dan konteks modul;
- navigation/toolbar untuk aksi utama;
- form untuk filter dan input data;
- table untuk data inventory, PO, SO, master data, dan report;
- status badge untuk state bisnis seperti low stock, draft, approved, received, fulfilled, cancelled;
- pagination untuk data list;
- modal/confirm/loading state untuk interaksi yang membutuhkan feedback.

Contoh penerapannya terlihat pada halaman products. Halaman tersebut memiliki page header, toolbar, form filter, data table, status stock badge, dan pagination. Ini menunjukkan bahwa HTML dipakai sebagai struktur informasi, bukan sekadar wadah visual.

Insight untuk dijelaskan:

> Saya tidak hanya menambahkan CSS, tetapi menyusun struktur HTML agar sesuai dengan workflow pengguna: melihat data, memfilter, memahami status, lalu melakukan aksi yang diizinkan.

### 2. Custom CSS As A Lightweight Design System

CSS project dibuat sebagai satu stylesheet utama di `public/assets/css/app.css`. Di bagian awal file, project mendefinisikan design token menggunakan CSS custom properties, seperti:

- background dan surface;
- warna teks dan muted text;
- warna primary dan accent;
- warna state success, warning, danger, info, draft;
- border color;
- shadow;
- radius.

Token ini membuat styling lebih maintainable karena keputusan desain tidak tersebar sebagai angka dan warna acak di banyak file. Jika warna primary, radius, atau shadow perlu berubah, perubahan bisa dilakukan dari token utama.

Korelasi dengan materi:

- Bab 2.2 (Unit Adaptif dan Kalkulasi — "Fungsi matematika dan token"): memakai custom property sebagai token, persis seperti Contoh 4 pada modul (`--space-*`, `--content-max`, `clamp()` untuk ukuran fluida).
- Bab 6.1 (Kualitas Komponen — "Token dan kontrak komponen"): state visual harus menjadi kontrak komponen, bukan styling kebetulan.
- Bab 11 (Debugging dan Keputusan Framework) dan rubrik Lampiran B dimensi Maintainability: mengurangi duplikasi dan mencegah token drift.

Insight untuk dijelaskan:

> Saya menggunakan CSS variable sebagai design token supaya warna, radius, shadow, dan state visual konsisten di seluruh halaman. Ini membuat CSS lebih mudah dipelihara dibanding menulis nilai berulang langsung di setiap komponen.

### 3. Box Model And Sizing Control

Project menggunakan `box-sizing: border-box` secara global. Ini penting karena banyak komponen memakai padding, border, input width 100%, table, card, dan layout responsive. Dengan `border-box`, ukuran elemen lebih mudah diprediksi karena padding dan border dihitung dalam width yang ditentukan.

Korelasi dengan materi:

- Bab 1.1 (Mendiagnosis Fondasi CSS — "Box model dan ukuran aktual") menekankan bahwa banyak bug layout berasal dari salah memahami box model, termasuk contoh kasus "card melebar di production" yang dipakai sebagai project case pada modul.
- `border-box` membantu menghindari input atau card melebar keluar dari container, sesuai tabel gejala-hipotesis-bukti pada Bab 1.1 ("Input keluar dari panel" → `content-box` + padding → solusi `border-box`).

Insight untuk dijelaskan:

> Saya menetapkan baseline box model agar ukuran elemen lebih deterministik, terutama untuk form dan card yang punya padding dan border. Ini mengurangi risiko overflow yang tidak disengaja.

### 4. Flexbox And Grid Based On Layout Responsibility

Project memakai Flexbox dan Grid sesuai karakter layout:

- Flexbox digunakan pada toolbar, brand, side navigation item, pagination action, quick links, dan action alignment karena relasinya dominan satu axis.
- Grid digunakan pada form filter, metric cards, report panel, dashboard grid, chart row, app shell, dan responsive mobile layout karena relasinya lebih cocok sebagai baris-kolom.

Korelasi dengan materi:

- Bab 3 (Layout Engineering dengan Flexbox dan Grid), khususnya tabel "Gunakan Ketika" pada 3.3: Flexbox cocok untuk one-dimensional layout, sedangkan Grid cocok untuk two-dimensional layout.
- Project memakai `repeat(auto-fit, minmax(...))` pada area seperti metric cards dan filters agar layout bisa menyesuaikan jumlah ruang tanpa terlalu banyak breakpoint — pola ini identik dengan Contoh 7 (Grid adaptif) pada Bab 3.3, dan menghindari red flag "menambah breakpoint untuk masalah yang sebenarnya dapat diselesaikan auto-fit/minmax".

Insight untuk dijelaskan:

> Pemilihan Flexbox dan Grid tidak dilakukan karena kebiasaan, tetapi berdasarkan hubungan konten. Toolbar perlu wrap dalam satu axis, sedangkan dashboard dan filter perlu susunan kolom yang stabil, jadi lebih cocok memakai Grid.

### 5. Responsive Design For Inventory Data

Project sudah memiliki responsive rules untuk viewport kecil. Pada mobile:

- halaman menjadi full width;
- toolbar berubah menjadi susunan vertikal;
- tombol aksi dibuat full width agar mudah dijangkau;
- filter menjadi satu kolom;
- metric/report grid menjadi satu kolom;
- sidebar berubah menjadi normal flow;
- dashboard dan chart disusun ulang;
- tabel tetap mempertahankan minimum width agar data tidak dipaksakan rusak.

Pendekatan table pada mobile sengaja tidak menyembunyikan kolom penting. Untuk aplikasi inventory, data seperti SKU, stock, status, dan action tetap harus bisa diakses. Karena itu strategi yang dipilih adalah membuat container halaman bisa horizontal scroll saat tabel memang lebih lebar dari viewport.

Korelasi dengan materi:

- Bab Responsive Design menekankan breakpoint berbasis konten, bukan merek perangkat.
- Data penting tidak boleh disembunyikan di mobile tanpa alternatif.
- `overflow-x: hidden` bukan solusi utama karena hanya menutupi gejala.

Insight untuk dijelaskan:

> Karena project ini banyak memakai tabel operasional, saya tidak memaksa semua kolom mengecil sampai tidak terbaca. Strateginya adalah menjaga tabel tetap readable dan menyediakan overflow yang terkendali, sehingga data penting tetap dapat diakses.

### 6. Positioning, Stacking Context, Dan Z-Index

Project memakai `position: sticky` untuk sidebar dan `position: fixed` untuk overlay seperti modal, loading state, dan confirm panel. Nilai `z-index` yang dipakai saat ini hanya dua angka literal (`30` dan `40`) yang ditulis langsung pada rule terkait, tanpa dikelola sebagai custom property.

Korelasi dengan materi:

- Bab 4.1–4.2 (Positioning, Stacking Context, dan Overlay): materi menekankan bahwa keputusan `position` harus mengikuti containing block dan perilaku scroll yang dibutuhkan (sticky untuk sidebar, fixed untuk overlay global), dan itu sudah sesuai dengan implementasi project.
- Bab 4, Contoh 8 (Layer token): materi secara eksplisit merekomendasikan layer token seperti `--layer-sticky`, `--layer-modal`, `--layer-backdrop` agar urutan tumpukan mudah dijelaskan dan tidak terjadi "perang z-index". Project belum menerapkan pola ini — dua nilai z-index yang ada masih literal, bukan token.

Insight untuk dijelaskan:

> Penggunaan sticky dan fixed pada project sudah sesuai kebutuhan containing block masing-masing komponen. Namun saya belum mengekstrak nilai z-index menjadi token layer seperti yang dicontohkan materi Bab 4; ini catatan perbaikan konkret, bukan klaim bahwa stacking context project sudah ideal.

## Framework Decision Insight

Materi membahas Tailwind dan Bootstrap, tetapi project ini tidak menggunakannya karena constraint assessment secara eksplisit melarang CSS framework dan admin template. Keputusan ini bisa dijelaskan dengan decision matrix:

| Kriteria | Native Custom CSS | Tailwind | Bootstrap |
|---|---|---|---|
| Sesuai brief | Ya | Tidak | Tidak |
| Kontrol cascade | Tinggi | Sedang, tergantung utility dan build | Perlu mengikuti global framework |
| Risiko global override | Rendah jika selector dijaga | Sedang | Lebih tinggi karena Reboot/global styles |
| Build complexity | Rendah | Perlu Node/build pipeline | Bisa CDN/package, tetap framework |
| Maintainability project kecil-menengah | Baik dengan token dan komponen | Baik jika tim disiplin utility | Baik untuk UI standar, tapi perlu customization |

Kesimpulan:

> Tailwind dan Bootstrap dipahami sebagai opsi framework, tetapi tidak dipakai karena melanggar batasan project. Native CSS dipilih karena paling sesuai dengan requirement, memberi kontrol penuh, dan cukup untuk scope UI inventory yang dibangun.

## Component Quality And State Handling

Project sudah menyiapkan beberapa state visual:

- hover dan focus pada link/button;
- `:focus-visible` untuk akses keyboard;
- disabled button;
- loading spinner;
- alert untuk error;
- empty state;
- status badge untuk state bisnis;
- modal dan confirmation panel.

Ini penting karena UI operasional tidak hanya menampilkan data normal. Pengguna juga harus memahami kondisi kosong, error, loading, disabled, status stok rendah, status order, dan aksi yang sedang berlangsung.

Korelasi dengan materi:

- Bab Kualitas Komponen menekankan state matrix: normal, hover, focus, disabled, loading, success, error.
- State error atau bisnis sebaiknya tidak hanya bergantung pada warna; teks seperti "Low", "In stock", atau status order tetap ditampilkan.

Insight untuk dijelaskan:

> Saya memperlakukan state UI sebagai bagian dari kontrak komponen. Misalnya stock rendah tidak hanya diberi warna kuning, tetapi juga teks "Low", sehingga informasi tetap jelas meskipun warna tidak terbaca sempurna.

## Accessibility Considerations

Beberapa praktik aksesibilitas yang sudah diterapkan:

- form memakai label pada banyak halaman create/edit;
- focus indicator tersedia lewat `:focus-visible`;
- tombol dan link memiliki ukuran klik yang cukup;
- status tidak hanya disampaikan lewat warna;
- struktur halaman memakai heading, nav, main, table, form, dan button sesuai fungsi.

Namun ada area yang masih bisa diperkuat. **Update 2026-09-10:** audit awal (2026-09-04) mencatat "3 dari 16 file berlabel memakai `aria-describedby`" — angka itu sudah usang dan sudah digantikan oleh audit yang lebih akurat di `docs/quality/js-decision-records.md` (DR-03). Audit ulang menemukan 22 file (bukan 16) memakai `<label>`, dan menemukan bahwa `forms.js` sebenarnya menyuntikkan `aria-invalid`/`aria-describedby` secara dinamis saat validasi gagal lewat `InventoryForms.enhanceForms()`, yang berjalan di semua 28 halaman. Gap statis di markup memang nyata (hanya 1 dari 22 file punya `aria-describedby` tertulis langsung di HTML), tapi 14 dari 22 file itu adalah form create/edit dengan field `required` yang benar-benar memicu wiring dinamis tersebut saat submit gagal — jadi gap aksesibilitas riilnya bukan soal markup statis semata, melainkan ketergantungan penuh pada JavaScript berhasil dimuat. 8 file sisanya adalah halaman filter/index tanpa field `required`, sehingga gap ini tidak relevan untuk mereka. Detail lengkap ada di DR-03.

Area yang masih bisa diperkuat:

- menambahkan `aria-describedby` **statis** pada 14 form create/edit yang saat ini hanya mendapat wiring itu secara dinamis dari `forms.js` (lihat DR-03 di `js-decision-records.md`) — supaya asosiasi error tetap ada walau JavaScript gagal dimuat;
- menambahkan dokumentasi hasil uji keyboard;
- menambahkan `prefers-reduced-motion` untuk loading animation (belum ditemukan pada `app.css` saat ini);
- mengekstrak nilai `z-index` literal (`30`, `40`) menjadi custom property/token sesuai pola Bab 4, Contoh 8;
- menyimpan screenshot uji responsive dan zoom 200%.

Insight untuk dijelaskan:

> Fokus saya bukan hanya tampilan desktop. Saya juga memperhatikan keyboard focus, label form, ukuran target klik, dan state visual agar interface tetap bisa digunakan pada kondisi non-ideal.

## Maintainability Insight

CSS project dijaga dengan beberapa prinsip:

- satu file CSS utama agar source styling mudah ditemukan;
- token global untuk keputusan desain berulang;
- class komponen yang deskriptif seperti `.toolbar`, `.filters`, `.metric-card`, `.status-badge`, `.pagination`, `.sidebar`, `.dashboard-grid`;
- selector tidak dibuat terlalu panjang;
- tidak memakai `!important` sebagai strategi override, kecuali baseline utility `[hidden]` yang memang dipakai untuk memaksa hidden state;
- layout responsive dikumpulkan dalam media query yang jelas.

Insight untuk dijelaskan:

> Maintainability CSS saya jaga dengan token, nama class yang merepresentasikan komponen, dan selector yang tidak terlalu spesifik. Jadi perubahan styling masih bisa dilakukan tanpa perang specificity.

## What This Proves Against The Training Material

| Materi training | Bukti di project |
|---|---|
| Box model dan computed sizing | `box-sizing: border-box`, width dengan `min()`/`calc()`, input width 100% |
| CSS units dan calculation | penggunaan `rem`, `%`, `min()`, `calc()`, `clamp()` |
| Design token | CSS custom properties di `:root` |
| Flexbox | toolbar, navigation, action alignment |
| Grid | filters, metrics, reports, dashboard, chart rows |
| Responsive design | media query mobile, toolbar stacked, single-column filters, table overflow strategy |
| Component state | hover, focus, disabled, loading, alert, empty, status badge |
| Positioning/layering | sticky sidebar, fixed loading/modal/confirm backdrop; z-index literal (30, 40), belum ditokenkan |
| Framework decision | custom CSS dipilih karena Tailwind/Bootstrap dilarang brief |
| Debugging/maintenance | selector rendah, no framework override, evidence docs |

Referensi silang lebih rinci ke seluruh 39 topik silabus tersedia pada Lampiran A1 materi (`Traceability Detail 39 Topik Silabus`). Baris yang paling relevan untuk scope project ini adalah nomor 1–14 (CSS Properties, Responsive, Flexboxes) dan nomor 22–23 (Responsive Design & Code Maintenance) — kelompok yang secara eksplisit dipraktikkan pada project, sementara nomor 15–21 dan 24–39 (Tailwind, Bootstrap) dipahami sebagai materi pembanding, bukan yang diimplementasikan.

## Suggested Explanation For Technical Defense

Saya mengerjakan scope HTML dan CSS sebagai interface operasional untuk inventory dan order management. HTML saya susun secara semantik menggunakan `main`, `header`, `nav`, `form`, `table`, dan `button` agar struktur halaman sesuai workflow pengguna. CSS saya buat custom karena project brief melarang framework CSS dan admin template.

Dari sisi CSS, saya tidak hanya styling visual, tetapi membuat baseline yang maintainable: global `box-sizing`, design token dengan CSS variable, komponen reusable seperti toolbar, filter, card, table, badge, pagination, sidebar, modal, loading, dan alert. Layout dibuat dengan Flexbox atau Grid sesuai kebutuhan konten. Toolbar memakai Flexbox karena satu axis dan perlu wrapping, sedangkan filter, metric cards, dashboard, dan chart memakai Grid karena perlu susunan kolom yang stabil.

Untuk responsive design, saya menyesuaikan layout pada mobile: toolbar dan form menjadi satu kolom, tombol menjadi full width, dashboard disusun ulang, dan tabel tetap dibuat bisa diakses tanpa menghapus data penting. Keputusan ini penting karena aplikasi inventory banyak bergantung pada data tabel, jadi readability lebih penting daripada memaksa semua kolom mengecil.

Saya juga memperhatikan state dan aksesibilitas: focus indicator, hover, disabled, loading, empty state, alert error, dan badge status. Status seperti low stock tidak hanya diberi warna, tetapi juga teks, sehingga informasi tetap jelas.

Jika dikaitkan dengan materi CSS & Styling Frameworks, project ini menunjukkan penerapan CSS reasoning: box model, cascade, token, layout, responsive, state, dan framework decision. Tailwind dan Bootstrap tidak digunakan bukan karena tidak dipahami, tetapi karena constraint project mengharuskan custom CSS. Dengan begitu keputusan teknis tetap sesuai requirement dan mudah dipertanggungjawabkan.

## Known Gaps And Improvement Plan

Area yang bisa ditingkatkan untuk evidence (sudah diverifikasi terhadap `public/assets/css/app.css` dan `views/`):

- project saat ini hanya memiliki satu breakpoint (`max-width: 520px`); buat screenshot matrix untuk 320px, 768px, 1024px, desktop wide, dan zoom 200%, lalu dokumentasikan alasan breakpoint `520px` berdasarkan titik rusaknya konten sesuai Bab 5.2;
- tambahkan `prefers-reduced-motion` untuk spinner/loading state — belum ditemukan pada stylesheet saat ini, sesuai Bab 6.2 Contoh 12;
- [x] audit selesai 2026-09-10 (lihat `docs/quality/js-decision-records.md`, DR-03): 22 file berlabel, 14 di antaranya form create/edit dengan wiring `aria-describedby` dinamis dari `forms.js`, tapi hanya 1 yang punya wiring itu statis di markup — sisanya masih perlu scaffolding statis sebagai fallback saat JS gagal dimuat;
- ekstrak `z-index: 30` dan `z-index: 40` menjadi custom property token (mis. `--layer-sticky`, `--layer-modal`) mengikuti pola Bab 4 Contoh 8, supaya urutan tumpukan dapat dijelaskan tanpa menghitung ulang angka literal;
- buat satu debugging log CSS lengkap sesuai template Lampiran D pada materi training.

## Final Summary

Scope HTML dan CSS pada project ini sudah mendukung requirement utama sebagai aplikasi inventory operasional. Nilai utamanya ada pada custom CSS yang sesuai constraint, struktur HTML yang semantik, layout yang terukur, komponen yang reusable, responsive behavior yang realistis untuk data tabel, dan keputusan framework yang bisa dijelaskan secara teknis.
