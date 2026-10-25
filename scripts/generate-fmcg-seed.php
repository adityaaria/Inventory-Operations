<?php

declare(strict_types=1);

// Builds the FMCG demo seed: master data, 500 SKUs in 3 warehouses and 1,000 PO/SO documents spread over six months.
// Every stock change goes through the real services (StockService transaction + ledger + audit). `SET TIMESTAMP`
// moves the MySQL session clock to each event, so CURRENT_DATE/NOW()/CURRENT_TIMESTAMP give historical dates.
// Run only through scripts/build-fmcg-seed.py: it drops every table of the disposable *_test database first.

use App\Entity\User;
use App\Repository\MySql\MySqlAuditLogRepository;
use App\Repository\MySql\MySqlDocumentSequenceRepository;
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
// D-17 / ADR-023: food and beverage categories track expiry; shelf life in days per category (null = no expiry date).
// Detergent and personal care carry long expiry dates although they do not track expiry; baby care has none.
const FOOD_CATEGORIES = ['MIN', 'KTE', 'SUS', 'MIE', 'SNK', 'BIS', 'BMB', 'SBK'];
const SHELF_LIFE_DAYS = ['MIN' => 300, 'KTE' => 365, 'SUS' => 150, 'MIE' => 240, 'SNK' => 150, 'BIS' => 270, 'BMB' => 365, 'SBK' => 300, 'PDR' => 900, 'PRT' => 900, 'KES' => 540, 'BYI' => null];

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
$customerBuys = [];
$usedNames = [];
for ($i = 0; $i < 66; $i++) {
    $region = $i % 3 + 1;
    [$city, $area] = pick($cities[$region]);
    do {
        $name = pick($outletTypes) . ' ' . pick($outletNames) . ' ' . $city;
    } while (isset($usedNames[$name]));
    $usedNames[$name] = true;
    $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $name));
    $customerId = insert($pdo, 'customers', [
        'name' => $name, 'email' => 'purchasing@' . substr($slug, 0, 28) . '.test',
        'phone' => $area . '-' . between(500, 899) . '-' . between(1000, 9999),
        'address' => pick($streets) . ' No. ' . between(1, 250) . ', ' . $city, 'is_active' => 1,
    ]);
    $customers[$region][] = $customerId;
    // Units (D-16, ADR-022): wholesale outlets (Grosir, CV) buy cartons, minimarkets and shops buy pieces or boxes,
    // supermarkets mix both. Derived from the name, so the customer table is unchanged.
    $customerBuys[$customerId] = match (strtok($name, ' ')) { 'Grosir', 'CV' => 'karton', 'Swalayan' => 'mixed', default => 'retail' };
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
// Units of measure (D-16, ADR-022): stock is kept in the smallest unit (pcs, or pak for diapers sold by the bal);
// karton (or bal) is the purchase and default sales unit with its own master prices, box/renceng are inner packs whose
// prices follow base price × factor. Every unit gets an EAN-13-like barcode with a valid check digit (prefix 899).
$unitRepository = new App\Repository\MySql\MySqlProductUnitRepository($pdo);
$unitCatalog = new App\Service\ProductUnitCatalog($productRepository, $unitRepository);
$unitService = new App\Service\ProductUnitService($productRepository, $unitRepository, new MasterDataAuthorizationService(), new MySqlAuditLogRepository($pdo), new MySqlTransactionManager($pdo));
$barcode = static function (int $productIndex, int $unitIndex): string {
    $twelve = '899' . sprintf('%04d', 1000 + $productIndex) . sprintf('%05d', 20261 + $unitIndex * 7);
    return $twelve . App\Service\ProductUnitService::ean13CheckDigit($twelve);
};
$catalog = [];
$sequence = [];
foreach ($chosen as $index => $item) {
    at($pdo, $setup->modify('+' . intdiv($index, 20) . ' day')->setTime(9 + $index % 8, ($index * 7) % 60));
    $sequence[$item['code']] = ($sequence[$item['code']] ?? 0) + 1;
    // Prices per carton (pack) as before; reorder point in cartons, stored in the base unit.
    $purchase = round($item['pack'] * $item['piece'] * (0.96 + mt_rand(0, 8) / 100), -2);
    $selling = round($purchase * (1.09 + mt_rand(0, 13) / 100), -2);
    $reorder = [1 => between(4, 10), 2 => between(10, 25), 3 => between(25, 60)][$item['velocity']];
    $pack = $item['pack'];
    $base = $pack > 1 ? ($item['unit'] === 'bal' ? 'pak' : 'pcs') : $item['unit'];
    // Base prices: purchase = carton / pack; selling per piece carries a 5% retail premium over the carton price.
    $basePurchase = round($purchase / $pack, 2);
    $baseSelling = $pack > 1 ? round(ceil($selling / $pack * 1.05), 2) : $selling;
    $product = $productService->create($admins[0]['auth'], new ProductInput(
        sprintf('%s-%04d', $item['code'], $sequence[$item['code']]), $item['name'], $base, $basePurchase, $baseSelling, $reorder * $pack, $categories[$item['code']],
        in_array($item['code'], FOOD_CATEGORIES, true), // D-17 / ADR-023: food and beverage products track expiry
    ));
    $baseUnit = $unitRepository->forProduct($product->id())[0];
    $unitService->update($admins[0]['auth'], $product->id(), $baseUnit->id(), ['barcode' => $barcode($index, 0), 'is_purchase_default' => $pack === 1 ? '1' : '', 'is_sales_default' => $pack === 1 ? '1' : '']);
    $unitIndex = 1;
    if ($pack % 12 === 0 && $pack >= 24) {
        $unitService->add($admins[0]['auth'], $product->id(), ['unit_code' => 'box', 'factor_to_base' => '12', 'barcode' => $barcode($index, $unitIndex++)]);
    } elseif ($pack % 10 === 0 && $pack >= 40) {
        $unitService->add($admins[0]['auth'], $product->id(), ['unit_code' => 'renceng', 'factor_to_base' => '10', 'barcode' => $barcode($index, $unitIndex++)]);
    }
    if ($pack > 1) {
        $unitService->add($admins[0]['auth'], $product->id(), ['unit_code' => $item['unit'], 'factor_to_base' => (string) $pack, 'barcode' => $barcode($index, $unitIndex), 'purchase_price' => number_format($purchase, 2, '.', ''), 'selling_price' => number_format($selling, 2, '.', ''), 'is_purchase_default' => '1', 'is_sales_default' => '1']);
    }
    $inner = $pack % 12 === 0 && $pack >= 24 ? 'box' : ($pack % 10 === 0 && $pack >= 40 ? 'renceng' : null);
    $catalog[$product->id()] = $item + ['id' => $product->id(), 'supplier' => $brandSupplier[$item['brand']], 'reorder' => $reorder, 'carton' => $item['unit'], 'base' => $base, 'inner' => $inner, 'inner_factor' => $inner === 'box' ? 12 : ($inner === 'renceng' ? 10 : 1)];
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
// ---- Customer price lists and tax identities (D-19, ADR-025) -----------------------------------------------------------
// Set up by Admin after the catalogue, before the document window (no mt_rand call, so the order plan is unchanged).
// Grosir: carton tiers 97/95/93 % of the carton master price from 1/10/50 karton; Minimarket Modern (valid Apr–Dec):
// pieces 99 % from 1 and 97 % from 24, inner box 97 %; Toko Tradisional: pieces 99 % from 6 and 98 % from 12 for
// staples; Promo Ramadan 2026 (valid only until 30 April): 96 % on coffee and tea cartons for two supermarkets, who fall
// back to master prices afterwards. Tier prices are whole Rupiah and never below the cost per unit. The first three
// customers stay on master prices (tests and HTTP fixtures use them).
at($pdo, $setup->modify('+27 day'));
$customerRepository = new App\Repository\MySql\MySqlCustomerRepository($pdo);
$priceListRepository = new App\Repository\MySql\MySqlPriceListRepository($pdo);
$priceLists = new App\Service\PriceListService($priceListRepository, $customerRepository, $productRepository, $unitCatalog, new MySqlTransactionManager($pdo), new MySqlAuditLogRepository($pdo));
$listIds = [];
foreach ([
    'grosir' => ['Grosir', 'Harga grosir per karton dengan potongan volume (1 / 10 / 50 karton).', '2026-03-01', null],
    'minimarket' => ['Minimarket Modern', 'Harga eceran modern per pcs dan box untuk minimarket dan swalayan.', '2026-04-01', '2026-12-31'],
    'toko' => ['Toko Tradisional', 'Harga toko kelontong untuk sembako, bumbu, mi dan kebutuhan rumah tangga per pcs.', null, null],
    'promo' => ['Promo Ramadan 2026', 'Promo kopi dan teh per karton selama Ramadan; berlaku sampai 30 April 2026.', '2026-03-01', '2026-04-30'],
] as $key => [$name, $description, $from, $to]) {
    $listIds[$key] = $priceLists->create($admins[0]['auth'], ['name' => $name, 'description' => $description, 'is_active' => '1', 'valid_from' => $from ?? '', 'valid_to' => $to ?? '']);
}
$tierCents = static fn (int $masterCents, int $percent): int => intdiv($masterCents * $percent, 100 * 100) * 100; // whole Rupiah, rounded down
$addTier = static function (string $list, int $productId, string $unitCode, int $minimum, int $percent) use ($listIds, $priceListRepository, $productRepository, $unitCatalog, $tierCents): bool {
    $product = $productRepository->findById($productId) ?? throw new RuntimeException('Product missing.');
    $unit = $unitCatalog->forProduct($product)->find($unitCode);
    if ($unit === null || !$unit->isActive()) {
        return false;
    }
    $price = $tierCents((int) round($unit->unitSellingPrice($product) * 100), $percent);
    if ($price < $unit->costCents($product)) {
        return false; // the cost floor applies to price lists too
    }
    $priceListRepository->addItem($listIds[$list], $productId, $unitCode, $minimum, App\Support\TaxSettings::decimal($price));

    return true;
};
foreach ($catalog as $id => $item) {
    if ($item['pack'] > 1) {
        foreach ([[1, 97], [10, 95], [50, 93]] as [$minimum, $percent]) {
            $addTier('grosir', $id, $item['carton'], $minimum, $percent);
        }
    }
    if (in_array($item['code'], ['MIN', 'KTE', 'SUS', 'MIE', 'SNK', 'BIS'], true) && $item['pack'] > 1) {
        $addTier('minimarket', $id, $item['base'], 1, 99);
        $addTier('minimarket', $id, $item['base'], 24, 97);
        if ($item['inner'] !== null) {
            $addTier('minimarket', $id, $item['inner'], 1, 97);
        }
    }
    if (in_array($item['code'], ['SBK', 'BMB', 'MIE', 'PRT'], true) && $item['pack'] > 1) {
        $addTier('toko', $id, $item['base'], 6, 99);
        $addTier('toko', $id, $item['base'], 12, 98);
    }
    if ($item['code'] === 'KTE' && $item['pack'] > 1) {
        $addTier('promo', $id, $item['carton'], 1, 96);
    }
}
$promoCustomers = 0;
foreach ($customerRepository->all() as $customer) {
    if ($customer->id() <= 3) {
        continue;
    }
    $type = strtok($customer->name(), ' ');
    $list = match ($type) { 'Grosir', 'CV' => 'grosir', 'Minimarket', 'Swalayan' => 'minimarket', default => 'toko' };
    if ($type === 'Swalayan' && $promoCustomers < 2) {
        $list = 'promo';
        $promoCustomers++;
    }
    $priceLists->assign($admins[0]['auth'], $customer->id(), $listIds[$list]);
    // Tax identity: companies have an NPWP (16 digits since 2024), a few small shops a NIK, the rest none.
    if (in_array($type, ['Grosir', 'CV', 'Swalayan', 'Minimarket'], true)) {
        $customerRepository->updateTaxProfile($customer->id(), sprintf('0%03d%012d', $customer->id(), 731050000000 + $customer->id() * 7919), null, 'PT ' . substr($customer->name(), strlen($type) + 1), $customer->address());
    } elseif ($customer->id() % 4 === 0) {
        $customerRepository->updateTaxProfile($customer->id(), null, sprintf('3171%012d', 140000000000 + $customer->id() * 104729), null, null);
    }
}

$customerEntities = [];
foreach ($customerRepository->all() as $customer) { $customerEntities[$customer->id()] = $customer; }
$purchaseRepository = new MySqlPurchaseOrderRepository($pdo);
$salesRepository = new MySqlSalesOrderRepository($pdo);
$exceptions = new MySqlOrderExceptionRepository($pdo);
$audit = new MySqlAuditLogRepository($pdo);
// Order numbers come from the same server sequence as the app (D-11, ADR-017); SET TIMESTAMP gives the YYMM.
$sequences = new MySqlDocumentSequenceRepository($pdo);
$po = new PurchaseOrderService($purchaseRepository, $sequences, $productRepository, $supplierEntities, $warehouseEntities, $stock, null, $exceptions, new App\Service\OrderLinePricing(), $unitCatalog);
// The exceptions repository lets the service refuse issues after a closed remainder (D-14, ADR-020).
// Price lists, quotations, delivery notes and invoices with PPN (D-19, ADR-025): the same services as the app. Every
// shipment creates its delivery note in the issue transaction.
$priceResolver = new App\Service\PriceResolver($priceListRepository, $productRepository, $unitCatalog);
$deliveryNoteRepository = new App\Repository\MySql\MySqlDeliveryNoteRepository($pdo);
$deliveryNotes = new App\Service\DeliveryNoteService($deliveryNoteRepository, $sequences, $customerRepository, new MySqlAuditLogRepository($pdo));
$so = new SalesOrderService($salesRepository, $sequences, $productRepository, $customerEntities, $warehouseEntities, $stock, null, new App\Service\OrderLinePricing(), $exceptions, $unitCatalog, $priceResolver, $deliveryNotes);
$quotations = new App\Service\QuotationService(new App\Repository\MySql\MySqlQuotationRepository($pdo), $sequences, $productRepository, $customerRepository,
    new App\Repository\MySql\MySqlWarehouseRepository($pdo), $so, new MySqlTransactionManager($pdo), $unitCatalog, new App\Service\OrderLinePricing(), $priceResolver, new MySqlAuditLogRepository($pdo));
$invoiceService = new App\Service\InvoiceService(new App\Repository\MySql\MySqlInvoiceRepository($pdo), $deliveryNoteRepository, $salesRepository, $customerRepository, $sequences,
    new MySqlTransactionManager($pdo), new App\Support\TaxSettings(), new MySqlAuditLogRepository($pdo));
// Vehicles and drivers per warehouse region, printed on the surat jalan.
$fleet = [1 => [['B 9123 KXT', 'Slamet Riyadi'], ['B 9481 PQA', 'Dedi Kurniawan'], ['B 9077 UVW', 'Rahmat Hidayat']],
    2 => [['L 8812 AB', 'Bambang Sugiarto'], ['L 9230 CD', 'Eko Prasetyo']], 3 => [['DD 8123 KL', 'Arifin Tahir'], ['DD 8456 MN', 'Syamsul Bahri']]];
$businessDate = static fn (DateTimeImmutable $time): string => $time->setTimezone(new DateTimeZone(App\Support\BusinessDate::DEFAULT_TIMEZONE))->format('Y-m-d');
$orderExceptions = new OrderExceptionService($exceptions, $purchaseRepository, $salesRepository, $stock, $audit);
$stockRepository = new MySqlStockRepository($pdo);
// Transfers with goods in transit (D-15, ADR-021) go through the same service as the app: propose → approve → dispatch → receive.
$operations = new App\Service\BusinessOperationService(new App\Repository\MySql\MySqlBusinessOperationRepository($pdo), $stock, $audit, $unitCatalog, $productRepository);
// Master price per unit (D-16): own unit price, else base price × factor; cost floor per unit = base purchase × factor.
$unitPrices = static function (int $productId, string $unitCode) use ($productRepository, $unitCatalog): array {
    $product = $productRepository->findById($productId) ?? throw new RuntimeException('Product missing.');
    $unit = $unitCatalog->forProduct($product)->resolve($unitCode, $product->sku());
    return ['purchase' => (int) round($unit->unitPurchasePrice($product) * 100), 'selling' => (int) round($unit->unitSellingPrice($product) * 100), 'cost' => $unit->costCents($product), 'factor' => $unit->factor()];
};

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
    // The new time wins over a 'time' carried in $data from the previous step (follow-up events of transfers, write-offs
    // and counts pass their data on); before 2026-10-12 those steps ran with the clock of the first step.
    $events->insert([$kind, ['time' => $time] + $data], [-$time->getTimestamp(), -$seq++]);

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

// Inter-warehouse transfers (D-15, ADR-021): one rebalancing transfer about every four days, at fixed business times
// (no mt_rand call, so the order plan above is unchanged). The last three are still open at the end of the window:
// two are in transit (their receipt is planned after the end) and the very last is approved but not yet dispatched.
const TRANSFERS = 40;
for ($t = 0; $t < TRANSFERS; $t++) {
    $time = $start->modify('+' . (20 + 4 * $t) . ' day')->setTime(9 + $t % 6, ($t * 13) % 60);
    if ((int) $time->format('N') === 7) {
        $time = $time->modify('+1 day');
    }
    $source = $warehouses[$t % 3];
    $schedule($time, 'tr', ['t' => $t, 'source' => $source, 'destination' => $warehouses[($t % 3 + 1 + intdiv($t, 3) % 2) % 3]]);
}

// Expired stock write-off (D-17, ADR-023): on the first working day of each month warehouse staff propose writing off
// every expired lot of their warehouse; another Admin approves and the staff posts it the same day.
for ($month = 1; $month <= 6; $month++) {
    foreach ($warehouses as $index => $warehouse) {
        $schedule((new DateTimeImmutable('2026-' . sprintf('%02d', 4 + $month) . '-01 09:00:00', $utc))->modify('+' . $index . ' hour'), 'wo', ['warehouse' => $warehouse, 'n' => $month * 3 + $index]);
    }
}

// Stock count sessions (D-18, ADR-024): three posted counts with small variances (one with a recount, one with a found
// lot) and one count still being counted at the end of the window. Fixed times, no mt_rand call, so the order plan above
// is unchanged.
$countPlans = [
    ['w' => 0, 'category' => 'KES', 'at' => '2026-08-14 09:10:00', 'plan' => 'posted'],
    ['w' => 1, 'category' => 'BIS', 'at' => '2026-09-11 09:20:00', 'plan' => 'recount'],
    ['w' => 2, 'category' => 'PDR', 'at' => '2026-09-25 09:30:00', 'plan' => 'found'],
    ['w' => 0, 'category' => 'BMB', 'at' => '2026-10-07 09:15:00', 'plan' => 'open'],
];
foreach ($countPlans as $n => $countPlan) {
    $schedule(new DateTimeImmutable($countPlan['at'], $utc), 'cnt_create', $countPlan + ['n' => $n, 'warehouse' => $warehouses[$countPlan['w']]]);
}

// Quotations outside the order flow (D-19, ADR-025): fixed times, no mt_rand call. Plans: draft left open, sent without
// answer (both Expired once valid_until passes), sent and rejected, draft cancelled, sent and accepted but never converted
// (validity ends), sent → accepted → converted to an SO. Four recent ones stay Draft, Sent and Accepted at the end.
$quotationPlans = ['draft', 'sent', 'rejected', 'cancelled', 'accepted', 'converted'];
for ($q = 0; $q < 30; $q++) {
    $schedule($start->modify('+' . (12 + 5 * $q) . ' day')->setTime(9 + $q % 7, ($q * 11) % 60), 'qt', ['q' => $q, 'plan' => $quotationPlans[$q % 6]]);
}
foreach (['draft', 'sent', 'accepted', 'sent'] as $offset => $plan) {
    $schedule($end->modify('-' . (4 - $offset) . ' day')->setTime(9 + $offset, 15), 'qt', ['q' => 30 + $offset, 'plan' => $plan, 'recent' => true]);
}

// ---- Run the events in time order ----------------------------------------------------------------------------------
$quantity = static fn (int $productId, int $warehouse): int => $stockRepository->quantity($productId, $warehouse);
$staffFor = static fn (int $warehouse): array => pick($byRole(User::ROLE_WAREHOUSE_STAFF, array_search($warehouse, $warehouses, true) + 1));
$countService = new App\Service\StockCountService(new App\Repository\MySql\MySqlStockCountRepository($pdo), $stock, $stockRepository, $audit, $productRepository, new App\Repository\MySql\MySqlUserRepository($pdo),
    new App\Repository\MySql\MySqlWarehouseRepository($pdo), new App\Repository\MySql\MySqlCategoryRepository($pdo), $unitCatalog);
$countRepository = new App\Repository\MySql\MySqlStockCountRepository($pdo);
$countReasons = ['posted' => 'Stock opname bulanan kategori kesehatan (cycle count).', 'recount' => 'Cycle count biskuit dan wafer setelah keluhan selisih pengiriman.', 'found' => 'Stock opname perawatan diri sebelum audit kuartal.', 'open' => 'Stock opname bumbu dan saus akhir minggu.'];
$counts = ['quotations' => 0, 'quotations_converted' => 0, 'so_from_quotation' => 0, 'invoices' => 0, 'invoices_voided' => 0, 'payments' => 0, 'po' => 0, 'so' => 0, 'approve_short' => 0, 'issue_skipped' => 0, 'so_shipments' => 0, 'so_split_orders' => 0, 'so_remainder_closed' => 0,
    'receipt_lines_split' => 0, 'issue_blocked_expired' => 0, 'write_offs_proposed' => 0, 'write_offs_posted' => 0, 'write_offs_refused' => 0, 'written_off_lots' => 0,
    'counts_created' => 0, 'counts_posted' => 0, 'count_post_refused' => 0, 'count_lines_with_variance' => 0,
    'transfers' => 0, 'transfer_skipped' => 0, 'transfer_dispatched' => 0, 'transfer_dispatch_refused' => 0, 'transfer_received' => 0, 'transfer_received_with_damage' => 0, 'transfer_received_short' => 0];
$staffAt = static fn (int $warehouse, int $n): array => ($list = $byRole(User::ROLE_WAREHOUSE_STAFF, array_search($warehouse, $warehouses, true) + 1))[$n % count($list)];
$damageReasons = ['Kemasan penyok dan bocor saat bongkar muat di gudang tujuan.', 'Karton basah terkena hujan selama perjalanan; isi tidak layak jual.'];
$shortReasons = ['Selisih hitung saat penerimaan; satu karton tidak ada di truk.', 'Jumlah fisik kurang dari surat jalan; sopir sudah membuat berita acara.'];
// Partial fulfilment (D-14, ADR-020): about one approved order in four ships in 2–3 shipments, chosen from the order id
// (no extra mt_rand call). Shipments after the end of the window simply do not happen, so recent split orders stay
// PartiallyFulfilled; a few split orders have their remainder closed by Admin with a reason instead of the last shipment.
$splitShipments = static fn (int $orderId): int => $orderId % 4 === 1 ? ($orderId % 3 === 0 ? 3 : 2) : 1;
$closesRemainder = static fn (int $orderId): bool => $orderId % 4 === 1 && $orderId % 7 === 3;
$soCloseReasons = ['Pelanggan menolak sisa barang karena sudah membeli dari distributor lain.', 'Sisa pesanan dibatalkan pelanggan; periode promo toko sudah berakhir.', 'Toko tutup untuk renovasi; sisa pengiriman tidak dilanjutkan.'];
$rejectReasons = ['Limit kredit pelanggan terlampaui; menunggu pelunasan tagihan sebelumnya.', 'Harga dan jumlah tidak sesuai kesepakatan kunjungan sales.', 'Pelanggan membatalkan pesanan melalui telepon.', 'Duplikat pesanan dengan dokumen sebelumnya.'];
// Negotiated line prices (D-13, ADR-019): a small, deterministic share of lines deviates from the master price with a
// reason. Chosen from document counters (no mt_rand call), so the rest of the history is the same as without them.
$soDiscountReasons = ['Promo akhir bulan', 'Volume order > 50 karton', 'Pelanggan grosir lama, harga sesuai kesepakatan kunjungan sales', 'Program diskon toko baru selama bulan pertama'];
$poNegotiationReasons = ['Diskon principal untuk pembelian volume besar', 'Harga promo principal bulan ini sesuai surat penawaran', 'Kesepakatan harga kontrak triwulan dengan principal'];
// D-20 / ADR-026: supplier price changes over time, so receipts create different cost layers (lot unit costs) and
// margins vary. Principals announce price letters: from June a 3 % (supplier id % 3 = 0) or 1.5 % (id % 3 = 1) increase,
// from August a further 2.5 % for even supplier ids, and a 2 % July promotion for supplier id % 5 = 1. PO lines are
// priced at the letter (negotiated price with the letter as reason, ADR-019); the master purchase price stays the
// catalogue price (the cost floor and price lists keep referring to it). Deterministic: no mt_rand call.
$supplierPriceLetter = static function (int $supplier, DateTimeImmutable $time): array {
    $month = $time->format('Y-m');
    $permille = 1000;
    $notes = [];
    if ($month >= '2026-06' && $supplier % 3 !== 2) {
        $permille = intdiv($permille * ($supplier % 3 === 0 ? 1030 : 1015) + 500, 1000);
        $notes[] = 'naik ' . ($supplier % 3 === 0 ? '3' : '1,5') . '% per Juni 2026';
    }
    if ($month >= '2026-08' && $supplier % 2 === 0) {
        $permille = intdiv($permille * 1025 + 500, 1000);
        $notes[] = 'naik 2,5% per Agustus 2026';
    }
    if ($month === '2026-07' && $supplier % 5 === 1) {
        $permille = intdiv($permille * 980 + 500, 1000);
        $notes[] = 'promo principal Juli 2026 -2%';
    }

    return [$permille, $notes === [] ? null : 'Surat harga principal: ' . implode(', ', $notes)];
};
$supplierLetterLines = 0;
$money = static fn (int $cents): string => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
$negotiate = static function (array &$line, int $priceCents, string $reason) use ($money): void {
    $line['price'] = $money($priceCents);
    $line['price_reason'] = $reason;
};
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
            // Purchasing thinks in cartons (the purchase default unit); stock is counted in the base unit.
            $current = intdiv($quantity($productId, $warehouse), $item['pack']);
            if ($data['opening']) {
                $lines[] = ['product_id' => $productId, 'quantity' => $item['reorder'] * between(3, 6), 'unit_code' => $item['carton']];
            } elseif ($current < $item['reorder'] && chance(0.7)) {
                $lines[] = ['product_id' => $productId, 'quantity' => max($item['reorder'], (int) (ceil(($item['reorder'] * 4 - $current) / 5) * 5)), 'unit_code' => $item['carton']];
            }
        }
        if (!$data['opening'] && count($lines) < 3) {
            shuffle($range);
            foreach (array_slice($range, 0, between(3, 8)) as $productId) {
                if (!in_array($productId, array_column($lines, 'product_id'), true)) {
                    $lines[] = ['product_id' => $productId, 'quantity' => $catalog[$productId]['reorder'] * between(2, 4), 'unit_code' => $catalog[$productId]['carton']];
                }
            }
        }
        $lines = array_slice($lines, 0, $data['opening'] ? 60 : 18);
        if (!$data['opening'] && $counts['po'] % 45 === 21) {
            // Negotiated supplier price: 2–4% below the master purchase price on the first one or two lines.
            foreach (array_slice(array_keys($lines), 0, 1 + $counts['po'] % 2) as $offset => $key) {
                $master = $unitPrices($lines[$key]['product_id'], $lines[$key]['unit_code'])['purchase']; // per carton
                $price = intdiv($master * (98 - ($counts['po'] + $offset) % 3), 100 * 100) * 100; // whole Rupiah
                if ($price > 0 && $price !== $master) {
                    $negotiate($lines[$key], $price, $poNegotiationReasons[($counts['po'] + $offset) % count($poNegotiationReasons)]);
                }
            }
        }
        [$letterPermille, $letterReason] = $supplierPriceLetter($data['supplier'], $time);
        if (!$data['opening'] && $letterReason !== null && $letterPermille !== 1000) {
            foreach ($lines as $key => $line) {
                if (isset($line['price'])) {
                    continue; // a negotiated volume price stays as agreed
                }
                $master = $unitPrices($line['product_id'], $line['unit_code'])['purchase']; // per carton, cents
                $price = intdiv($master * $letterPermille + 50000, 100000) * 100; // whole Rupiah, half up
                if ($price > 0 && $price !== $master) {
                    $negotiate($lines[$key], $price, $letterReason);
                    $supplierLetterLines++;
                }
            }
        }
        $creator = chance(0.75) ? $staffFor($warehouse) : pick($admins);
        $order = $po->createDraft($creator['auth'], $data['supplier'], $warehouse, $lines);
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
                // Receipts are entered in the line unit (cartons), D-16.
                $receipt[$item->id()] = $data['partial'] ? max(1, (int) round($item->remainingUnitQuantity() * between(40, 80) / 100)) : $item->remainingUnitQuantity();
            }
        }
        if ($data['partial'] && count($receipt) > 1) {
            unset($receipt[array_key_last($receipt)]); // one line short on the first delivery
        }
        // Lots (D-17, ADR-023): the delivery carries one batch per line, sometimes two (an older and a newer
        // production date). Expiry = production date + shelf life of the category (± 15 % per product); production is
        // 5–40 days before the delivery. Chosen from ids and counters (no extra mt_rand call).
        $lots = [];
        foreach ($order->items() as $line) {
            if (!isset($receipt[$line->id()])) {
                continue;
            }
            $item = $catalog[$line->productId()];
            $shelf = SHELF_LIFE_DAYS[$item['code']];
            $made = $time->modify('-' . (5 + ($line->productId() * 7 + $data['id']) % 36) . ' day');
            $expiry = static fn (DateTimeImmutable $made): ?string => $shelf === null ? null : $made->modify('+' . (int) round($shelf * (85 + $line->productId() % 31) / 100) . ' day')->format('Y-m-d');
            $code = static fn (DateTimeImmutable $made): string => 'L' . $made->format('ymd') . '-' . sprintf('%03d', $line->productId() % 1000);
            $units = $receipt[$line->id()];
            if ($units >= 2 && ($line->productId() + $data['id']) % 5 === 0) {
                $older = $made->modify('-' . (10 + $line->productId() % 11) . ' day');
                $lots[$line->id()] = [
                    ['lot_code' => $code($older), 'expiry_date' => $expiry($older), 'quantity' => intdiv($units, 2)],
                    ['lot_code' => $code($made), 'expiry_date' => $expiry($made), 'quantity' => $units - intdiv($units, 2)],
                ];
                $counts['receipt_lines_split']++;
            } else {
                $lots[$line->id()] = [['lot_code' => $code($made), 'expiry_date' => $expiry($made), 'quantity' => $units]];
            }
        }
        $po->receive($staffFor($data['warehouse'])['auth'], $data['id'], $receipt, null, $lots);
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
        // Sellable stock only: on hand minus what Approved orders already reserved (D-12, ADR-018).
        // D-17: expired lots are not sellable, so they never count as available.
        $inStock = $pdo->prepare('SELECT ps.product_id, ' . App\Repository\MySql\LowStockSql::available() . ' AS available FROM product_stocks ps WHERE ps.warehouse_id = ? HAVING available > 0');
        $inStock->execute([$warehouse]);
        $onHand = array_map('intval', $inStock->fetchAll(PDO::FETCH_KEY_PAIR));
        $available = array_keys($onHand);
        if ($available === []) {
            continue;
        }
        shuffle($available);
        $lines = [];
        $buys = $customerBuys[$customer];
        foreach (array_slice($available, 0, between(2, 9)) as $index => $productId) {
            $item = $catalog[$productId];
            $cap = [1 => 8, 2 => 25, 3 => 60][$item['velocity']];
            $cartons = intdiv((int) ($onHand[$productId] * 0.35), $item['pack']);
            // Demand in cartons as before; wholesale orders it in cartons, retail in inner boxes (same quantity) or loose
            // pieces (a third of it), supermarkets alternate (D-16).
            $wanted = max(1, min($cap, between(1, max(1, $cartons))));
            $wantsCartons = $item['pack'] === 1 || $buys === 'karton' || ($buys === 'mixed' && ($customer + $index) % 2 === 0);
            if ($wantsCartons && $cartons >= 1) {
                $lines[] = ['product_id' => $productId, 'quantity' => $wanted, 'unit_code' => $item['carton']];
            } elseif ($item['inner'] !== null && $cartons >= 1 && ($customer + $index) % 3 !== 0) {
                $lines[] = ['product_id' => $productId, 'quantity' => max(1, intdiv($wanted * $item['pack'], $item['inner_factor'])), 'unit_code' => $item['inner']];
            } else {
                $lines[] = ['product_id' => $productId, 'quantity' => max(1, min(intdiv($wanted * $item['pack'], 3), (int) ($onHand[$productId] * 0.35))), 'unit_code' => $item['base']];
            }
        }
        $prices = $unitPrices($lines[0]['product_id'], $lines[0]['unit_code']);
        // D-19: discounts are negotiated against the customer's list price (price list tier, else master).
        $firstProduct = $productRepository->findById($lines[0]['product_id']) ?? throw new RuntimeException('Product missing.');
        $master = $priceResolver->resolve($customer, $firstProduct, $unitCatalog->forProduct($firstProduct)->resolve($lines[0]['unit_code'], $firstProduct->sku()), $lines[0]['quantity'])['price_cents'];
        $cost = $prices['cost'];
        if ($counts['so'] % 40 === 13) {
            // Sales discount of 3–8% (cap 10%), whole Rupiah, never below the purchase price.
            $price = max($cost, intdiv($master * (100 - (3 + $counts['so'] % 6)) + 9999, 100 * 100) * 100);
            if ($price !== $master) {
                $negotiate($lines[0], $price, $soDiscountReasons[intdiv($counts['so'], 40) % count($soDiscountReasons)]);
            }
        } elseif ($counts['so'] === 101) {
            // One line above master: express delivery surcharge agreed with the customer.
            $negotiate($lines[0], intdiv($master * 105 + 9999, 100 * 100) * 100, 'Biaya pengiriman ekspres ke luar kota disepakati pelanggan');
        }
        if ($counts['so'] % 15 === 4 && !isset($lines[0]['price'])) {
            // About one order in fifteen starts as a quotation (D-19): sent, accepted the next day and converted to the SO
            // draft at the quoted prices, which then follows the normal flow. The same mt_rand calls as a direct order.
            $quotationId = $quotations->create($sales['auth'], $customer, $warehouse, null, 'Penawaran harga atas permintaan toko.', $lines);
            $requestAudit($sales, '/quotations', null);
            $counts['so']++;
            $counts['quotations']++;
            $cancelled = chance(0.04);
            $delay = $cancelled ? between(1, 6) * 60 : between(10, 600);
            $schedule($time->modify('+' . $delay . ' minute'), 'qt_send', ['id' => $quotationId, 'sales' => $sales, 'warehouse' => $warehouse, 'convert' => !$cancelled]);
            continue;
        }
        $order = $so->createDraft($sales['auth'], $customer, $warehouse, $lines);
        $requestAudit($sales, '/sales-orders', null);
        $counts['so']++;
        if (chance(0.04)) {
            $schedule($time->modify('+' . between(1, 6) . ' hour'), 'so_cancel', ['id' => $order->id()]);
            continue;
        }
        $schedule($time->modify('+' . between(10, 600) . ' minute'), 'so_submit', ['id' => $order->id(), 'sales' => $sales, 'warehouse' => $warehouse]);
    } elseif ($kind === 'so_cancel') {
        $admin = pick($admins); // only Admin may cancel a sales order
        $so->rejectOrCancel($admin['auth'], $data['id']); // audited atomically by the service (D-12)
    } elseif ($kind === 'so_submit') {
        $so->submit($data['sales']['auth'], $data['id']);
        $requestAudit($data['sales'], '/sales-orders/submit', $data['id']);
        $reviewAt = $time->modify('+' . between(1, 50) . ' hour');
        $schedule($reviewAt, chance(0.05) ? 'so_reject' : 'so_approve', ['id' => $data['id'], 'warehouse' => $data['warehouse']]);
    } elseif ($kind === 'so_reject') {
        $orderExceptions->reject(pick($admins)['auth'], $data['id'], pick($rejectReasons));
    } elseif ($kind === 'so_approve') {
        $admin = pick($admins);
        try {
            // Approval reserves the lines (D-12); the service writes the approval audit with the reserved lines.
            $so->approve($admin['auth'], $data['id']);
        } catch (App\Exception\ValidationException) {
            $counts['approve_short']++; // stays PendingApproval until stock arrives, as in a real backlog

            continue;
        }
        $schedule($time->modify('+' . between(3, 72) . ' hour'), 'so_issue', ['id' => $data['id'], 'warehouse' => $data['warehouse'], 'shipment' => 1, 'of' => $splitShipments($data['id'])]);
    } elseif ($kind === 'so_issue') {
        $order = $salesRepository->findById($data['id']) ?? throw new RuntimeException('SO missing.');
        // Approved orders hold a reservation, so their goods are always on hand; the check stays as a safeguard.
        $enough = array_reduce($order->items(), static fn (bool $ok, $item): bool => $ok && $quantity($item->productId(), $data['warehouse']) >= $item->remainingQuantity(), true);
        if (!$enough) {
            $counts['issue_skipped']++;

            continue;
        }
        $id = $data['id'];
        $last = $data['shipment'] >= $data['of'];
        $quantities = null; // the last shipment issues every remaining unit
        if (!$last) {
            // Part of each open line (45–75%, at least 1); on the first shipment the last open line waits entirely.
            $quantities = [];
            $open = array_values(array_filter($order->items(), static fn ($item): bool => $item->remainingUnitQuantity() > 0));
            foreach ($open as $index => $item) {
                $share = intdiv($item->remainingUnitQuantity() * (45 + ($id + $index * 7) % 31), 100); // line units (D-16)
                $quantities[$item->id()] = $data['shipment'] === 1 && count($open) > 1 && $index === count($open) - 1 ? 0 : max(1, $share);
            }
        }
        try {
            $region = array_search($data['warehouse'], $warehouses, true) + 1;
            [$vehicle, $driver] = $fleet[$region][($id + $data['shipment']) % count($fleet[$region])];
            $so->issue($staffFor($data['warehouse'])['auth'], $id, null, $quantities, ['vehicle' => $vehicle, 'driver' => $driver]);
        } catch (App\Exception\ValidationException) {
            $counts['issue_blocked_expired']++; // lots expired after approval: stays open, visible in the Expiry report

            continue;
        }
        $counts['so_shipments']++;
        // D-19: Admin invoices most delivery notes the next working day; about one in ten stays un-invoiced.
        $note = $pdo->query('SELECT MAX(id) FROM delivery_notes WHERE sales_order_id = ' . (int) $id)->fetchColumn();
        if ($note !== false && $note !== null && (int) $note % 10 !== 7) {
            $schedule($time->modify('+1 day')->setTime(9 + (int) $note % 6, ((int) $note * 13) % 60), 'inv_create', ['note' => (int) $note]);
        }
        $stillOpen = ($salesRepository->findById($id)?->remainingUnits() ?? 0) > 0; // tiny lines may complete early
        if ($data['shipment'] === 1 && !$last && $stillOpen) {
            $counts['so_split_orders']++;
        }
        if (!$last && $stillOpen) {
            $next = $time->modify('+' . (1 + ($id + $data['shipment']) % 4) . ' day')->setTime(8 + ($id + $data['shipment']) % 8, ($id * 7) % 60);
            if ($data['shipment'] + 1 === $data['of'] && $closesRemainder($id)) {
                $schedule($next, 'so_close', ['id' => $id]);
            } else {
                $schedule($next, 'so_issue', ['id' => $id, 'warehouse' => $data['warehouse'], 'shipment' => $data['shipment'] + 1, 'of' => $data['of']]);
            }
        }
    } elseif ($kind === 'tr') {
        // Rebalance: products plentiful (available) at the source and below twice the reorder point at the destination.
        $t = $data['t'];
        // Available excludes expired lots (D-17): an expired lot is never dispatched.
        $sourceAvailable = str_replace('ps.', 's.', App\Repository\MySql\LowStockSql::available());
        $destinationAvailable = str_replace('ps.', 'd.', App\Repository\MySql\LowStockSql::available());
        $pairs = $pdo->prepare('SELECT s.product_id, ' . $sourceAvailable . ' AS source_available, ' . $destinationAvailable . ' AS destination_available, p.reorder_point
            FROM product_stocks s JOIN product_stocks d ON d.product_id = s.product_id AND d.warehouse_id = ? JOIN products p ON p.id = s.product_id
            WHERE s.warehouse_id = ? AND ' . $sourceAvailable . ' > 3 * p.reorder_point
              AND ' . $destinationAvailable . ' < 2 * p.reorder_point
            ORDER BY (' . $destinationAvailable . ') / GREATEST(p.reorder_point, 1) ASC, s.product_id ASC LIMIT ' . (2 + $t % 3));
        $pairs->execute([$data['destination'], $data['source']]);
        $lines = [];
        foreach ($pairs->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $baseQuantity = max(1, min((int) $row['source_available'] - 2 * (int) $row['reorder_point'], 2 * (int) $row['reorder_point']));
            $item = $catalog[(int) $row['product_id']];
            // Whole cartons when at least one fits, otherwise pieces (D-16).
            $lines[] = $baseQuantity >= $item['pack'] && $item['pack'] > 1
                ? ['product_id' => (int) $row['product_id'], 'quantity' => intdiv($baseQuantity, $item['pack']), 'unit_code' => $item['carton']]
                : ['product_id' => (int) $row['product_id'], 'quantity' => $baseQuantity, 'unit_code' => $item['base']];
        }
        if ($lines === []) {
            $counts['transfer_skipped']++;

            continue;
        }
        $creator = $staffAt($data['source'], $t);
        $id = $operations->propose($creator['auth'], 'Transfer', $data['source'], $data['destination'], 'Pemerataan stok antar gudang: stok tersedia di gudang tujuan di bawah titik pesan ulang.', $lines);
        $counts['transfers']++;
        $schedule($time->modify('+' . (2 + $t % 18) . ' hour'), 'tr_approve', $data + ['id' => $id]);
    } elseif ($kind === 'tr_approve') {
        $operations->decide($admins[$data['t'] % count($admins)]['auth'], $data['id'], 'Approved', 'Disetujui: stok sumber mencukupi setelah reservasi pesanan penjualan.');
        if ($data['t'] < TRANSFERS - 1) { // the last transfer stays Approved (not yet dispatched)
            $schedule($time->modify('+' . (1 + $data['t'] % 2) . ' day')->setTime(8 + $data['t'] % 7, ($data['t'] * 17) % 60), 'tr_dispatch', $data);
        }
    } elseif ($kind === 'tr_dispatch') {
        try {
            $operations->dispatch($staffAt($data['source'], $data['t'] + 1)['auth'], $data['id']);
        } catch (App\Exception\ValidationException) {
            $counts['transfer_dispatch_refused']++; // reservations took the stock meanwhile; stays Approved

            continue;
        }
        $counts['transfer_dispatched']++;
        // Two transfers are still in transit at the end of the window: their receipt is planned after it.
        $days = $data['t'] >= TRANSFERS - 3 ? 30 : 1 + $data['t'] % 3;
        $schedule($time->modify('+' . $days . ' day')->setTime(9 + $data['t'] % 7, ($data['t'] * 23) % 60), 'tr_receive', $data);
    } elseif ($kind === 'tr_receive') {
        $t = $data['t'];
        $operation = $operations->show($admins[0]['auth'], $data['id']);
        $receipt = [];
        $reasons = [];
        foreach ($operation['items'] as $index => $item) {
            $dispatched = (int) $item['dispatched_quantity'];
            $damaged = $t % 5 === 2 && $index === 0 ? max(1, intdiv($dispatched, 10)) : 0;
            // Damage and shortage are counted in the base unit; a missing carton is one carton's worth of pieces.
            $carton = max(1, (int) ($item['unit_factor'] ?? 1));
            $short = $t % 7 === 4 && $index === count($operation['items']) - 1 && $dispatched - $damaged > $carton ? $carton : 0;
            $receipt[(int) $item['product_id']] = ['good' => $dispatched - $damaged - $short, 'damaged' => $damaged];
            if ($damaged > 0) { $reasons[0] = $damageReasons[$t % 2]; }
            if ($short > 0) { $reasons[1] = $shortReasons[$t % 2]; }
        }
        ksort($reasons);
        $operations->receive($staffAt($data['destination'], $t)['auth'], $data['id'], $receipt, $reasons === [] ? null : implode(' ', $reasons));
        $counts['transfer_received']++;
        $counts['transfer_received_with_damage'] += isset($reasons[0]) ? 1 : 0;
        $counts['transfer_received_short'] += isset($reasons[1]) ? 1 : 0;
    } elseif ($kind === 'wo') {
        try {
            $id = $operations->proposeExpiredWriteOff($staffAt($data['warehouse'], $data['n'])['auth'], $data['warehouse'], 'Pemusnahan stok kedaluwarsa bulanan sesuai berita acara gudang.');
        } catch (App\Exception\ValidationException) {
            continue; // nothing expired in this warehouse
        }
        $counts['write_offs_proposed']++;
        $operations->decide($admins[$data['n'] % count($admins)]['auth'], $id, 'Approved', 'Disetujui: lot kedaluwarsa sudah dipisahkan dan dicatat.');
        $schedule($time->modify('+3 hour'), 'wo_post', $data + ['id' => $id]);
    } elseif ($kind === 'wo_post') {
        try {
            $operations->post($staffAt($data['warehouse'], $data['n'] + 1)['auth'], $data['id']);
            $counts['write_offs_posted']++;
            $counts['written_off_lots'] += count($operations->show($admins[0]['auth'], $data['id'])['items']);
        } catch (App\Exception\ValidationException | App\Exception\HttpException) {
            $counts['write_offs_refused']++; // a reservation or a changed lot blocks it; stays Approved
        }
    } elseif ($kind === 'cnt_create') {
        // Admin creates the count for one category of one warehouse with both warehouse staff of that region as counters,
        // and starts it right away (snapshot of every lot with stock + ledger watermark).
        $staff = $byRole(User::ROLE_WAREHOUSE_STAFF, $data['w'] + 1);
        $creator = $admins[$data['n'] % count($admins)];
        $id = $countService->create($creator['auth'], ['warehouse_id' => (string) $data['warehouse'], 'scope' => 'Category', 'category_id' => (string) $categories[$data['category']],
            'counter_ids' => array_map(static fn (array $user): string => (string) $user['id'], $staff), 'reason' => $countReasons[$data['plan']]]);
        $countService->start($creator['auth'], $id);
        $counts['counts_created']++;
        $schedule($time->modify('+95 minutes'), 'cnt_count', $data + ['id' => $id, 'staff' => $staff]);
    } elseif ($kind === 'cnt_count') {
        // The first counter counts the lines: the physical quantity equals the system quantity except for a few small,
        // deterministic differences (−1 or +1 base unit; the recount plan has one shortfall just above the tolerance).
        $lines = $countRepository->lines($data['id']);
        $entries = [];
        $recountLine = null;
        foreach ($lines as $index => $line) {
            if ($data['plan'] === 'open' && $index % 2 === 1) {
                continue; // the open count is half done at the end of the window
            }
            $system = $stockRepository->findLot((int) $line['lot_id'])?->quantity() ?? 0;
            $available = $quantity((int) $line['product_id'], $data['warehouse']) - $stockRepository->reservedQuantity((int) $line['product_id'], $data['warehouse']);
            $variance = 0;
            if ($data['plan'] === 'recount' && $recountLine === null && $system >= 20 && $available >= intdiv($system * 2, 100) + 5) {
                // Just above the 2 % recount tolerance (default COUNT_RECOUNT_TOLERANCE_PERCENT).
                $variance = -(intdiv($system * 2, 100) + 2);
                $recountLine = (int) $line['id'];
            } elseif ($index % 17 === 3 && $system >= 3 && $available >= 4) {
                $variance = -1;
            } elseif ($index % 23 === 5) {
                $variance = 1;
            }
            $counts['count_lines_with_variance'] += $variance !== 0 ? 1 : 0;
            $entries[(int) $line['id']] = ['quantity' => (string) ($system + $variance), 'unit' => ''];
        }
        if ($entries !== []) {
            $countService->record($data['staff'][0]['auth'], $data['id'], $entries);
        }
        if ($data['plan'] === 'found') {
            // A carton without a known lot is found on the shelf: entered as a found lot, created when the count is posted.
            $countService->addFoundLot($data['staff'][0]['auth'], $data['id'], ['product_id' => (string) $lines[0]['product_id'], 'lot_code' => 'TEMUAN-0925-01', 'quantity' => '2', 'unit_code' => '']);
            $counts['count_lines_with_variance']++;
        }
        if ($data['plan'] === 'open') {
            continue;
        }
        $countService->submit($data['staff'][0]['auth'], $data['id']);
        $schedule($time->modify('+3 hour'), $data['plan'] === 'recount' ? 'cnt_recount_request' : 'cnt_approve', $data + ['recount_line' => $recountLine]);
    } elseif ($kind === 'cnt_recount_request') {
        $reviewer = $admins[($data['n'] + 1) % count($admins)];
        $countService->requestRecount($reviewer['auth'], $data['id'], [(string) $data['recount_line']], 'Selisih di atas toleransi; mohon hitung ulang oleh petugas lain.');
        $schedule($time->modify('+1 hour'), 'cnt_recount', $data);
    } elseif ($kind === 'cnt_recount') {
        // The second counter finds all but one of the missing units: the recount variance is −1.
        $line = array_values(array_filter($countRepository->lines($data['id']), static fn (array $row): bool => (int) $row['id'] === $data['recount_line']))[0];
        $system = $stockRepository->findLot((int) $line['lot_id'])?->quantity() ?? 0;
        $countService->record($data['staff'][1]['auth'], $data['id'], [$data['recount_line'] => ['quantity' => (string) ($system - 1), 'unit' => '']]);
        $countService->submit($data['staff'][1]['auth'], $data['id']);
        $schedule($time->modify('+2 hour'), 'cnt_approve', $data);
    } elseif ($kind === 'cnt_approve') {
        // Independent approval: an Admin who counted nothing (the counters are warehouse staff).
        $countService->approve($admins[($data['n'] + 2) % count($admins)]['auth'], $data['id'], 'Selisih kecil diterima; disesuaikan ke stok.');
        $schedule($time->modify('+1 hour'), 'cnt_post', $data);
    } elseif ($kind === 'cnt_post') {
        try {
            $countService->post($data['staff'][1]['auth'], $data['id']);
            $counts['counts_posted']++;
        } catch (App\Exception\ValidationException | App\Exception\HttpException) {
            $counts['count_post_refused']++; // a reservation took the stock meanwhile; stays Approved
        }
    } elseif ($kind === 'qt_send') {
        $quotations->send($data['sales']['auth'], $data['id']);
        if ($data['convert']) {
            $schedule($time->modify('+1 day')->setTime(10 + $data['id'] % 6, ($data['id'] * 7) % 60), 'qt_accept', $data);
        } else {
            $schedule($time->modify('+2 day'), 'qt_reject', $data);
        }
    } elseif ($kind === 'qt_accept') {
        $quotations->accept($data['sales']['auth'], $data['id']);
        $schedule($time->modify('+2 hour'), 'qt_convert', $data);
    } elseif ($kind === 'qt_reject') {
        $quotations->reject($data['sales']['auth'], $data['id'], 'Pelanggan memilih penawaran distributor lain.');
    } elseif ($kind === 'qt_convert') {
        $orderId = $quotations->convert($data['sales']['auth'], $data['id']);
        $counts['quotations_converted']++;
        $counts['so_from_quotation']++;
        $schedule($time->modify('+' . (30 + $data['id'] % 120) . ' minute'), 'so_submit', ['id' => $orderId, 'sales' => $data['sales'], 'warehouse' => $data['warehouse']]);
    } elseif ($kind === 'qt') {
        // A standalone quotation for a customer with a price list: 2–4 products in the customer's buying unit.
        $q = $data['q'];
        $region = $q % 3 + 1;
        $candidates = array_values(array_filter($customers[$region], static fn (int $customerId): bool => $customerId > 3));
        $customer = $candidates[$q % count($candidates)];
        $sales = $byRole(User::ROLE_SALES, $region)[$q % count($byRole(User::ROLE_SALES, $region))];
        $productIds = array_keys($catalog);
        $lines = [];
        for ($n = 0; $n < 2 + $q % 3; $n++) {
            $item = $catalog[$productIds[($q * 37 + $n * 101) % count($productIds)]];
            $unitCode = $customerBuys[$customer] === 'karton' || $item['pack'] === 1 ? $item['carton'] : $item['base'];
            $lines[] = ['product_id' => $item['id'], 'quantity' => $unitCode === $item['carton'] ? 5 + ($q + $n) % 30 : 12 * (1 + ($q + $n) % 4), 'unit_code' => $unitCode];
        }
        $id = $quotations->create($sales['auth'], $customer, $warehouses[$region - 1], null, $data['plan'] === 'converted' ? 'Penawaran proyek; harga berlaku 14 hari.' : '', $lines);
        $counts['quotations']++;
        $requestAudit($sales, '/quotations', null);
        $follow = ['draft' => null, 'cancelled' => 'qt_cancel', 'sent' => 'qt_only_send', 'rejected' => 'qt_only_send', 'accepted' => 'qt_only_send', 'converted' => 'qt_only_send'][$data['plan']];
        if ($follow !== null) {
            $schedule($time->modify('+3 hour'), $follow, $data + ['id' => $id, 'sales' => $sales, 'warehouse' => $warehouses[$region - 1]]);
        }
    } elseif ($kind === 'qt_cancel') {
        $quotations->cancel($data['sales']['auth'], $data['id']);
    } elseif ($kind === 'qt_only_send') {
        $quotations->send($data['sales']['auth'], $data['id']);
        if ($data['plan'] === 'rejected') {
            $schedule($time->modify('+2 day'), 'qt_reject', $data);
        } elseif (in_array($data['plan'], ['accepted', 'converted'], true)) {
            $schedule($time->modify('+1 day'), 'qt_only_accept', $data);
        }
    } elseif ($kind === 'qt_only_accept') {
        $quotations->accept($data['sales']['auth'], $data['id']);
        if ($data['plan'] === 'converted') {
            $schedule($time->modify('+1 hour'), 'qt_convert', $data);
        }
    } elseif ($kind === 'inv_create') {
        // Admin bills the delivery note; payment behaviour follows the invoice id (no mt_rand call).
        $admin = $admins[$data['note'] % count($admins)];
        $invoiceId = $invoiceService->createFromDeliveryNote($admin['auth'], $data['note']);
        $counts['invoices']++;
        $invoice = $invoiceService->show($admin['auth'], $invoiceId);
        $total = App\Support\TaxSettings::cents($invoice['total_amount']);
        if ($counts['invoices_voided'] === 0 && $invoiceId % 97 === 50) {
            // One invoice is voided (wrong customer tax identity) and the delivery note is billed again.
            $schedule($time->modify('+2 hour'), 'inv_void', ['id' => $invoiceId, 'note' => $data['note']]);
            $counts['invoices_voided']++;
        } elseif ($invoiceId % 120 === 17) {
            // Never paid: overdue after the payment terms.
        } elseif ($invoiceId % 120 === 33 || $invoiceId % 120 === 81) {
            $schedule($time->modify('+' . (9 + $invoiceId % 5) . ' day'), 'inv_pay', ['id' => $invoiceId, 'amount' => intdiv($total, 200) * 100, 'method' => 'Transfer']);
        } elseif ($invoiceId % 20 === 5) {
            $part = intdiv($total * 2, 500) * 100;
            $schedule($time->modify('+12 day'), 'inv_pay', ['id' => $invoiceId, 'amount' => $part, 'method' => 'Giro']);
            $schedule($time->modify('+40 day'), 'inv_pay', ['id' => $invoiceId, 'amount' => $total - $part, 'method' => 'Transfer']);
        } else {
            $schedule($time->modify('+' . (5 + $invoiceId % 25) . ' day'), 'inv_pay', ['id' => $invoiceId, 'amount' => $total, 'method' => $invoiceId % 9 === 0 ? 'Cash' : 'Transfer']);
        }
    } elseif ($kind === 'inv_void') {
        $invoiceService->void($admins[0]['auth'], $data['id'], 'NPWP pelanggan salah pada faktur; diterbitkan ulang dengan identitas pajak yang benar.');
        $schedule($time->modify('+1 hour'), 'inv_create', ['note' => $data['note']]);
    } elseif ($kind === 'inv_pay') {
        $invoiceService->recordPayment($admins[$data['id'] % count($admins)]['auth'], $data['id'], [
            'paid_on' => $businessDate($time), 'amount' => App\Support\TaxSettings::decimal($data['amount']), 'method' => $data['method'],
            'reference' => ($data['method'] === 'Giro' ? 'GR-' : 'TRF-') . $businessDate($time) . '-' . $data['id'],
        ]);
        $counts['payments']++;
    } elseif ($kind === 'so_close') {
        // Admin closes the unshipped remainder with a reason; the reservation is released (D-14, ADR-020).
        $so->closeRemaining(pick($admins)['auth'], $data['id'], $soCloseReasons[$data['id'] % count($soCloseReasons)]);
        $counts['so_remainder_closed']++;
    }
}

