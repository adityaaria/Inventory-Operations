<?php

declare(strict_types=1);

// Builds the FMCG demo seed: master data, 500 SKUs in 3 warehouses and 1,000 PO/SO documents spread over six months.
// Every stock change goes through the real services (StockService transaction + ledger + audit). `SET TIMESTAMP`
// moves the MySQL session clock to each event, so CURRENT_DATE/NOW()/CURRENT_TIMESTAMP give historical dates.
// Run only through scripts/build-fmcg-seed.py: it drops every table of the disposable *_test database first.

use App\Entity\User;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlOrderExceptionRepository;
use App\Repository\MySql\MySqlProductRepository;
use App\Repository\MySql\MySqlPurchaseOrderRepository;
use App\Repository\MySql\MySqlSalesOrderRepository;
use App\Repository\MySql\MySqlStockCatalogRepository;
use App\Repository\MySql\MySqlStockLedgerRepository;
use App\Repository\MySql\MySqlStockRepository;
use App\Repository\MySql\MySqlTransactionManager;
use App\Security\AuthContext;
use App\Service\MasterDataAuthorizationService;
use App\Service\OrderExceptionService;
use App\Service\ProductService;
use App\Service\PurchaseOrderService;
use App\Service\SalesOrderService;
use App\Service\StockService;
use App\Support\Config;
use App\Support\DatabaseFactory;
use App\Support\ProductInput;
use App\Support\RequestOrigin;

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli' || preg_match('/^[a-zA-Z0-9_]+_test$/D', (string) getenv('DB_DATABASE')) !== 1) {
    fwrite(STDERR, "Refusing: the generator drops all tables and runs only against a disposable *_test database.\n");
    exit(2);
}

const MARKER = '-- ==== Generated FMCG seed';
const START = '2026-04-10';
const END = '2026-10-08 17:30:00';
const PASSWORD_HASH = '$2y$12$oyfiM/IEnP2h0lhMMjisKu/pAWoGTR0z5By1yobcKkpbsS1D4nBGS'; // "password", demo only
const PURCHASE_ORDERS = 400;
const SALES_ORDERS = 600;
const SKUS = 500;

mt_srand(20261009);

$pdo = (new DatabaseFactory(Config::fromEnvironment()))->create();
$schema = (string) file_get_contents(dirname(__DIR__) . '/database/schema-and-seed.sql');
$ddl = str_contains($schema, MARKER) ? substr($schema, 0, (int) strpos($schema, MARKER)) : throw new RuntimeException('Seed marker missing.');
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
    $pdo->exec('DROP TABLE `' . str_replace('`', '``', (string) $table) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
$pdo->exec($ddl);

function between(int $min, int $max): int
{
    return mt_rand($min, $max);
}

/** @template T @param list<T> $items @return T */
function pick(array $items): mixed
{
    return $items[mt_rand(0, count($items) - 1)];
}

function chance(float $probability): bool
{
    return mt_rand() / mt_getrandmax() < $probability;
}

/** Sets the session clock used by CURRENT_DATE, NOW() and CURRENT_TIMESTAMP. */
function at(PDO $pdo, DateTimeImmutable $time): void
{
    $pdo->exec('SET TIMESTAMP = ' . $time->getTimestamp());
}

/** Next business moment (Mon–Sat, 08:00–17:00) at or after $time. */
function businessTime(DateTimeImmutable $time): DateTimeImmutable
{
    while ((int) $time->format('N') === 7 || (int) $time->format('G') >= 17) {
        $time = $time->modify('+1 day')->setTime(8, between(0, 59));
    }
    if ((int) $time->format('G') < 8) {
        $time = $time->setTime(8, between(0, 59));
    }

    return $time;
}

function insert(PDO $pdo, string $table, array $row): int
{
    $columns = array_keys($row);
    $statement = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')');
    $statement->execute($row);

    return (int) $pdo->lastInsertId();
}

$utc = new DateTimeZone('UTC');
$setup = new DateTimeImmutable('2026-03-02 09:00:00', $utc);
$end = new DateTimeImmutable(END, $utc);
at($pdo, $setup);

// ---- People -------------------------------------------------------------------------------------------------------
$users = [];
foreach ([
    ['Admin Demo', 'admin@example.test', User::ROLE_ADMIN, 0],
    ['Sales Demo One', 'sales1@example.test', User::ROLE_SALES, 1],
    ['Sales Demo Two', 'sales2@example.test', User::ROLE_SALES, 2],
    ['Warehouse Demo One', 'warehouse1@example.test', User::ROLE_WAREHOUSE_STAFF, 1],
    ['Warehouse Demo Two', 'warehouse2@example.test', User::ROLE_WAREHOUSE_STAFF, 2],
    ['Hendra Wijaya', 'hendra.wijaya@sumberniaga.test', User::ROLE_ADMIN, 0],
    ['Lina Hartono', 'lina.hartono@sumberniaga.test', User::ROLE_ADMIN, 0],
    ['Rina Kartika', 'rina.kartika@sumberniaga.test', User::ROLE_SALES, 1],
    ['Budi Santoso', 'budi.santoso@sumberniaga.test', User::ROLE_SALES, 1],
    ['Dewi Lestari', 'dewi.lestari@sumberniaga.test', User::ROLE_SALES, 2],
    ['Agus Pratama', 'agus.pratama@sumberniaga.test', User::ROLE_SALES, 2],
    ['Sari Wulandari', 'sari.wulandari@sumberniaga.test', User::ROLE_SALES, 3],
    ['Fajar Nugroho', 'fajar.nugroho@sumberniaga.test', User::ROLE_SALES, 3],
    ['Joko Susilo', 'joko.susilo@sumberniaga.test', User::ROLE_WAREHOUSE_STAFF, 1],
    ['Andi Saputra', 'andi.saputra@sumberniaga.test', User::ROLE_WAREHOUSE_STAFF, 2],
    ['Yusuf Hidayat', 'yusuf.hidayat@sumberniaga.test', User::ROLE_WAREHOUSE_STAFF, 3],
    ['Maya Anggraini', 'maya.anggraini@sumberniaga.test', User::ROLE_WAREHOUSE_STAFF, 3],
] as [$name, $email, $role, $region]) {
    $id = insert($pdo, 'users', ['name' => $name, 'email' => $email, 'password_hash' => PASSWORD_HASH, 'role' => $role, 'is_active' => 1]);
    $users[] = ['id' => $id, 'auth' => new AuthContext($id, $email, $role), 'role' => $role, 'region' => $region];
}
$byRole = static fn (string $role, ?int $region = null): array => array_values(array_filter($users, static fn (array $u): bool => $u['role'] === $role && ($region === null || $u['region'] === $region)));
$admins = $byRole(User::ROLE_ADMIN);

