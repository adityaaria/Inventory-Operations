# AI Insight: JavaScript Scope

Date: 2026-09-10
Source context: `output/pdf/Training Programmer - Javascript.pdf` (Intermediate Programmer — JavaScript V2), `AGENTS.md`, `docs/quality/javascript-runtime-evidence.md`, `docs/quality/js-decision-records.md`, current implementation in `public/assets/js/` dan `tests/JavaScript/`

## Purpose

Dokumen ini merangkum insight yang bisa dipakai untuk menjelaskan scope JavaScript pada project Inventory & Order Management System, dan mengaitkannya dengan materi *Training Programmer — JavaScript* (V2). Sama seperti insight HTML/CSS sebelumnya, fokusnya bukan "sudah ada interaktivitas", tetapi bagaimana keputusan JavaScript mendukung reliability, testability, dan separation of concerns — serta jujur menyebut bagian silabus yang memang tidak relevan dengan constraint project ini.

## Main Insight

Materi JavaScript V2 sebagian besar mengasumsikan stack React + TypeScript + Next.js (Bab 7–11 dari 12 bab). Project ini justru dibangun di atas PHP MVC dengan **Vanilla JavaScript** sebagai interaction layer — sesuai constraint `AGENTS.md` yang melarang React, Vue, Angular, dan jQuery. Business validation, authorization, dan stock invariant tetap dikerjakan server-side; JavaScript hanya menangani event handling, fetch orchestration, form feedback, dan enhancement tabel.

Konsekuensinya, sebagian besar reasoning yang relevan dari materi ada di **Bab 1–6 dan Bab 8** (value/reference, operator/fungsi, event, async, Web API, testing, separation of concerns) — bukan di Bab 7 (TypeScript), 9–11 (React/Next.js). Ini bukan berarti materi itu diabaikan; project justru menunjukkan bahwa prinsip di baliknya (dependency contract, boundary yang eksplisit, error strategy) tetap bisa diterapkan tanpa framework tersebut, sama seperti keputusan native CSS pada insight sebelumnya.

## What Was Implemented

### 1. Value/Reference Discipline Dan Module Pattern

Setiap file di `public/assets/js/` dibungkus sebagai IIFE/UMD kecil (`(function exposeX(root) { ... })(...)`) yang mengekspos API publik lewat `root.InventoryX`, dan memakai destructuring dengan default value untuk options (`const { allowedStatuses = [], body, fetchImpl = root.fetch.bind(root), ... } = options` pada `http.js`).

Korelasi dengan materi:

- Bab 1.1–1.2 (Values, References, dan Destructuring): pola destructuring dengan default value pada parameter `options` di `http.js` persis mengikuti "Destructuring sebagai kontrak lokal" (Contoh 2).
- Bab 2.2 (Arrow function, `this`, dan IIFE): setiap modul memakai IIFE agar tidak membocorkan variabel ke global scope selain satu namespace (`InventoryHttp`, `InventoryTables`, dst.) — pola yang sama dengan Contoh 4.

Insight untuk dijelaskan:

> Saya membungkus setiap file JS sebagai modul IIFE dengan satu titik ekspor, bukan menaruh fungsi langsung di global scope. Ini mengurangi risiko dua file saling menimpa variabel yang sama, sekaligus membuat dependency antar modul (`InventoryUi`, `InventoryHttp`, `InventoryForms`) terlihat eksplisit.

### 2. Event Handling Dan Delegation

`app.js`, `forms.js`, dan `modal.js` memakai `event.target.closest(...)` untuk delegated listener pada elemen yang dibuat dinamis (link navigasi, tombol modal, form di dalam modal body), bukan memasang listener satu-satu pada setiap elemen.

Korelasi dengan materi:

- Bab 3.2 (Delegation untuk elemen dinamis): pola `event.target.closest('a[href]')` pada `app.js` dan `event.target === modal || event.target.closest('.modal-close')` pada `modal.js` identik dengan Contoh 7 pada modul — satu listener melayani elemen yang ditambahkan belakangan.
- Modal juga menangani `Escape` untuk menutup dan `Tab` untuk *focus trap* (lihat `modal.js`), sesuatu yang tidak eksplisit dicontohkan modul tapi konsisten dengan prinsip "capture, target, bubble, dan default action" pada Bab 3.1.

Insight untuk dijelaskan:

> Saya memilih delegated listener untuk tabel dan modal karena barisnya dibuat dinamis lewat fetch/render ulang. Kalau saya pasang listener langsung ke setiap baris, listener lama akan menumpuk setiap kali tabel di-refresh.