// Master data was set up before the window; leave the session clock at the dataset's "now".
at($pdo, $end);

// Per-warehouse reorder point overrides (D-10, ADR-016), set by Admin through the audited service after the history,
// so the document flow above is unchanged: the central Cikarang hub keeps a larger buffer for fast movers, and the
// Makassar regional warehouse a smaller one for slow movers. No stock or ledger row changes.
$reorderPoints = new App\Service\ReorderPointService($productRepository, new MasterDataAuthorizationService(), $audit, new MySqlTransactionManager($pdo));
$overrides = 0;
foreach ([[0, 3, 2.0, 10], [2, 1, 0.5, 5]] as [$warehouseIndex, $velocity, $factor, $limit]) {
    $picked = array_slice(array_values(array_filter($catalog, static fn (array $item): bool => $item['velocity'] === $velocity)), 0, $limit);
    foreach ($picked as $item) {
        $reorderPoints->setOverride($admins[0]['auth'], $item['id'], $warehouses[$warehouseIndex], max(1, (int) round($item['reorder'] * $factor)) * $item['pack']);
        $overrides++;
    }
}

$summary = $counts;
$summary['price_override_without_reason'] = 0;
$summary['reorder_overrides'] = $overrides;
foreach (['price_lists', 'price_list_items', 'quotations', 'quotation_items', 'delivery_notes', 'delivery_note_lines', 'delivery_note_lots', 'invoices', 'invoice_lines', 'invoice_payments', 'stock_counts', 'stock_count_lines', 'stock_lots', 'inventory_operation_lots', 'users', 'warehouses', 'categories', 'suppliers', 'customers', 'products', 'product_units', 'product_stocks', 'purchase_orders', 'purchase_order_items', 'sales_orders', 'sales_order_items', 'stock_ledger', 'audit_logs', 'purchase_order_closures', 'sales_order_rejections', 'sales_order_closures', 'document_sequences', 'inventory_operations', 'inventory_operation_items'] as $table) {
    $summary['rows'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
}
foreach (['purchase_orders', 'sales_orders', 'inventory_operations', 'stock_counts'] as $table) {
    $summary['status'][$table] = $pdo->query("SELECT status, COUNT(*) FROM `$table` GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$summary['ledger_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM product_stocks ps LEFT JOIN (SELECT product_id, warehouse_id, SUM(CASE WHEN movement_type = 'Receipt' THEN quantity WHEN movement_type = 'Issue' THEN -quantity ELSE COALESCE(quantity_delta, 0) END) AS total FROM stock_ledger GROUP BY product_id, warehouse_id) l ON l.product_id = ps.product_id AND l.warehouse_id = ps.warehouse_id WHERE ps.quantity <> COALESCE(l.total, 0)")->fetchColumn();
$summary['negative_stock'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks WHERE quantity < 0')->fetchColumn();
// Low stock = available (quantity − reserved) < effective reorder point: the single rule in LowStockSql (D-10/D-12).
$summary['low_stock_pairs'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE ' . App\Repository\MySql\LowStockSql::isLow() . ' AND p.is_active = 1 AND w.is_active = 1')->fetchColumn();
$summary['low_stock_pairs_physical_quantity'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE ps.quantity < COALESCE(ps.reorder_point_override, p.reorder_point) AND p.is_active = 1 AND w.is_active = 1')->fetchColumn();
$summary['low_stock_pairs_product_default_only'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks ps JOIN products p ON p.id = ps.product_id JOIN warehouses w ON w.id = ps.warehouse_id WHERE CAST(ps.quantity AS SIGNED) - CAST(ps.reserved_quantity AS SIGNED) < p.reorder_point AND p.is_active = 1 AND w.is_active = 1')->fetchColumn();
// D-12 + D-14: reserved = Σ unissued remainder of Approved/PartiallyFulfilled SO lines without a closed remainder, per
// pair, and never above the physical quantity. issued_quantity = Σ Issue ledger per SO line; Fulfilled ⇔ all complete.
$summary += (new App\Repository\MySql\PartialFulfilmentChecks($pdo))->report();
// D-15 / ADR-021: transfer lines match their TRANSFER ledger rows, statuses match line progress, and
// Σ dispatched − Σ received − Σ written off = units in transit; discrepancies carry a reason.
$summary += (new App\Repository\MySql\TransferTransitChecks($pdo))->report();
$summary['in_transit_units'] = (new App\Repository\MySql\TransferTransitChecks($pdo))->inTransitUnits();
$summary['transfer_written_off_units'] = (int) $pdo->query('SELECT COALESCE(SUM(damaged_quantity + short_quantity), 0) FROM inventory_operation_items')->fetchColumn();
$summary['so_lines_partially_issued'] = (int) $pdo->query('SELECT COUNT(*) FROM sales_order_items WHERE issued_quantity > 0 AND issued_quantity < quantity')->fetchColumn();
$summary['so_with_multiple_shipments'] = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT reference_id FROM stock_ledger WHERE movement_type = 'Issue' AND reference_type = 'SO' GROUP BY reference_id HAVING COUNT(DISTINCT created_at) > 1) m")->fetchColumn();
$summary['so_remainders_closed'] = (int) $pdo->query('SELECT COUNT(*) FROM sales_order_closures')->fetchColumn();
$summary['reservation_mismatches'] = (new App\Repository\MySql\StockReservationReconciler($pdo))->mismatches();
$summary['reserved_above_quantity'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > quantity')->fetchColumn();
$summary['reserved_pairs'] = (int) $pdo->query('SELECT COUNT(*) FROM product_stocks WHERE reserved_quantity > 0')->fetchColumn();
$summary['reserved_units'] = (int) $pdo->query('SELECT COALESCE(SUM(reserved_quantity), 0) FROM product_stocks')->fetchColumn();
// D-11: every number is PREFIX-YYMM-NNNN with YYMM = its order_date month, and each counter equals its period's max.
$summary['order_numbers_off_pattern'] = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT order_number, order_date, 'PO' p FROM purchase_orders UNION ALL SELECT order_number, order_date, 'SO' FROM sales_orders) o WHERE NOT REGEXP_LIKE(o.order_number, CONCAT('^', o.p, '-[0-9]{4}-[0-9]{4,}$'), 'c') OR SUBSTRING(o.order_number, 4, 4) <> DATE_FORMAT(o.order_date, '%y%m')")->fetchColumn();
$summary['order_number_duplicates'] = (int) $pdo->query('SELECT COUNT(*) - COUNT(DISTINCT order_number) FROM (SELECT order_number FROM purchase_orders UNION ALL SELECT order_number FROM sales_orders) o')->fetchColumn();
$summary['sequence_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT p, per, MAX(n) n, COUNT(*) c FROM (SELECT 'PO' p, SUBSTRING(order_number, 4, 4) per, CAST(SUBSTRING(order_number, 9) AS UNSIGNED) n FROM purchase_orders UNION ALL SELECT 'SO', SUBSTRING(order_number, 4, 4), CAST(SUBSTRING(order_number, 9) AS UNSIGNED) FROM sales_orders) x GROUP BY p, per) m LEFT JOIN document_sequences s ON s.prefix = m.p AND s.period = m.per WHERE s.last_number IS NULL OR s.last_number <> m.n OR m.n <> m.c")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM document_sequences s WHERE s.prefix IN ('PO', 'SO') AND NOT EXISTS (SELECT 1 FROM purchase_orders WHERE s.prefix = 'PO' AND order_number LIKE CONCAT('PO-', s.period, '-%')) AND NOT EXISTS (SELECT 1 FROM sales_orders WHERE s.prefix = 'SO' AND order_number LIKE CONCAT('SO-', s.period, '-%'))")->fetchColumn();
// D-19 / ADR-025: QT, DO and INV numbers use the same sequence: per prefix and month, counter = highest number = count
// (no gaps; a void invoice keeps its number), every counter row has documents, and every number has the exact format.
$documentNumbers = "SELECT 'QT' p, quotation_number n FROM quotations UNION ALL SELECT 'DO', delivery_number FROM delivery_notes UNION ALL SELECT 'INV', invoice_number FROM invoices";
$summary['document_number_mismatches'] = (int) $pdo->query("SELECT COUNT(*) FROM (SELECT p, SUBSTRING_INDEX(SUBSTRING_INDEX(n, '-', 2), '-', -1) per, MAX(CAST(SUBSTRING_INDEX(n, '-', -1) AS UNSIGNED)) mx, COUNT(*) c
        FROM ({$documentNumbers}) d GROUP BY p, per) m LEFT JOIN document_sequences s ON s.prefix = m.p AND s.period = m.per WHERE s.last_number IS NULL OR s.last_number <> m.mx OR m.mx <> m.c")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM document_sequences s WHERE s.prefix IN ('QT', 'DO', 'INV') AND NOT EXISTS (SELECT 1 FROM ({$documentNumbers}) d WHERE d.p = s.prefix AND d.n LIKE CONCAT(s.prefix, '-', s.period, '-%'))")->fetchColumn()
    + (int) $pdo->query("SELECT COUNT(*) FROM ({$documentNumbers}) d WHERE NOT REGEXP_LIKE(d.n, CONCAT('^', d.p, '-[0-9]{4}-[0-9]{4,}$'), 'c')")->fetchColumn();
$summary['document_sequences'] = $pdo->query("SELECT CONCAT(prefix, '-', period), last_number FROM document_sequences ORDER BY prefix, period")->fetchAll(PDO::FETCH_KEY_PAIR);
// Negotiated prices (D-13, ADR-019): every deviating line has a reason, no SO line is below the purchase price (purchase
// prices do not change during the seed, so the current master is the price at that time), Sales stays within the cap.
foreach (['purchase_order_items' => 'purchase_price', 'sales_order_items' => 'selling_price'] as $table => $column) {
    $summary['price_overrides'][$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} <> list_price")->fetchColumn();
    $summary['price_overrides_above_master'][$table] = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$column} > list_price")->fetchColumn();
    $summary['price_override_without_reason'] += (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE ({$column} <> list_price AND (price_reason IS NULL OR CHAR_LENGTH(TRIM(price_reason)) < 3)) OR ({$column} = list_price AND price_reason IS NOT NULL)")->fetchColumn();
}
// Cost floor per entered unit = base purchase price × unit factor (D-16, ADR-022).
$summary['so_lines_below_purchase_price'] = (int) $pdo->query('SELECT COUNT(*) FROM sales_order_items i JOIN products p ON p.id = i.product_id WHERE ROUND(i.selling_price * 100) < ROUND(p.purchase_price * 100) * i.unit_factor')->fetchColumn();
// D-16 / ADR-022: base quantity = unit quantity × factor on every line, whole-unit receipts/shipments, one base unit per
// product (= products.unit), and every master-priced line's list price equals its unit's master price (no price changes
// happen during the seed): own unit price, else base price × factor.
$summary += (new App\Repository\MySql\MultiUnitChecks($pdo))->report();
// D-17 / ADR-023: lots = aggregate, lot = opening + ledger, every row has a lot, no Issue from an expired lot, FEFO order.
$summary += (new App\Repository\MySql\BatchLotChecks($pdo))->report();
$summary['lot_statistics'] = (new App\Repository\MySql\BatchLotChecks($pdo))->statistics();
$summary['lot_statistics']['near_expiry_lots_at_today'] = (int) $pdo->query('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date BETWEEN DATE(\'' . App\Support\BusinessDate::today() . '\') AND DATE(\'' . App\Support\BusinessDate::today() . '\') + INTERVAL 30 DAY')->fetchColumn();
$summary['lot_statistics']['expired_lots_at_today'] = (int) $pdo->query('SELECT COUNT(*) FROM stock_lots WHERE quantity > 0 AND expiry_date < DATE(\'' . App\Support\BusinessDate::today() . '\')')->fetchColumn();
// D-18 / ADR-024: every posted count line's ledger delta equals its approved variance, no posted count left a product
// below its reservation, approvals are independent of the counters, expected = snapshot + ledger, no overlapping open count.
$summary += (new App\Repository\MySql\StockCountChecks($pdo))->report();
$summary['count_statistics'] = (new App\Repository\MySql\StockCountChecks($pdo))->statistics();
$summary['line_list_price_mismatches'] = (int) $pdo->query('SELECT (SELECT COUNT(*) FROM purchase_order_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_units u ON u.product_id = i.product_id AND u.unit_code = i.unit_code
        WHERE ROUND(i.list_price * 100) <> COALESCE(ROUND(u.purchase_price * 100), ROUND(p.purchase_price * 100) * i.unit_factor))
    + (SELECT COUNT(*) FROM sales_order_items i JOIN products p ON p.id = i.product_id LEFT JOIN product_units u ON u.product_id = i.product_id AND u.unit_code = i.unit_code
        WHERE i.price_source = \'MASTER\' AND ROUND(i.list_price * 100) <> COALESCE(ROUND(u.selling_price * 100), ROUND(p.selling_price * 100) * i.unit_factor))')->fetchColumn();
// D-19 / ADR-025: since price lists, SO list_price is the resolved price; master-sourced lines are checked above. Price-list
// lines must carry the tier of their list at the highest min_quantity ≤ unit quantity (no list is edited during the seed);
// quotation lines likewise.
$summary['price_list_price_mismatches'] = (int) $pdo->query("SELECT
      (SELECT COUNT(*) FROM sales_order_items i WHERE i.price_source = 'PRICE_LIST' AND ROUND(i.list_price * 100) <> (SELECT ROUND(pli.unit_price * 100) FROM price_list_items pli
          WHERE pli.price_list_id = i.price_list_id AND pli.product_id = i.product_id AND pli.unit_code = i.unit_code AND pli.min_quantity <= i.unit_quantity ORDER BY pli.min_quantity DESC LIMIT 1))
    + (SELECT COUNT(*) FROM quotation_items i WHERE i.price_source = 'PRICE_LIST' AND ROUND(i.list_price * 100) <> (SELECT ROUND(pli.unit_price * 100) FROM price_list_items pli
          WHERE pli.price_list_id = i.price_list_id AND pli.product_id = i.product_id AND pli.unit_code = i.unit_code AND pli.min_quantity <= i.unit_quantity ORDER BY pli.min_quantity DESC LIMIT 1))")->fetchColumn();
// Customers with an active, valid list whose master-priced lines had a matching tier at the order date would be a
// resolution bug: the line should have come from the list.
$summary['price_list_not_applied'] = (int) $pdo->query("SELECT COUNT(*) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id JOIN customers c ON c.id = so.customer_id
    JOIN price_lists pl ON pl.id = c.price_list_id AND pl.is_active = 1 AND (pl.valid_from IS NULL OR pl.valid_from <= so.order_date) AND (pl.valid_to IS NULL OR pl.valid_to >= so.order_date)
    WHERE i.price_source = 'MASTER' AND i.quotation_item_id IS NULL AND DATE_ADD(so.order_date, INTERVAL 1 DAY) <= COALESCE(pl.valid_to, '9999-12-31') AND DATE_SUB(so.order_date, INTERVAL 1 DAY) >= COALESCE(pl.valid_from, '0001-01-01')
      AND EXISTS (SELECT 1 FROM price_list_items pli WHERE pli.price_list_id = pl.id AND pli.product_id = i.product_id AND pli.unit_code = i.unit_code AND pli.min_quantity <= i.unit_quantity)")->fetchColumn();
// D-19 / ADR-025: every shipment has exactly one delivery note, invoices = notes × SO prices, totals/PPN recompute from the
// snapshot, payments ≤ total and match paid_amount, one SO per quotation, consistent price sources.
$summary += (new App\Repository\MySql\SalesDocumentChecks($pdo))->report();
$summary['sales_document_statistics'] = (new App\Repository\MySql\SalesDocumentChecks($pdo))->statistics();
// D-20 / ADR-026: FIFO-per-batch valuation. Ledger costs match their lots, every lot rolls forward to the cent, transfers
// carry their cost, COGS = delivery-note lots × lot cost; every month of the history reconciles to the cent and chains
// (closing of a month = opening of the next, the last closing = the value now); margin totals = revenue − COGS.
$summary += (new App\Repository\MySql\ValuationChecks($pdo))->report();
$summary['valuation_statistics'] = (new App\Repository\MySql\ValuationChecks($pdo))->statistics();
$summary['valuation_statistics']['supplier_price_letter_lines'] = $supplierLetterLines;
$valuation = new App\Service\InventoryValuationService(new App\Repository\MySql\MySqlInventoryValuationRepository($pdo));
$valuationAdmin = $admins[0]['auth'];
$summary['reconciliation_differences'] = 0;
$summary['reconciliation_chain_breaks'] = 0;
$previous = null;
for ($month = new DateTimeImmutable('2026-04-01'); $month->format('Y-m') <= '2026-10'; $month = $month->modify('+1 month')) {
    $report = $valuation->reconciliation($valuationAdmin, ['from' => $month->format('Y-m-01'), 'to' => $month->format('Y-m-t')]);
    $byLine = [];
    foreach ($report['rows'] as $row) {
        $summary['reconciliation_differences'] += $row['difference'] === 0 ? 0 : 1;
        $byLine[$row['label']] = $row;
    }
    foreach ($previous ?? [] as $label => $row) {
        $summary['reconciliation_chain_breaks'] += ($byLine[$label]['opening'] ?? 0) === $row['closing'] ? 0 : 1;
    }
    $previous = $byLine;
    $total = $byLine['Total'];
    $summary['reconciliation_by_month'][$month->format('Y-m')] = ['opening' => App\Support\TaxSettings::decimal($total['opening']), 'receipts' => App\Support\TaxSettings::decimal($total['receipts']),
        'issues_cogs' => App\Support\TaxSettings::decimal($total['issues']), 'adjustments' => App\Support\TaxSettings::decimal($total['adjustments']), 'counts' => App\Support\TaxSettings::decimal($total['counts']),
        'write_offs' => App\Support\TaxSettings::decimal($total['write_offs']), 'transit_losses' => App\Support\TaxSettings::decimal($total['transit_losses']),
        'closing' => App\Support\TaxSettings::decimal($total['closing']), 'in_transit_closing' => App\Support\TaxSettings::decimal($byLine['In transit']['closing']), 'difference' => $total['difference']];
}
$now = $valuation->summary($valuationAdmin);
$summary['reconciliation_chain_breaks'] += ($previous['Total']['closing'] ?? -1) === $now['total_cents'] ? 0 : 1;
$summary['valuation_now'] = ['on_hand' => App\Support\TaxSettings::decimal($now['on_hand_cents']), 'in_transit' => App\Support\TaxSettings::decimal($now['in_transit_cents']), 'total' => App\Support\TaxSettings::decimal($now['total_cents'])];
$margin = $valuation->margin($valuationAdmin, ['from' => '2026-01-01', 'to' => '2026-12-31', 'group' => 'line']);
$sqlRevenue = (int) $pdo->query('SELECT COALESCE(SUM(CAST(ROUND(subtotal * 100) AS SIGNED)), 0) FROM invoices WHERE voided_at IS NULL')->fetchColumn();
$sqlCogs = (int) $pdo->query('SELECT COALESCE(SUM(-CAST(ROUND(s.cost_amount * 100) AS SIGNED)), 0) FROM invoices i JOIN invoice_lines il ON il.invoice_id = i.id JOIN delivery_note_lots x ON x.delivery_note_line_id = il.delivery_note_line_id
    JOIN stock_ledger s ON s.id = x.ledger_id WHERE i.voided_at IS NULL')->fetchColumn();
$summary['margin_total_mismatches'] = ($margin['totals']['revenue_cents'] + $margin['totals']['unvalued_revenue_cents'] === $sqlRevenue ? 0 : 1)
    + ($margin['totals']['cogs_cents'] === $sqlCogs ? 0 : 1) + ($margin['totals']['margin_cents'] === $margin['totals']['revenue_cents'] - $margin['totals']['cogs_cents'] ? 0 : 1);
$summary['margin'] = ['revenue' => App\Support\TaxSettings::decimal($margin['totals']['revenue_cents']), 'cogs' => App\Support\TaxSettings::decimal($margin['totals']['cogs_cents']),
    'gross_margin' => App\Support\TaxSettings::decimal($margin['totals']['margin_cents']), 'margin_percent' => $margin['totals']['margin_percent'], 'invoice_lines' => $margin['totals']['lines'],
    'negative_margin_lines' => count(array_filter($margin['lines'], static fn (array $line): bool => $line['valued'] && $line['margin_cents'] < 0)),
    'shipped_not_invoiced_lines' => count($margin['not_invoiced']), 'shipped_not_invoiced_cogs' => App\Support\TaxSettings::decimal($margin['not_invoiced_totals']['cogs_cents'])];
$losses = $valuation->losses($valuationAdmin, ['from' => '2026-01-01', 'to' => '2026-12-31']);
$summary['losses'] = array_map(static fn (array $total): array => ['units' => $total['units'], 'value' => App\Support\TaxSettings::decimal($total['value_cents'])], $losses['totals']);
$summary['product_units'] = $pdo->query('SELECT unit_code, COUNT(*) FROM product_units GROUP BY unit_code ORDER BY unit_code')->fetchAll(PDO::FETCH_KEY_PAIR);
$summary['barcodes'] = (int) $pdo->query('SELECT COUNT(*) FROM product_units WHERE barcode IS NOT NULL')->fetchColumn();
$summary['barcode_check_digit_errors'] = count(array_filter($pdo->query('SELECT barcode FROM product_units WHERE barcode IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN), static fn ($code): bool => !preg_match('/^\d{13}$/', (string) $code) || App\Service\ProductUnitService::ean13CheckDigit(substr((string) $code, 0, 12)) !== (int) substr((string) $code, 12)));
foreach (['purchase_order_items', 'sales_order_items', 'inventory_operation_items'] as $table) {
    $summary['lines_by_unit'][$table] = $pdo->query("SELECT unit_code, COUNT(*) FROM {$table} GROUP BY unit_code ORDER BY unit_code")->fetchAll(PDO::FETCH_KEY_PAIR);
}
$summary['sales_discount_above_cap'] = (int) $pdo->query("SELECT COUNT(*) FROM sales_order_items i JOIN sales_orders so ON so.id = i.sales_order_id JOIN users u ON u.id = so.created_by WHERE u.role = 'Sales' AND i.selling_price * 100 < i.list_price * (100 - " . App\Service\OrderLinePricing::DEFAULT_SALES_MAX_DISCOUNT_PERCENT . ')')->fetchColumn();
$summary['date_range'] = $pdo->query('SELECT MIN(order_date), MAX(order_date) FROM (SELECT order_date FROM purchase_orders UNION ALL SELECT order_date FROM sales_orders) d')->fetch(PDO::FETCH_NUM);
echo json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . PHP_EOL;
exit($summary['ledger_mismatches'] === 0 && $summary['negative_stock'] === 0 && $summary['reservation_mismatches'] === 0 && $summary['reserved_above_quantity'] === 0 && $summary['order_numbers_off_pattern'] === 0 && $summary['order_number_duplicates'] === 0 && $summary['sequence_mismatches'] === 0 && $summary['price_override_without_reason'] === 0 && $summary['so_lines_below_purchase_price'] === 0 && $summary['sales_discount_above_cap'] === 0 && $summary['issued_ledger_mismatches'] === 0 && $summary['fulfilment_status_mismatches'] === 0 && $summary['closure_mismatches'] === 0
    && $summary['transfer_dispatch_ledger_mismatches'] === 0 && $summary['transfer_receipt_ledger_mismatches'] === 0 && $summary['transfer_status_mismatches'] === 0
    && $summary['in_transit_equation_mismatch'] === 0 && $summary['transfer_discrepancy_without_reason'] === 0
    && $summary['line_unit_mismatches'] === 0 && $summary['whole_unit_mismatches'] === 0 && $summary['base_unit_mismatches'] === 0
    && $summary['line_list_price_mismatches'] === 0 && $summary['barcode_check_digit_errors'] === 0
    && $summary['lot_aggregate_mismatches'] === 0 && $summary['lot_ledger_mismatches'] === 0 && $summary['lot_count_mismatches'] === 0 && $summary['ledger_rows_without_lot'] === 0
    && $summary['issue_from_expired_lot'] === 0 && $summary['fefo_violations'] === 0 && $summary['transfer_lot_mismatches'] === 0
    && $summary['count_posted_delta_mismatches'] === 0 && $summary['count_below_reserved'] === 0 && $summary['count_approved_by_counter'] === 0
    && $summary['count_expected_mismatches'] === 0 && $summary['count_open_overlaps'] === 0
    && $summary['price_list_price_mismatches'] === 0 && $summary['document_number_mismatches'] === 0 && $summary['price_list_not_applied'] === 0 && $summary['issue_rows_without_delivery_note'] === 0 && $summary['delivery_note_line_mismatches'] === 0
    && $summary['delivery_note_issue_mismatches'] === 0 && $summary['invoice_line_mismatches'] === 0 && $summary['invoice_total_mismatches'] === 0
    && $summary['invoice_payment_mismatches'] === 0 && $summary['quotation_conversion_mismatches'] === 0 && $summary['price_source_mismatches'] === 0
    && $summary['ledger_cost_missing'] === 0 && $summary['ledger_cost_without_lot'] === 0 && $summary['ledger_cost_lot_mismatches'] === 0 && $summary['ledger_cost_amount_mismatches'] === 0
    && $summary['lot_value_rollforward_mismatches'] === 0 && $summary['pair_value_mismatches'] === 0 && $summary['transit_cost_mismatches'] === 0 && $summary['transit_value_mismatches'] === 0
    && $summary['cogs_delivery_note_mismatches'] === 0 && $summary['cogs_order_mismatches'] === 0
    && $summary['reconciliation_differences'] === 0 && $summary['reconciliation_chain_breaks'] === 0 && $summary['margin_total_mismatches'] === 0 ? 0 : 1);