// ---- Warehouses, categories, suppliers, customers -----------------------------------------------------------------
$warehouses = [];
foreach ([
    ['Gudang Pusat Cikarang', 'Kawasan Industri Jababeka Blok C-12, Cikarang Utara, Bekasi'],
    ['Gudang Distribusi Surabaya', 'Jl. Rungkut Industri III No. 18, Surabaya'],
    ['Gudang Regional Makassar', 'Kawasan Industri Makassar (KIMA) Jl. Kima 10 Kav. M-7, Makassar'],
] as [$name, $location]) {
    $warehouses[] = insert($pdo, 'warehouses', ['name' => $name, 'location' => $location, 'is_active' => 1]);
}

// code => [name, description]
$categoryRows = [
    'MIN' => ['Minuman', 'Air mineral, teh siap minum, minuman isotonik, jus dan minuman bersoda.'],
    'KTE' => ['Kopi & Teh', 'Kopi bubuk, kopi sachet dan teh celup atau seduh.'],
    'SUS' => ['Susu & Olahan Susu', 'Susu UHT, susu kental manis dan susu bubuk.'],
    'MIE' => ['Mi & Makanan Instan', 'Mi instan goreng dan kuah, bihun serta bubur instan.'],
    'SNK' => ['Makanan Ringan', 'Keripik, kacang dan camilan kemasan.'],
    'BIS' => ['Biskuit & Wafer', 'Biskuit, crackers, wafer dan kue kering kemasan.'],
    'BMB' => ['Bumbu & Saus', 'Kecap, saus sambal, saus tomat, bumbu instan dan kaldu.'],
    'SBK' => ['Sembako', 'Minyak goreng, gula pasir, beras, tepung terigu dan garam.'],
    'PDR' => ['Perawatan Diri', 'Sabun mandi, sampo, pasta gigi dan deodoran.'],
    'PRT' => ['Perawatan Rumah Tangga', 'Deterjen, sabun cuci piring, pembersih lantai dan pewangi pakaian.'],
    'BYI' => ['Ibu & Bayi', 'Popok, tisu basah, minyak telon dan bedak bayi.'],
    'KES' => ['Kesehatan', 'Vitamin, minyak angin dan perlengkapan kesehatan ringan.'],
];
$categories = [];
foreach ($categoryRows as $code => [$name, $description]) {
    $categories[$code] = insert($pdo, 'categories', ['name' => $name, 'description' => $description, 'is_active' => 1]);
}

// Principals: name, email domain, phone, address, brands
$supplierRows = [
    ['PT Tirta Alam Sejahtera', 'tirtaalam', '021-8990-1120', 'Jl. Raya Narogong Km 12, Bekasi', ['Tirta Alam', 'Segar Kita']],
    ['PT Kopi Nusantara Prima', 'kopinusantara', '0341-552-310', 'Jl. Raya Tumpang No. 45, Malang', ['Kopi Rakyat', 'Teh Kebun Raya']],
    ['PT Susu Lembah Hijau', 'lembahhijau', '022-7781-456', 'Jl. Raya Lembang No. 210, Bandung Barat', ['Lembah Hijau', 'Mini Moo']],
    ['PT Sari Pangan Indonesia', 'saripangan', '021-5940-772', 'Jl. Gatot Subroto Km 8, Tangerang', ['Mi Pelangi', 'Selera Nusa']],
    ['PT Gurih Jaya Snack', 'gurihjaya', '031-8670-221', 'Jl. Raya Gedangan No. 77, Sidoarjo', ['Kriuk Jaya', 'Tela Mas']],
    ['PT Mentari Biskuit Mandiri', 'mentaribiskuit', '024-6580-114', 'Jl. Industri Candi Blok A-5, Semarang', ['Biskuit Mentari', 'Wafer Ceria']],
    ['PT Bumbu Dapur Nusantara', 'bumbudapur', '031-7491-305', 'Jl. Margomulyo Indah Blok D-9, Surabaya', ['Dapur Ibu', 'Kecap Rajawali', 'Saus Selera']],
    ['PT Sawit Emas Lestari', 'sawitemas', '061-6852-400', 'Jl. Kawasan Industri Medan II, Deli Serdang', ['Sawit Emas', 'Manis Murni', 'Pandan Lestari', 'Tepung Kencana']],
    ['PT Cahaya Kosmetika Indonesia', 'cahayakosmetika', '021-4682-919', 'Jl. Pulogadung Raya No. 31, Jakarta Timur', ['Bening', 'Rambut Indah', 'Senyum Putih']],
    ['PT Bersih Rumah Nusantara', 'bersihrumah', '021-8838-530', 'Jl. Raya Cileungsi Km 19, Bogor', ['Kilau', 'Cuci Bersih', 'Wangi Lantai']],
    ['PT Buah Hati Care', 'buahhaticare', '021-2910-645', 'Jl. Daan Mogot Km 14, Jakarta Barat', ['Buah Hati', 'Lembut']],
    ['PT Sehat Sentosa Farma', 'sehatsentosa', '022-5940-118', 'Jl. Soekarno-Hatta No. 702, Bandung', ['Vita Sehat', 'Hangat Herbal']],
    ['PT Mutiara Jaya Distribusi', 'mutiarajaya', '021-6231-887', 'Jl. Pangeran Jayakarta No. 88, Jakarta Pusat', ['Pilihan Hemat']],
    ['PT Anugerah Pangan Sentosa', 'anugerahpangan', '0411-442-615', 'Jl. Perintis Kemerdekaan Km 15, Makassar', ['Rasa Nusantara']],
    ['PT Kemilau Home Care', 'kemilauhomecare', '031-8492-073', 'Jl. Raya Waru No. 112, Sidoarjo', ['Asri']],
];
$suppliers = [];
$brandSupplier = [];
foreach ($supplierRows as [$name, $domain, $phone, $address, $brands]) {
    $id = insert($pdo, 'suppliers', ['name' => $name, 'email' => 'order@' . $domain . '.test', 'phone' => $phone, 'address' => $address, 'is_active' => 1]);
    $suppliers[] = $id;
    foreach ($brands as $brand) {
        $brandSupplier[$brand] = $id;
    }
}