### 3. Asynchronous Request Coordination

`http.js` mengekspos `createRequestCoordinator()` yang membatalkan request aktif sebelum memulai request baru (`active?.controller.abort()`), dan `fetchHtml()` membedakan `AbortError` (diteruskan begitu saja) dari kegagalan jaringan lain (dibungkus sebagai `NetworkRequestError`).

```js
function createRequestCoordinator(controllerFactory = () => new AbortController()) {
    let active = null;
    function start() {
        active?.controller.abort();
        const controller = controllerFactory();
        const request = { controller };
        active = request;
        return {
            signal: controller.signal,
            isCurrent: () => active === request,
            finish: () => { if (active === request) active = null; },
        };
    }
    ...
}
```

Korelasi dengan materi:

- Bab 4.3 (Error, finally, dan race condition — "Membatalkan request usang"): pola ini nyaris identik dengan Contoh 10 pada modul (`activeController?.abort()` sebelum request baru, `AbortError` diperlakukan berbeda dari error lain).
- Bab 4.2: kebutuhan "batalkan request usang" pada tabel keputusan Bab 4.2 dipenuhi lewat `AbortController`/`AbortSignal`, bukan flag manual.

Insight untuk dijelaskan:

> Saya tidak membiarkan dua request fetch berjalan bersamaan untuk aksi yang sama (misalnya buka modal dua kali cepat). Request lama saya batalkan lebih dulu lewat `AbortController`, dan `AbortError` saya biarkan lewat tanpa dianggap error jaringan biasa.

### 4. Web Storage Dan Custom Error Strategy

`tables.js` menyimpan nilai filter tabel ke `sessionStorage` dengan key yang mengikuti `location.pathname` dan id tabel, dibungkus `try/catch` agar gagal-baca tidak mematikan halaman. `http.js` mendefinisikan dua custom error (`HttpResponseError`, `NetworkRequestError`) yang memisahkan detail teknis (`status`, `body`, `cause`) dari pesan yang ditampilkan ke pengguna.

Korelasi dengan materi:

- Bab 5.1: `sessionStorage` dipilih (bukan `localStorage`) karena filter tabel memang seharusnya berumur sepanjang tab/session, bukan permanen — sesuai tabel lifetime pada Bab 5.1.
- Bab 5.2 (Custom error dan translation): `HttpResponseError extends Error` dan `NetworkRequestError extends Error` mengikuti pola `class AppError extends Error` pada Contoh 12, memisahkan `error.name`/`error.status` (untuk program) dari pesan pengguna.

Insight untuk dijelaskan:

> Filter tabel saya simpan di `sessionStorage`, bukan `localStorage`, karena filter itu relevan untuk satu sesi kerja saja. Error jaringan saya bungkus jadi custom error class supaya kode pemanggil bisa membedakan `AbortError`, `NetworkRequestError`, dan `HttpResponseError` tanpa mem-parsing string pesan.

### 5. Testability Tanpa Jest/Cypress

Project tidak memakai Jest atau Cypress seperti dicontohkan Bab 6, tetapi memakai **Node built-in test runner** (`node:test` + `node:assert/strict`). Testability tetap dicapai lewat *dependency injection* eksplisit: `fetchHtml` menerima `fetchImpl` sebagai parameter, `createRequestCoordinator` menerima `controllerFactory`, dan `debounce` di `ui-helpers.js` menerima `timerApi = { clearTimeout, setTimeout }` — pola yang memungkinkan test mengganti waktu nyata dengan stub, mirip fungsi `jest.useFakeTimers()` pada Contoh 14, tanpa perlu library eksternal.

**Terverifikasi.** Menjalankan `node --test tests/JavaScript/*.test.js` pada 2026-09-10 menghasilkan **15 pass, 0 fail** dari tiga file test (`http.test.js`, `form-validation.test.js`, `ui-helpers.test.js`), mencakup: sukses/gagal fetch, klasifikasi error jaringan, forwarding abort signal, request coordinator yang membatalkan request usang, debounce yang membatalkan timer lama, sorting numerik/teks, escaping CSV, dan validasi required/numeric/min/max.

Korelasi dengan materi:

- Bab 6.1 (Test pyramid yang pragmatis): ketiga file test itu murni unit test pada "pure logic/contract kecil" (mapper, validator, coordinator) — level paling murah dan cepat pada piramida modul, tepat sesuai definisi kolom "Unit" pada tabel 6.1.
- Bab 6.3: prinsip "kontrol waktu dan dependency" pada test async/timer tercapai lewat `timerApi` yang bisa di-stub, walau tanpa `jest.useFakeTimers()` — semangatnya sama meski toolingnya beda.
- Bab 6 tidak menyebut Node test runner sebagai opsi eksplisit (modul hanya membahas Jest dan Cypress), sehingga ini adalah keputusan implementasi di luar contoh langsung modul, bukan penerapan literal.

Insight untuk dijelaskan:

> Saya tidak memakai Jest karena project ini tidak memakai build tool/bundler — Node test runner bawaan sudah cukup untuk unit test level modul. Supaya tetap testable tanpa Jest, saya suntikkan dependency seperti `fetchImpl` dan `timerApi` lewat parameter, bukan memanggil `fetch`/`setTimeout` global langsung di dalam fungsi.

### 6. Separation Of Concerns Antar Modul

Tanggung jawab dipisah per file: `http.js` murni transport (fetch + error translation), `ui-helpers.js` murni pure function (debounce, compare, escape), `form-validation.js` murni aturan validasi client-side, sedangkan `forms.js`/`modal.js`/`tables.js` jadi lapisan orkestrasi DOM yang memanggil modul-modul itu.

Korelasi dengan materi:

- Bab 8.1 (Boundary yang dapat diuji): pemisahan ini mendekati tabel boundary Bab 8.1 — `http.js` setara "Service/adapter" (HTTP, error translation), `ui-helpers.js`/`form-validation.js` setara "Domain" (rule, calculation murni), dan `forms.js`/`tables.js`/`modal.js` setara "Presentation" (DOM, render).
- Bab 8 aslinya membahas boundary ini dalam konteks React (hook/controller, service layer, Axios/Fetch) — project menerapkan prinsip yang sama pada arsitektur PHP MVC + Vanilla JS, bukan pada komponen React.

Insight untuk dijelaskan:

> Saya memisahkan `http.js` (transport) dari `ui-helpers.js` (pure helper) dari `forms.js`/`tables.js` (orkestrasi DOM) supaya masing-masing bisa diuji sendiri-sendiri. `ui-helpers.js` dan `form-validation.js` bahkan tidak menyentuh DOM sama sekali, jadi test-nya cepat dan deterministik.

### 7. Accessibility Wiring Yang Dibentuk Secara Dinamis

`forms.js` menyuntikkan `aria-invalid="true"` dan `aria-describedby` ke field yang gagal validasi, beserta elemen `<span class="field-error" role="alert">` yang berisi pesan error — dilakukan lewat JavaScript saat submit, bukan ditulis statis di markup.

Insight ini melengkapi (bukan bertentangan dengan) temuan pada *CSS Decision Record* sebelumnya, yang mencatat baru 3 dari 16 file view yang memakai `aria-describedby` **secara statis** di markup. Faktanya, untuk form yang melewati `InventoryForms.enhanceForms()`, keterhubungan `aria-describedby` itu tetap terjadi — hanya dibentuk saat runtime, bukan terlihat langsung di source HTML. Ini nuansa penting untuk dijelaskan ke reviewer: gap aksesibilitas statis yang dicatat sebelumnya lebih kecil dampaknya pada form yang sudah di-enhance JS ini, tapi tetap tidak menghapus rekomendasi untuk form yang belum tersentuh modul ini atau yang JS-nya gagal load.

Insight untuk dijelaskan:

> Saya menghubungkan error field ke pesan errornya lewat `aria-describedby` yang dibuat JavaScript saat validasi gagal — bukan statis di HTML. Ini artinya form yang sudah dibungkus `InventoryForms` tetap accessible walau markup awalnya tidak menuliskan atribut itu, tapi form yang belum ter-enhance atau berjalan tanpa JS tetap jadi gap yang perlu diperbaiki di markup-nya sendiri.

## Stack Decision Insight

Materi membahas TypeScript (Bab 7), React (Bab 8–10), Next.js (Bab 11), Jest, dan Cypress (Bab 6) secara mendalam, tetapi project ini tidak memakai kelima-limanya karena constraint `AGENTS.md` (dilarang framework frontend) dan karena stack backend adalah PHP MVC, bukan Node/Next.js runtime. Keputusan ini bisa dijelaskan dengan decision record berikut:

