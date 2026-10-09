<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Controller\ReportController;
use App\Exception\HttpException;
use App\Http\Request;
use App\Repository\InMemory\InMemoryOperationalQueryRepository;
use App\Security\{AuthContext,AuthGuard,SessionManager};
use App\Service\ReportService;
use PHPUnit\Framework\TestCase;
/** Report page filters/status badges and the three CSV download endpoints with their authorization and validation. */
final class ReportControllerCoverageTest extends TestCase
{
    private const ROWS=[
        ['Type'=>'PO','OrderNumber'=>'PO-1','Party'=>'Supplier','Status'=>'PartiallyReceived','Date'=>'2026-10-01','Movement'=>'Receipt','SKU'=>'SKU-1','Warehouse'=>'Main','Quantity'=>4,'ReferenceType'=>'PO','ReferenceId'=>1,'AgeBucket'=>'8-30 days','AgeDays'=>9,'OutstandingQty'=>3,'Created'=>'2026-09-30'],
        ['Type'=>'SO','OrderNumber'=>'SO-1','Party'=>'=HYPERLINK("x")','Status'=>'PendingApproval','Date'=>'2026-10-02','Movement'=>'Issue','SKU'=>'SKU-1','Warehouse'=>'Main','Quantity'=>1,'ReferenceType'=>'SO','ReferenceId'=>1,'AgeBucket'=>'0-2 days','AgeDays'=>1,'OutstandingQty'=>1,'Created'=>'2026-10-02'],
        ['Type'=>'PO','OrderNumber'=>'PO-2','Party'=>'Supplier','Status'=>'Received','Date'=>'2026-10-03'],
        ['Type'=>'PO','OrderNumber'=>'PO-3','Party'=>'Supplier','Status'=>'Ordered','Date'=>'2026-10-03'],
        ['Type'=>'SO','OrderNumber'=>'SO-2','Party'=>'Customer','Status'=>'Cancelled','Date'=>'2026-10-04'],
    ];
    private function controller(AuthContext $actor): ReportController
    {
        $session=new SessionManager();$session->login($actor);
        return new ReportController(new ReportService(new InMemoryOperationalQueryRepository(self::ROWS)),new AuthGuard($session));
    }
    private function admin(): AuthContext {return new AuthContext(1,'admin@test','Admin');}
    public function testOrdersReportRendersEveryStatusTone(): void
    {
        $body=$this->controller($this->admin())->index(new Request('GET','/reports',['type'=>'orders'],[],[]))->body();
        foreach(['status-warning status-partial','status-warning status-pending','status-success status-received','status-warning status-ordered','status-danger status-cancelled'] as $badge){self::assertStringContainsString($badge,$body);}
        self::assertStringNotContainsString('name="document"',$body);
    }
    public function testOutstandingReportKeepsDocumentAndAgeFiltersInFormAndExport(): void
    {
        $response=$this->controller($this->admin())->index(new Request('GET','/reports',['type'=>'outstanding','document'=>'PO','age'=>'8-30 days'],[],[]));
        $body=$response->body();
        self::assertSame(200,$response->statusCode());
        self::assertStringContainsString('<option value="PO" selected>Purchase orders</option>',$body);
        self::assertStringContainsString('<option value="OP" >Stock proposals</option>',$body);
        self::assertStringContainsString('<option value="8-30 days" selected>8-30 days</option>',$body);
        self::assertStringContainsString('PO-1',$body);self::assertStringNotContainsString('SO-1',$body);
        self::assertStringContainsString(htmlspecialchars('/reports/outstanding.csv?from=&to=&document=PO&age=8-30+days',ENT_QUOTES,'UTF-8'),$body);
    }
    public function testSalesOutstandingFilterOffersOnlySalesOrdersAndRejectsPurchaseFilter(): void
    {
        $sales=$this->controller(new AuthContext(7,'sales@test','Sales'));
        $body=$sales->index(new Request('GET','/reports',['type'=>'outstanding'],[],[]))->body();
        self::assertStringContainsString('<option value="SO" >Sales orders</option>',$body);self::assertStringNotContainsString('value="PO"',$body);
        $rejected=$sales->index(new Request('GET','/reports',['type'=>'outstanding','document'=>'PO'],[],[]));
        self::assertSame(422,$rejected->statusCode());self::assertStringContainsString('Invalid outstanding filter.',$rejected->body());
    }
    public function testStockLedgerCsvUsesMovementColumnsAndNeutralizesFormulas(): void
    {
        $response=$this->controller(new AuthContext(4,'warehouse@test','WarehouseStaff'))->stockLedger(new Request('GET','/reports/stock-ledger.csv',['from'=>'2026-10-01','to'=>'2026-10-31'],[],[]));
        self::assertSame(200,$response->statusCode());
        self::assertSame('attachment; filename="stock-ledger.csv"',$response->headers()['Content-Disposition']);
        $lines=explode("\n",trim($response->body()));
        self::assertSame('Date,Movement,SKU,Warehouse,Quantity,ReferenceType,ReferenceId',$lines[0]);
        self::assertSame('2026-10-01,Receipt,SKU-1,Main,4,PO,1',$lines[1]);
        self::assertCount(count(self::ROWS)+1,$lines);
    }
    public function testStockLedgerCsvIsForbiddenForSalesAndValidatesDates(): void
    {
        $bad=$this->controller($this->admin())->stockLedger(new Request('GET','/reports/stock-ledger.csv',['from'=>'2026-10-31','to'=>'2026-10-01'],[],[]));
        self::assertSame(422,$bad->statusCode());self::assertSame('Invalid date range.',$bad->body());
        $this->expectException(HttpException::class);$this->expectExceptionMessage('Forbidden');
        $this->controller(new AuthContext(7,'sales@test','Sales'))->stockLedger(new Request('GET','/reports/stock-ledger.csv',[],[],[]));
    }
    public function testOrdersCsvDownloadsAndRejectsInvalidDate(): void
    {
        $controller=$this->controller($this->admin());
        $response=$controller->orders(new Request('GET','/reports/orders.csv',[],[],[]));
        self::assertSame('attachment; filename="orders.csv"',$response->headers()['Content-Disposition']);
        $body=$response->body();
        self::assertStringStartsWith("Type,OrderNumber,Party,Status,Date\n",$body);
        self::assertStringContainsString("SO,SO-1,\"'=HYPERLINK(\"\"x\"\")\",PendingApproval,2026-10-02",$body);
        $bad=$controller->orders(new Request('GET','/reports/orders.csv',['from'=>['array']],[],[]));
        self::assertSame(422,$bad->statusCode());self::assertSame('Invalid date range.',$bad->body());
    }
    public function testOutstandingCsvAppliesFiltersAndRejectsUnknownBucket(): void
    {
        $controller=$this->controller($this->admin());
        $response=$controller->outstanding(new Request('GET','/reports/outstanding.csv',['document'=>'SO'],[],[]));
        self::assertSame('attachment; filename="outstanding-orders.csv"',$response->headers()['Content-Disposition']);
        $lines=explode("\n",trim($response->body()));
        self::assertSame('Type,OrderNumber,Party,Status,Created,AgeDays,AgeBucket,DaysSinceApproval,OutstandingQty',$lines[0]);
        self::assertCount(3,$lines);self::assertStringStartsWith('SO,SO-1,',$lines[1]);self::assertStringStartsWith('SO,SO-2,',$lines[2]);
        $bad=$controller->outstanding(new Request('GET','/reports/outstanding.csv',['age'=>'forever'],[],[]));
        self::assertSame(422,$bad->statusCode());self::assertSame('Invalid outstanding filter.',$bad->body());
    }
}