// Outlets per warehouse region (1 = Jabodetabek/Jawa Barat, 2 = Jawa Timur/Bali, 3 = Sulawesi)
$cities = [
    1 => [['Jakarta Timur', '021'], ['Jakarta Selatan', '021'], ['Bekasi', '021'], ['Bogor', '0251'], ['Depok', '021'], ['Tangerang', '021'], ['Bandung', '022'], ['Karawang', '0267']],
    2 => [['Surabaya', '031'], ['Sidoarjo', '031'], ['Malang', '0341'], ['Gresik', '031'], ['Kediri', '0354'], ['Denpasar', '0361'], ['Jember', '0331']],
    3 => [['Makassar', '0411'], ['Gowa', '0411'], ['Maros', '0411'], ['Parepare', '0421'], ['Kendari', '0401'], ['Palu', '0451'], ['Manado', '0431']],
];
$outletTypes = ['Toko', 'Toko', 'Minimarket', 'Swalayan', 'Grosir', 'CV'];
$outletNames = ['Sinar Jaya', 'Berkah Abadi', 'Sumber Rezeki', 'Makmur Sentosa', 'Mutiara', 'Cahaya Baru', 'Harapan Kita', 'Sejahtera', 'Bintang Timur', 'Maju Bersama', 'Barokah', 'Anugerah', 'Sentosa Mart', 'Rejeki Lancar', 'Mekar Sari', 'Karya Mandiri', 'Mitra Usaha', 'Indah Jaya', 'Sumber Makmur', 'Lancar Jaya'];
$streets = ['Jl. Merdeka', 'Jl. Pahlawan', 'Jl. Sudirman', 'Jl. Diponegoro', 'Jl. Ahmad Yani', 'Jl. Gajah Mada', 'Jl. Hasanuddin', 'Jl. Imam Bonjol', 'Jl. Pasar Baru', 'Jl. Raya Utama'];
$customers = [1 => [], 2 => [], 3 => []];
$usedNames = [];
for ($i = 0; $i < 66; $i++) {
    $region = $i % 3 + 1;
    [$city, $area] = pick($cities[$region]);
    do {
        $name = pick($outletTypes) . ' ' . pick($outletNames) . ' ' . $city;
    } while (isset($usedNames[$name]));
    $usedNames[$name] = true;
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    $customers[$region][] = insert($pdo, 'customers', [
        'name' => $name, 'email' => 'purchasing@' . substr($slug, 0, 28) . '.test',
        'phone' => $area . '-' . between(500, 899) . '-' . between(1000, 9999),
        'address' => pick($streets) . ' No. ' . between(1, 250) . ', ' . $city, 'is_active' => 1,
    ]);
}