| Kriteria | Vanilla JS (dipakai) | TypeScript | React + Next.js |
|---|---|---|---|
| Sesuai brief | Ya | Tidak diadopsi (constraint bahasa runtime PHP) | Tidak — dilarang eksplisit oleh `AGENTS.md` |
| Type safety | Tidak ada — divalidasi lewat test & runtime check manual | Compile-time, butuh build step | Sama seperti TypeScript bila dipakai bersama |
| Build complexity | Tidak ada build step; file dimuat langsung oleh browser | Perlu compiler/transpiler | Perlu bundler, SSR/CSR runtime |
| Kesesuaian dengan PHP MVC | Selaras — JS jadi enhancement layer tipis di atas render PHP | Bisa jalan tapi menambah pipeline terpisah dari PHP | Tidak selaras — Next.js mengasumsikan Node sebagai server |
| Testability | Dicapai lewat dependency injection manual + `node:test` | Lebih kuat lewat compiler, tapi tidak wajib untuk testability | Butuh Jest/Cypress + React Testing Library |

Kesimpulan:

> TypeScript dan React/Next.js dipahami sebagai materi inti modul, tetapi tidak dipakai karena project ini adalah aplikasi PHP MVC yang JavaScript-nya hanya interaction layer tipis, bukan single-page application. Vanilla JS dengan dependency injection manual dipilih karena cukup untuk scope ini dan tidak menambah pipeline build yang harus dipelihara terpisah dari backend PHP.

## What This Proves Against The Training Material

| Materi training | Bukti di project |
|---|---|
| Values, reference, destructuring | destructuring dengan default value pada `options` (`http.js`), rest/spread pada helper |
| Operator, function, IIFE | setiap modul dibungkus IIFE; `??`/optional chaining dipakai pada beberapa helper |
| Event & delegation | `event.target.closest(...)` pada `app.js`, `forms.js`, `modal.js`; focus trap `Tab`/`Escape` pada modal |
| Asynchronous JavaScript | `AbortController`-based request coordinator di `http.js`, membatalkan request usang sebelum request baru |
| Web API, storage, error strategy | `sessionStorage` untuk filter tabel (dibungkus try/catch); `HttpResponseError`/`NetworkRequestError extends Error` |
| Testable JavaScript | 15/15 test lulus lewat `node:test` dengan dependency injection (`fetchImpl`, `timerApi`, `controllerFactory`) — bukan Jest/Cypress |
| TypeScript sebagai kontrak | **tidak diterapkan** — project murni JavaScript, kontrak data divalidasi lewat test dan validasi runtime manual |
| Separation of concerns | `http.js` (transport) / `ui-helpers.js` (pure logic) / `forms.js`, `tables.js`, `modal.js` (orkestrasi DOM) terpisah jelas |
| React fundamentals & state | **tidak relevan** — tidak ada komponen React di project |
| Next.js App Router & auth | **tidak relevan** — backend adalah PHP MVC, bukan Next.js |
| Refactoring & profiling | **belum ada evidence** — tidak ditemukan `performance.mark`/`performance.measure` di codebase saat ini |

Referensi silang lebih rinci ke seluruh 35 topik silabus tersedia pada **Lampiran A1** materi (`Traceability Detail 35 Topik Silabus`). Baris yang paling relevan untuk scope project ini adalah nomor 1–18 (More variables/operators/functions, Event, Asynchronous JavaScript, Web API, Error handling, Interaction, Unit Test) dan nomor 21 (Separation of Concerns) — kelompok yang secara eksplisit dipraktikkan. Nomor 19–20 (TypeScript), 22–28 (React), dan 30–33 (Next.js) dipahami sebagai materi pembanding yang tidak diimplementasikan karena constraint stack; nomor 34–35 (refactoring decision & profiling hotspot) dipahami tapi belum punya evidence formal di project ini.

## Suggested Explanation For Technical Defense

Saya mengerjakan scope JavaScript sebagai interaction layer tipis di atas aplikasi PHP MVC, bukan sebagai single-page application. Setiap modul (`http.js`, `ui-helpers.js`, `form-validation.js`, `forms.js`, `tables.js`, `modal.js`, `navigation.js`, `charts.js`) dibungkus sebagai IIFE dengan satu titik ekspor, sehingga dependency antar modul terlihat eksplisit dan tidak saling menimpa variabel global.

Dari sisi reliability, saya menangani asynchronous request dengan `AbortController`: request lama dibatalkan sebelum request baru dimulai, dan `AbortError` diperlakukan berbeda dari kegagalan jaringan lain lewat custom error class (`HttpResponseError`, `NetworkRequestError`). Filter tabel disimpan di `sessionStorage`, bukan `localStorage`, karena relevansinya memang seumur sesi kerja, dan operasi baca/tulisnya dibungkus try/catch agar tidak mematikan halaman saat storage tidak tersedia.