// ---- Catalogue: 500 SKUs ------------------------------------------------------------------------------------------
// [category, brand, product, unit, [flavours], [[size, pack, price per piece]]]; velocity: 3 fast, 2 medium, 1 slow
$templates = [
    ['MIN', 'Tirta Alam', 'Air Mineral', 'karton', [''], [['330ml', 24, 1500, 3], ['600ml', 24, 2300, 3], ['1500ml', 12, 4200, 3]]],
    ['MIN', 'Tirta Alam', 'Air Mineral Galon', 'galon', [''], [['19L', 1, 17500, 2]]],
    ['MIN', 'Segar Kita', 'Teh Kemasan', 'karton', ['Melati', 'Apel', 'Lemon', 'Tawar'], [['350ml', 24, 3200, 3], ['500ml', 24, 4100, 2]]],
    ['MIN', 'Segar Kita', 'Minuman Isotonik', 'karton', ['Original', 'Jeruk Nipis'], [['500ml', 24, 5200, 2], ['350ml', 24, 4300, 2]]],
    ['MIN', 'Segar Kita', 'Jus Buah', 'karton', ['Jeruk', 'Jambu', 'Mangga', 'Apel'], [['250ml', 24, 4100, 2]]],
    ['MIN', 'Segar Kita', 'Minuman Soda', 'karton', ['Lemon', 'Stroberi', 'Kola'], [['330ml kaleng', 24, 5600, 1]]],
    ['KTE', 'Kopi Rakyat', 'Kopi Bubuk', 'karton', ['Robusta', 'Arabika Gayo', 'Toraja'], [['165g', 24, 14500, 2], ['380g', 12, 31000, 1]]],
    ['KTE', 'Kopi Rakyat', 'Kopi Instan 3in1', 'karton', ['Original', 'Mocca', 'Gula Aren', 'Susu'], [['10 sachet x 20g', 12, 11500, 3], ['30 sachet x 20g', 6, 33000, 2]]],
    ['KTE', 'Teh Kebun Raya', 'Teh Celup', 'karton', ['Melati', 'Hijau', 'Hitam', 'Chamomile'], [['25 kantong', 48, 6800, 3], ['50 kantong', 24, 12800, 2]]],
    ['KTE', 'Teh Kebun Raya', 'Teh Seduh', 'karton', ['Melati', 'Tubruk'], [['40g', 60, 3900, 2], ['100g', 30, 8700, 1]]],
    ['SUS', 'Lembah Hijau', 'Susu UHT', 'karton', ['Full Cream', 'Cokelat', 'Stroberi', 'Rendah Lemak'], [['200ml', 24, 5600, 3], ['1L', 12, 19500, 2]]],
    ['SUS', 'Lembah Hijau', 'Susu Kental Manis', 'karton', ['Putih', 'Cokelat'], [['370g kaleng', 24, 11800, 2], ['545g pouch', 24, 14200, 2], ['40g sachet x 6', 24, 7100, 2]]],
    ['SUS', 'Mini Moo', 'Susu Anak UHT', 'karton', ['Vanila', 'Cokelat', 'Pisang'], [['115ml', 36, 3200, 3]]],
    ['SUS', 'Mini Moo', 'Susu Bubuk Pertumbuhan 1-3 Tahun', 'karton', ['Vanila', 'Madu'], [['400g', 12, 52000, 1], ['800g', 6, 98000, 1]]],
    ['MIE', 'Mi Pelangi', 'Mi Goreng', 'karton', ['Original', 'Ayam Bawang', 'Rendang', 'Pedas Ekstra', 'Sate'], [['85g', 40, 2900, 3]]],
    ['MIE', 'Mi Pelangi', 'Mi Kuah', 'karton', ['Ayam Bawang', 'Soto', 'Kari Ayam', 'Kaldu Sapi', 'Baso'], [['75g', 40, 2700, 3]]],
    ['MIE', 'Selera Nusa', 'Mi Cup', 'karton', ['Ayam Bawang', 'Soto', 'Pedas'], [['60g', 24, 4700, 2]]],
    ['MIE', 'Selera Nusa', 'Bihun Instan', 'karton', ['Goreng', 'Kuah Soto'], [['55g', 40, 2600, 2]]],
    ['MIE', 'Selera Nusa', 'Bubur Instan', 'karton', ['Ayam', 'Abon Sapi'], [['45g', 24, 5300, 1]]],
    ['SNK', 'Kriuk Jaya', 'Keripik Kentang', 'karton', ['Original', 'Balado', 'Keju', 'Rumput Laut', 'Barbeque'], [['68g', 30, 8400, 2], ['15g', 120, 1800, 3]]],
    ['SNK', 'Tela Mas', 'Keripik Singkong', 'karton', ['Original', 'Balado Pedas', 'Sapi Panggang'], [['140g', 24, 9600, 2]]],
    ['SNK', 'Tela Mas', 'Kacang Atom', 'karton', ['Original', 'Pedas'], [['150g', 24, 8100, 2]]],
    ['SNK', 'Kriuk Jaya', 'Stik Jagung', 'karton', ['Keju', 'Bakar'], [['40g', 60, 3600, 2]]],
    ['BIS', 'Biskuit Mentari', 'Biskuit Kelapa', 'karton', ['Original'], [['300g', 24, 9900, 2], ['70g', 60, 2700, 3]]],
    ['BIS', 'Biskuit Mentari', 'Crackers', 'karton', ['Original', 'Gandum', 'Keju'], [['250g', 24, 11200, 2]]],
    ['BIS', 'Biskuit Mentari', 'Biskuit Krim', 'karton', ['Cokelat', 'Vanila', 'Stroberi', 'Kacang'], [['120g', 48, 5100, 2]]],
    ['BIS', 'Wafer Ceria', 'Wafer', 'karton', ['Cokelat', 'Keju', 'Vanila', 'Stroberi'], [['130g', 36, 6200, 2], ['20g', 120, 1100, 3]]],
    ['BIS', 'Wafer Ceria', 'Wafer Stik', 'karton', ['Cokelat', 'Keju'], [['200g', 24, 9300, 1]]],
    ['BMB', 'Kecap Rajawali', 'Kecap Manis', 'karton', ['Original'], [['135ml', 48, 4600, 3], ['275ml', 24, 8800, 2], ['520ml', 12, 15500, 2]]],
    ['BMB', 'Saus Selera', 'Saus Sambal', 'karton', ['Original', 'Extra Pedas', 'Bawang'], [['135ml', 48, 4900, 2], ['340ml', 24, 10800, 2]]],
    ['BMB', 'Saus Selera', 'Saus Tomat', 'karton', ['Original'], [['135ml', 48, 4200, 2], ['340ml', 24, 9500, 1]]],
    ['BMB', 'Dapur Ibu', 'Bumbu Instan', 'karton', ['Rendang', 'Opor', 'Nasi Goreng', 'Soto Ayam', 'Rawon', 'Gulai'], [['45g', 60, 3900, 2]]],
    ['BMB', 'Dapur Ibu', 'Kaldu Bubuk', 'karton', ['Ayam', 'Sapi', 'Jamur'], [['100g', 48, 5300, 2], ['9g sachet x 12', 40, 6100, 3]]],
    ['SBK', 'Sawit Emas', 'Minyak Goreng', 'karton', [''], [['1L pouch', 12, 16800, 3], ['2L pouch', 6, 33000, 3], ['5L jeriken', 4, 81000, 2]]],
    ['SBK', 'Sawit Emas', 'Minyak Goreng Curah', 'jeriken', [''], [['18L', 1, 270000, 2]]],
    ['SBK', 'Manis Murni', 'Gula Pasir', 'karton', ['Premium', 'Kristal Putih'], [['1kg', 20, 16500, 3], ['500g', 40, 8600, 2]]],
    ['SBK', 'Pandan Lestari', 'Beras', 'sak', ['Pandan Wangi', 'Medium', 'Premium'], [['5kg', 1, 69000, 3], ['25kg', 1, 335000, 2]]],
    ['SBK', 'Tepung Kencana', 'Tepung Terigu', 'karton', ['Protein Sedang', 'Protein Tinggi'], [['1kg', 12, 12400, 2]]],
    ['SBK', 'Tepung Kencana', 'Garam Beryodium', 'karton', ['Halus'], [['250g', 50, 2100, 1]]],
    ['PDR', 'Bening', 'Sabun Mandi Batang', 'karton', ['Fresh', 'Lavender', 'Sereh', 'Mawar', 'Antiseptik'], [['85g', 72, 3500, 3]]],
    ['PDR', 'Bening', 'Sabun Mandi Cair', 'karton', ['Fresh', 'Lavender', 'Susu'], [['450ml pouch', 24, 17500, 2], ['250ml botol', 24, 13900, 2]]],
    ['PDR', 'Rambut Indah', 'Sampo', 'karton', ['Anti Ketombe', 'Hijab Fresh', 'Lembut Berkilau', 'Ginseng'], [['170ml', 24, 18500, 2], ['10ml sachet x 12', 24, 5600, 3]]],
    ['PDR', 'Senyum Putih', 'Pasta Gigi', 'karton', ['Siwak', 'Herbal', 'Pemutih'], [['75g', 72, 6800, 3], ['190g', 36, 14800, 2]]],
    ['PDR', 'Bening', 'Deodoran Roll On', 'karton', ['Fresh', 'Floral'], [['50ml', 48, 14200, 1]]],
    ['PRT', 'Cuci Bersih', 'Deterjen Bubuk', 'karton', ['Lavender', 'Bunga Segar', 'Anti Noda'], [['800g', 24, 17500, 3], ['1.8kg', 8, 37500, 2]]],
    ['PRT', 'Cuci Bersih', 'Deterjen Cair', 'karton', ['Matic', 'Lavender'], [['750ml pouch', 12, 21500, 2]]],
    ['PRT', 'Kilau', 'Sabun Cuci Piring', 'karton', ['Jeruk Nipis', 'Lemon', 'Teh Hijau'], [['650ml pouch', 24, 11200, 3], ['280ml', 36, 5900, 2]]],
    ['PRT', 'Wangi Lantai', 'Pembersih Lantai', 'karton', ['Lavender', 'Apel', 'Pinus'], [['770ml pouch', 12, 12300, 2]]],
    ['PRT', 'Cuci Bersih', 'Pewangi Pakaian', 'karton', ['Lembut Pagi', 'Sakura', 'Bayi'], [['900ml pouch', 12, 13500, 2]]],
    ['BYI', 'Buah Hati', 'Popok Celana', 'bal', ['S', 'M', 'L', 'XL'], [['isi 20', 4, 48500, 2], ['isi 40', 2, 92000, 2]]],
    ['BYI', 'Lembut', 'Tisu Basah Bayi', 'karton', ['Tanpa Pewangi', 'Aloe Vera'], [['isi 50', 24, 9800, 2]]],
    ['BYI', 'Buah Hati', 'Minyak Telon', 'karton', ['Original', 'Plus Lavender'], [['60ml', 48, 17500, 2], ['100ml', 24, 26800, 1]]],
    ['BYI', 'Buah Hati', 'Bedak Bayi', 'karton', ['Original', 'Lembut'], [['100g', 36, 9200, 1]]],
    ['KES', 'Vita Sehat', 'Vitamin C 500mg', 'karton', ['Jeruk', 'Anggur'], [['strip isi 4 tablet', 100, 3300, 2]]],
    ['KES', 'Hangat Herbal', 'Minyak Angin', 'karton', ['Original', 'Aromaterapi'], [['12ml', 72, 10400, 2], ['30ml', 36, 19800, 1]]],
    ['KES', 'Hangat Herbal', 'Balsem Gosok', 'karton', ['Hangat', 'Dingin'], [['20g', 72, 7400, 1]]],
    ['KES', 'Vita Sehat', 'Plester Luka', 'karton', ['Transparan', 'Kain'], [['isi 10', 100, 3100, 1]]],
];
// Second-source brands carry the same product lines, as distributors stock competing and house brands side by side.
$alternates = [
    ['Pilihan Hemat', 0.88, ['MIN', 'KTE', 'SUS', 'MIE', 'SNK', 'BIS', 'BMB', 'SBK', 'PDR', 'PRT', 'BYI']],
    ['Rasa Nusantara', 0.97, ['MIN', 'KTE', 'SUS', 'MIE', 'SNK', 'BIS', 'BMB', 'SBK']],
    ['Asri', 0.95, ['PDR', 'PRT', 'BYI', 'KES']],
];
foreach ($templates as [$code, $brand, $product, $unit, $flavours, $sizes]) {
    foreach ($alternates as [$alternate, $factor, $codes]) {
        if (in_array($code, $codes, true)) {
            $templates[] = [$code, $alternate, $product, $unit, $flavours, array_map(static fn (array $size): array => [$size[0], $size[1], (int) round($size[2] * $factor), max(1, $size[3] - 1)], $sizes)];
        }
    }
}
$candidates = [];
foreach ($templates as [$code, $brand, $product, $unit, $flavours, $sizes]) {
    foreach ($flavours as $flavour) {
        foreach ($sizes as [$size, $pack, $piece, $velocity]) {
            $label = trim($brand . ' ' . $product . ($flavour === '' ? '' : ' ' . $flavour) . ' ' . $size);
            $packLabel = $pack > 1 ? ' (' . $pack . ' ' . ($unit === 'bal' ? 'pak' : 'pcs') . ')' : '';
            $candidates[] = ['code' => $code, 'brand' => $brand, 'name' => $label . $packLabel, 'unit' => $unit, 'pack' => $pack, 'piece' => $piece, 'velocity' => $velocity];
        }
    }
}
// Deterministic shuffle, then keep 500 while every category stays represented.
usort($candidates, static fn (array $a, array $b): int => strcmp(md5('fmcg' . $a['name']), md5('fmcg' . $b['name'])));
if (count($candidates) < SKUS) {
    throw new RuntimeException('Catalogue templates produce only ' . count($candidates) . ' SKUs.');
}
$chosen = array_slice($candidates, 0, SKUS);
usort($chosen, static fn (array $a, array $b): int => [$a['code'], $a['name']] <=> [$b['code'], $b['name']]);

$stock = new StockService(new MySqlStockRepository($pdo), new MySqlStockLedgerRepository($pdo), new MySqlAuditLogRepository($pdo), new MySqlStockCatalogRepository($pdo), new MySqlTransactionManager($pdo));
$productRepository = new MySqlProductRepository($pdo);
$productService = new ProductService($productRepository, new MasterDataAuthorizationService(), $stock);
$catalog = [];
$sequence = [];
foreach ($chosen as $index => $item) {
    at($pdo, $setup->modify('+' . intdiv($index, 20) . ' day')->setTime(9 + $index % 8, ($index * 7) % 60));
    $sequence[$item['code']] = ($sequence[$item['code']] ?? 0) + 1;
    $purchase = round($item['pack'] * $item['piece'] * (0.96 + mt_rand(0, 8) / 100), -2);
    $selling = round($purchase * (1.09 + mt_rand(0, 13) / 100), -2);
    $reorder = [1 => between(4, 10), 2 => between(10, 25), 3 => between(25, 60)][$item['velocity']];
    $product = $productService->create($admins[0]['auth'], new ProductInput(
        sprintf('%s-%04d', $item['code'], $sequence[$item['code']]), $item['name'], $item['unit'], $purchase, $selling, $reorder, $categories[$item['code']],
    ));
    $catalog[$product->id()] = $item + ['id' => $product->id(), 'supplier' => $brandSupplier[$item['brand']], 'reorder' => $reorder];
}
$supplierProducts = [];
foreach ($catalog as $id => $item) {
    $supplierProducts[$item['supplier']][] = $id;
}