Untuk testability, saya tidak memakai Jest atau Cypress karena project ini tidak punya build pipeline terpisah dari PHP — saya pakai Node built-in test runner dan mencapai testability yang setara lewat dependency injection manual (`fetchImpl`, `timerApi`, `controllerFactory`), bukan lewat fitur mocking bawaan framework test tertentu. Saat ini ada 15 test yang lulus, mencakup jalur sukses, error, dan cancellation.

Jika dikaitkan dengan materi JavaScript V2, project ini menunjukkan penerapan reasoning Bab 1–6 dan Bab 8 secara langsung: value/reference, event delegation, async coordination, Web API/storage, error strategy, testability, dan separation of concerns. TypeScript, React, dan Next.js (Bab 7, 9–11) tidak digunakan bukan karena tidak dipahami, tetapi karena project ini berjalan di atas stack PHP MVC yang constraint-nya melarang framework frontend — keputusan itu bisa dipertanggungjawabkan dengan decision record, bukan sekadar preferensi pribadi.

## Known Gaps And Improvement Plan

Status per 2026-09-10. Empat dari lima gap yang tercatat sebelumnya sudah dikerjakan; detail lengkapnya ada di `docs/quality/js-decision-records.md`.

- [x] **Profiling/hotspot evidence (Bab 12.2).** `tables.js`'s `filter()` sekarang membuat `performance.mark`/`performance.measure` (`table-filter:{id}`) di setiap panggilan, dan baseline murni untuk hot path-nya (`InventoryUi.matchesFilter`) terekam di `tests/JavaScript/filter-baseline.bench.js` — dijalankan 2026-09-10: 100 baris ≈ 0.016ms median, 1.000 baris ≈ 0.073ms, 5.000 baris ≈ 0.337ms. Ini baseline pengukuran pertama (belum ada optimasi untuk dibandingkan), tapi sekarang ada angka nyata untuk dijadikan pembanding kalau nanti volume data bertambah.
- [ ] **Component-level atau browser-level test (setara Cypress component test, Bab 6.4).** Masih belum ada — evidence tetap murni Node-level unit test tanpa DOM nyata/browser. Tidak dikerjakan pada iterasi ini karena butuh keputusan tooling baru (Playwright/Cypress) yang di luar scope permintaan saat ini; tetap tercatat sebagai gap terbuka.
- [x] **Decision record: Node test runner vs Jest.** Ditulis sebagai DR-01 di `docs/quality/js-decision-records.md`, mencakup alternatif yang dipertimbangkan dan trade-off-nya.
- [x] **Audit `aria-describedby` per halaman.** Audit ulang (2026-09-10) menemukan angka lama sudah usang: bukan 16 file dengan label (sekarang 22), dan bukan "13 dari 16 form tanpa wiring" — lihat DR-03. Temuan aktualnya: 14 dari 22 file adalah form create/edit dengan field `required` yang benar-benar memicu `aria-describedby` dinamis dari `InventoryForms.enhanceForms()`; 8 sisanya adalah halaman filter/index tanpa field `required` sehingga gap ini tidak relevan untuknya; hanya 1 file (`products/index.php`) yang punya `aria-describedby` statis di markup. Gap yang tersisa: 14 form itu masih 100% bergantung pada JS berhasil dimuat — belum ada scaffolding `aria-describedby` statis sebagai fallback.
- [x] **Decision record: kenapa TypeScript tidak diadopsi.** Ditulis sebagai DR-02 di `docs/quality/js-decision-records.md`, termasuk kondisi yang akan membuat keputusan ini ditinjau ulang (pertumbuhan jumlah file/kontributor).

## Final Summary

Scope JavaScript pada project ini adalah interaction layer yang reliable dan testable untuk aplikasi PHP MVC, bukan tiruan aplikasi React/Next.js. Nilai utamanya ada pada dependency injection manual yang membuat modul tetap testable tanpa Jest/Cypress, penanganan async yang disiplin lewat `AbortController`, custom error strategy yang memisahkan detail teknis dari pesan pengguna, serta separation of concerns antar modul yang jelas. TypeScript, React, dan Next.js dipahami sebagai materi inti modul tetapi sengaja tidak dipakai karena constraint stack — keputusan itu didokumentasikan, bukan sekadar dilewati.