// ---- Services for documents --------------------------------------------------------------------------------------
$warehouseEntities = [];
foreach ((new App\Repository\MySql\MySqlWarehouseRepository($pdo))->all() as $warehouse) { $warehouseEntities[$warehouse->id()] = $warehouse; }
$supplierEntities = [];
foreach ((new App\Repository\MySql\MySqlSupplierRepository($pdo))->all() as $supplier) { $supplierEntities[$supplier->id()] = $supplier; }
$customerEntities = [];
foreach ((new App\Repository\MySql\MySqlCustomerRepository($pdo))->all() as $customer) { $customerEntities[$customer->id()] = $customer; }
$purchaseRepository = new MySqlPurchaseOrderRepository($pdo);
$salesRepository = new MySqlSalesOrderRepository($pdo);
$exceptions = new MySqlOrderExceptionRepository($pdo);
$audit = new MySqlAuditLogRepository($pdo);
$po = new PurchaseOrderService($purchaseRepository, $productRepository, $supplierEntities, $warehouseEntities, $stock, null, $exceptions);
$so = new SalesOrderService($salesRepository, $productRepository, $customerEntities, $warehouseEntities, $stock);
$orderExceptions = new OrderExceptionService($exceptions, $purchaseRepository, $salesRepository, $stock, $audit);
$stockRepository = new MySqlStockRepository($pdo);

$agents = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15', 'Mozilla/5.0 (Linux; Android 14; SM-A556E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36'];
/** Mirrors RequestAuditRecorder for the POST that would have triggered the step. */
$requestAudit = static function (array $user, string $path, ?int $id) use ($audit, $agents): void {
    $parts = explode('/', trim($path, '/'));
    $audit->append($user['id'], $parts[0] . '.' . ($parts[1] ?? 'create'), $parts[0], $id, 'success', new RequestOrigin('10.20.' . $user['region'] . '.' . (10 + $user['id']), $agents[$user['id'] % 3]), ['path' => $path, 'status_code' => 302]);
};

// ---- Plan documents ------------------------------------------------------------------------------------------------
$start = new DateTimeImmutable(START . ' 08:00:00', $utc);
$span = $end->getTimestamp() - $start->getTimestamp();
$events = new SplPriorityQueue();
$seq = 0;
// Earliest time first; equal times keep scheduling order.
$schedule = static function (DateTimeImmutable $time, string $kind, array $data) use ($events, &$seq, $end): bool {
    $time = businessTime($time);
    if ($time > $end) {
        return false;
    }
    $events->insert([$kind, $data + ['time' => $time]], [-$time->getTimestamp(), -$seq++]);

    return true;
};
// Opening stock: every principal delivers its range to each warehouse in the first two weeks.
$docs = 0;
foreach ($suppliers as $supplier) {
    foreach ($warehouses as $warehouse) {
        $schedule($start->modify('+' . between(0, 5) . ' day')->setTime(between(8, 15), between(0, 59)), 'po', ['supplier' => $supplier, 'warehouse' => $warehouse, 'opening' => true]);
        $docs++;
    }
}
for ($i = $docs; $i < PURCHASE_ORDERS; $i++) {
    $offset = (int) (14 * 86400 + (($span - 14 * 86400) * mt_rand(0, 1000000) / 1000000));
    $schedule($start->modify('+' . $offset . ' seconds'), 'po', ['supplier' => pick($suppliers), 'warehouse' => pick($warehouses), 'opening' => false]);
}
for ($i = 0; $i < SALES_ORDERS; $i++) {
    // Demand grows over the half year: a power below 1 puts more orders in later months, yet every month has orders.
    $offset = (int) (8 * 86400 + ($span - 8 * 86400) * (mt_rand(0, 1000000) / 1000000) ** 0.8);
    $schedule($start->modify('+' . $offset . ' seconds'), 'so', ['region' => between(1, 3)]);
}

// ---- Run the events in time order ----------------------------------------------------------------------------------
$monthSequence = [];
$numberFor = static function (string $prefix, DateTimeImmutable $time) use (&$monthSequence): string {
    $key = $prefix . $time->format('ym');
    $monthSequence[$key] = ($monthSequence[$key] ?? 0) + 1;

    return sprintf('%s-%s-%04d', $prefix, $time->format('ym'), $monthSequence[$key]);
};
$quantity = static fn (int $productId, int $warehouse): int => $stockRepository->quantity($productId, $warehouse);
$staffFor = static fn (int $warehouse): array => pick($byRole(User::ROLE_WAREHOUSE_STAFF, array_search($warehouse, $warehouses, true) + 1));
$counts = ['po' => 0, 'so' => 0, 'issue_skipped' => 0];
$rejectReasons = ['Limit kredit pelanggan terlampaui; menunggu pelunasan tagihan sebelumnya.', 'Harga dan jumlah tidak sesuai kesepakatan kunjungan sales.', 'Pelanggan membatalkan pesanan melalui telepon.', 'Duplikat pesanan dengan dokumen sebelumnya.'];
$closeReasons = ['Principal menyatakan stok kosong; sisa pesanan tidak akan dikirim.', 'Sisa pesanan dialihkan ke PO berikutnya sesuai jadwal pengiriman principal.', 'Kemasan lama dihentikan principal; sisa dibatalkan.'];

while (!$events->isEmpty()) {
    [$kind, $data] = $events->extract();
    $time = $data['time'];
    at($pdo, $time);
    $age = $end->getTimestamp() - $time->getTimestamp();

    if ($kind === 'po') {
        $warehouse = $data['warehouse'];
        $range = $supplierProducts[$data['supplier']];
        $lines = [];
        foreach ($range as $productId) {
            $item = $catalog[$productId];
            $current = $quantity($productId, $warehouse);
            if ($data['opening']) {
                $lines[] = ['product_id' => $productId, 'quantity' => $item['reorder'] * between(3, 6)];
            } elseif ($current < $item['reorder'] && chance(0.7)) {
                $lines[] = ['product_id' => $productId, 'quantity' => max($item['reorder'], (int) (ceil(($item['reorder'] * 4 - $current) / 5) * 5))];
            }
        }
        if (!$data['opening'] && count($lines) < 3) {
            shuffle($range);
            foreach (array_slice($range, 0, between(3, 8)) as $productId) {
                if (!in_array($productId, array_column($lines, 'product_id'), true)) {
                    $lines[] = ['product_id' => $productId, 'quantity' => $catalog[$productId]['reorder'] * between(2, 4)];
                }
            }
        }
        $lines = array_slice($lines, 0, $data['opening'] ? 60 : 18);
        $creator = chance(0.75) ? $staffFor($warehouse) : pick($admins);
        $order = $po->createDraft($creator['auth'], $numberFor('PO', $time), $data['supplier'], $warehouse, $lines);
        $requestAudit($creator, '/purchase-orders', null);
        $counts['po']++;
        // Fate: recent drafts stay Draft; most others are ordered, a few cancelled, then received in one or two drops.
        if (!$data['opening'] && $age < 4 * 86400 && chance(0.6)) {
            continue;
        }
        $orderedAt = $time->modify('+' . between(1, 20) . ' hour');
        if (!$data['opening'] && chance(0.05)) {
            $schedule($orderedAt, 'po_cancel', ['id' => $order->id()]);
            continue;
        }
        $schedule($orderedAt, 'po_order', ['id' => $order->id(), 'warehouse' => $warehouse, 'opening' => $data['opening']]);
    } elseif ($kind === 'po_cancel') {
        $admin = pick($admins);
        $po->cancel($admin['auth'], $data['id']);
        $requestAudit($admin, '/purchase-orders/cancel', $data['id']);
    } elseif ($kind === 'po_order') {
        $admin = pick($admins);
        $po->markOrdered($admin['auth'], $data['id']);
        $requestAudit($admin, '/purchase-orders/order', $data['id']);
        $twoDrops = !$data['opening'] && chance(0.25);
        $partialForever = !$data['opening'] && chance(0.07);
        $schedule($time->modify('+' . ($data['opening'] ? between(2, 5) : between(3, 10)) . ' day')->setTime(between(8, 15), between(0, 59)), 'po_receive', ['id' => $data['id'], 'warehouse' => $data['warehouse'], 'partial' => $twoDrops || $partialForever, 'rest' => $twoDrops, 'close' => $partialForever && chance(0.5)]);
    } elseif ($kind === 'po_receive') {
        $order = $purchaseRepository->findById($data['id']) ?? throw new RuntimeException('PO missing.');
        $receipt = [];
        foreach ($order->items() as $item) {
            $remaining = $item->remainingQuantity();
            if ($remaining > 0) {
                $receipt[$item->id()] = $data['partial'] ? max(1, (int) round($remaining * between(40, 80) / 100)) : $remaining;
            }
        }
        if ($data['partial'] && count($receipt) > 1) {
            unset($receipt[array_key_last($receipt)]); // one line short on the first delivery
        }
        $po->receive($staffFor($data['warehouse'])['auth'], $data['id'], $receipt);
        if ($data['rest']) {
            $schedule($time->modify('+' . between(2, 5) . ' day')->setTime(between(8, 15), between(0, 59)), 'po_receive', ['id' => $data['id'], 'warehouse' => $data['warehouse'], 'partial' => false, 'rest' => false, 'close' => false]);
        } elseif ($data['close']) {
            $schedule($time->modify('+' . between(5, 10) . ' day'), 'po_close', ['id' => $data['id']]);
        }
    } elseif ($kind === 'po_close') {
        $orderExceptions->close(pick($admins)['auth'], $data['id'], pick($closeReasons));
    } elseif ($kind === 'so') {
        $region = $data['region'];
        $warehouse = $warehouses[$region - 1];
        $customer = pick($customers[$region]);
        $sales = pick($byRole(User::ROLE_SALES, $region));
        $inStock = $pdo->prepare('SELECT product_id, quantity FROM product_stocks WHERE warehouse_id = ? AND quantity > 0');
        $inStock->execute([$warehouse]);
        $onHand = array_map('intval', $inStock->fetchAll(PDO::FETCH_KEY_PAIR));
        $available = array_keys($onHand);
        if ($available === []) {
            continue;
        }
        shuffle($available);
        $lines = [];
        foreach (array_slice($available, 0, between(2, 9)) as $productId) {
            $cap = [1 => 8, 2 => 25, 3 => 60][$catalog[$productId]['velocity']];
            $lines[] = ['product_id' => $productId, 'quantity' => max(1, min($cap, between(1, max(1, (int) ($onHand[$productId] * 0.35)))))];
        }
        $order = $so->createDraft($sales['auth'], $numberFor('SO', $time), $customer, $warehouse, $lines);
        $requestAudit($sales, '/sales-orders', null);
        $counts['so']++;
        if (chance(0.04)) {
            $schedule($time->modify('+' . between(1, 6) . ' hour'), 'so_cancel', ['id' => $order->id()]);
            continue;
        }
        $schedule($time->modify('+' . between(10, 600) . ' minute'), 'so_submit', ['id' => $order->id(), 'sales' => $sales, 'warehouse' => $warehouse]);
    } elseif ($kind === 'so_cancel') {
        $admin = pick($admins); // only Admin may cancel a sales order
        $so->rejectOrCancel($admin['auth'], $data['id']);
        $requestAudit($admin, '/sales-orders/cancel', $data['id']);
    } elseif ($kind === 'so_submit') {
        $so->submit($data['sales']['auth'], $data['id']);
        $requestAudit($data['sales'], '/sales-orders/submit', $data['id']);
        $reviewAt = $time->modify('+' . between(1, 50) . ' hour');
        $schedule($reviewAt, chance(0.05) ? 'so_reject' : 'so_approve', ['id' => $data['id'], 'warehouse' => $data['warehouse']]);
    } elseif ($kind === 'so_reject') {
        $orderExceptions->reject(pick($admins)['auth'], $data['id'], pick($rejectReasons));
    } elseif ($kind === 'so_approve') {
        $admin = pick($admins);
        $so->approve($admin['auth'], $data['id']);
        $requestAudit($admin, '/sales-orders/approve', $data['id']);
        $schedule($time->modify('+' . between(3, 72) . ' hour'), 'so_issue', ['id' => $data['id'], 'warehouse' => $data['warehouse']]);
    } elseif ($kind === 'so_issue') {
        $order = $salesRepository->findById($data['id']) ?? throw new RuntimeException('SO missing.');
        $enough = array_reduce($order->items(), static fn (bool $ok, $item): bool => $ok && $quantity($item->productId(), $data['warehouse']) >= $item->quantity(), true);
        if (!$enough) {
            $counts['issue_skipped']++; // stays Approved, waiting for replenishment, as in a real backlog

            continue;
        }
        $so->issue($staffFor($data['warehouse'])['auth'], $data['id']);
    }
}

// Master data was set up before the window; leave the session clock at the dataset's "now".
at($pdo, $end);
$summary = $counts;
foreach (['users', 'warehouses', 'categories', 'suppliers', 'customers', 'products', 'product_stocks', 'purchase_orders', 'purchase_order_items', 'sales_orders', 'sales_order_items', 'stock_ledger', 'audit_logs', 'purchase_order_closures', 'sales_order_rejections'] as $table) {
    $summary['rows'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
foreach (['purchase_orders', 'sales_orders'] as $table) {
    $summary['status'][$table] = $pdo->query("SELECT status, COUNT(*) FROM `$table` GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$summary['ledger_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)")->fetchColumn();
$summary['negative_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks WHERE quantity < 0')->fetchColumn();
$summary['low_stock_pairs'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id WHERE ps.quantity < p.reorder_point')->fetchColumn();
$summary['date_range'] = $pdo->query('SELECT MIN(order_date), MAX(order_date) FROM (SELECT order_date FROM purchase_orders UNION ALL SELECT order_date FROM sales_orders) d')->fetch(PDO::FETCH_NUM);
echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
exit($summary['ledger_mismatches'] === 0 && $summary['negative_stock'] === 0 ? 0 : 1);
