<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\{Request,Response};
use App\Security\AuthGuard;
use App\Service\{BusinessOperationService,BusinessOperationInput};
use App\Repository\Contract\{ProductRepositoryInterface,WarehouseRepositoryInterface};
use App\Validation\InputValidator;
use InvalidArgumentException;
final class BusinessOperationController
{
    private const SHOW_PATH='/inventory-operations/show?id=';
    public function __construct(private readonly BusinessOperationService $service,private readonly AuthGuard $guard,private readonly ProductRepositoryInterface $products,private readonly WarehouseRepositoryInterface $warehouses,private readonly ?\App\Service\ProductUnitCatalog $units=null) {}
    public function index(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$this->service->authorize($actor);$error='';$status=200;
        try{$filters=$this->service->filters($request->query());$result=$this->service->search($actor,$request->query());}catch(InvalidArgumentException $exception){$error=$exception->getMessage();$status=422;$filters=$this->service->filters([]);$result=null;}
        return $this->render('index',['actor'=>$actor,'filters'=>$filters,'result'=>$result,'error'=>$error],$status);
    }
    public function recommendations(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$this->service->authorize($actor);$error='';$status=200;
        try{$filters=$this->service->filters($request->query());$result=$this->service->recommendations($actor,$request->query());}catch(InvalidArgumentException $exception){$error=$exception->getMessage();$status=422;$filters=$this->service->filters([]);$result=null;}
        return $this->render('recommendations',['filters'=>$filters,'result'=>$result,'error'=>$error,'warehouses'=>array_values(array_filter($this->warehouses->all(),static fn($warehouse):bool=>$warehouse->isActive()))],$status);
    }
    public function create(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$this->service->authorize($actor);
        $old=[];foreach(['kind','source_ledger_id'] as $key) { if(is_string($request->query()[$key]??null)) { $old[$key]=$request->query()[$key]; } }
        return $this->form($old);
    }
    public function store(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$this->service->authorize($actor);$post=$request->post();
        try{
            $kind=InputValidator::requiredString('kind',$post['kind']??'',30);$isReturn=in_array($kind,['SupplierReturn','CustomerReturn'],true);
            $warehouse=$isReturn?0:InputValidator::positiveInt('warehouse_id',$post['warehouse_id']??'');
            $destination=$kind==='Transfer'?InputValidator::positiveInt('destination_id',$post['destination_id']??''):null;
            $extra=$post['items']??[];
            if(!is_array($extra) || count($extra)>99) { throw new \App\Exception\ValidationException('Use at most 100 product items.'); }
            $items=[];
            foreach([$post,...array_values($extra)] as $line){
                if(!is_array($line)) { throw new \App\Exception\ValidationException('Invalid item.'); }
                // Lot of an adjustment line (D-17, ADR-023): lot_id = an existing lot, "new" + lot_code/expiry_date = a new lot, empty = whole product.
                $lotChoice=is_string($line['lot_id']??null)?trim($line['lot_id']):'';
                $items[]=['product_id'=>$isReturn?0:InputValidator::positiveInt('product_id',$line['product_id']??''),'quantity'=>BusinessOperationInput::quantity($line['quantity']??null,$kind==='Adjustment'),'unit_code'=>is_string($line['unit_code']??null)?$line['unit_code']:null,'baseline'=>$line['baseline']??null,'source_ledger_id'=>$line['source_ledger_id']??null,'fit_for_stock'=>($line['fit_for_stock']??'')==='1',
                    'lot_id'=>$kind==='Adjustment'&&$lotChoice!==''&&$lotChoice!=='new'?$lotChoice:null,'lot_code'=>$kind==='Adjustment'&&$lotChoice==='new'?($line['lot_code']??''):null,'expiry_date'=>$kind==='Adjustment'&&$lotChoice==='new'?($line['expiry_date']??''):null];
            }
            $id=$this->service->propose($actor,$kind,$warehouse,$destination,BusinessOperationInput::reason($post['reason']??null),$items);
        }catch(InvalidArgumentException $exception){return $this->form($post,$exception->getMessage(),422);}
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    public function show(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$operation=$this->service->show($actor,InputValidator::positiveInt('id',$request->query()['id']??''));return $this->render('show',['operation'=>$operation,'actor'=>$actor,'error'=>'']);
    }
    public function decide(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$id=InputValidator::positiveInt('id',$request->post()['id']??'');
        $decision=$request->post()['decision']??'';$reason=$request->post()['reason']??null;
        try{$this->service->decide($actor,$id,InputValidator::requiredString('decision',$decision,30),BusinessOperationInput::reason($reason));}
        catch(InvalidArgumentException $exception){
            $operation=$this->service->show($actor,$id);
            // A failed rejection of a still-pending proposal reopens its reason dialog; other failures show on the page.
            if($decision==='Rejected' && $operation['status']==='PendingApproval'){return $this->render('show',['operation'=>$operation,'actor'=>$actor,'error'=>'','rejectDialog'=>['open'=>true,'reason'=>is_string($reason)?$reason:'','error'=>$exception->getMessage()]],422);}
            return $this->render('show',['operation'=>$operation,'actor'=>$actor,'error'=>$exception->getMessage()],422);
        }
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    public function post(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$id=InputValidator::positiveInt('id',$request->post()['id']??'');
        try{$this->service->post($actor,$id);}catch(InvalidArgumentException $exception){return $this->render('show',['operation'=>$this->service->show($actor,$id),'actor'=>$actor,'error'=>$exception->getMessage()],422);}
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    /** Transfer dispatch (D-15, ADR-021); the service authorizes and validates. */
    public function dispatch(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$id=InputValidator::positiveInt('id',$request->post()['id']??'');
        try{$this->service->dispatch($actor,$id);}catch(InvalidArgumentException $exception){return $this->render('show',['operation'=>$this->service->show($actor,$id),'actor'=>$actor,'error'=>$exception->getMessage()],422);}
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    /** Transfer receipt: received[product_id] good units, damaged[product_id] damaged units, receipt_reason for discrepancies. */
    public function receive(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$post=$request->post();$id=InputValidator::positiveInt('id',$post['id']??'');
        $reason=is_string($post['receipt_reason']??null)?$post['receipt_reason']:null;$lines=null;
        try{
            if(isset($post['received'])) {
                $received=$post['received'];$damaged=$post['damaged']??[];
                if(!is_array($received) || !is_array($damaged)) { throw new \App\Exception\ValidationException('Invalid receipt quantities.'); }
                $lines=[];foreach($received as $product=>$good) { $lines[$product]=['good'=>$good,'damaged'=>$damaged[$product]??'0']; }
            }
            $this->service->receive($actor,$id,$lines,$reason);
        }catch(InvalidArgumentException $exception){return $this->render('show',['operation'=>$this->service->show($actor,$id),'actor'=>$actor,'error'=>$exception->getMessage(),'receiptInput'=>['received'=>is_array($post['received']??null)?$post['received']:[],'damaged'=>is_array($post['damaged']??null)?$post['damaged']:[],'reason'=>$reason??'']],422);}
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    /** Proposes the write-off of every expired lot with stock in a warehouse (D-17, ADR-023); the service authorizes. */
    public function writeOffExpired(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$post=$request->post();
        $id=$this->service->proposeExpiredWriteOff($actor,InputValidator::positiveInt('warehouse_id',$post['warehouse_id']??''),BusinessOperationInput::reason($post['reason']??null));
        return new Response('',302,['Location'=>self::SHOW_PATH.$id]);
    }
    /** Open dispatched transfers ("In transit"), Admin/WarehouseStaff. */
    public function inTransit(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$this->service->authorize($actor);
        return $this->render('in-transit',['result'=>$this->service->inTransit($actor,$request->query())]);
    }
    public function balance(Request $request): Response {$actor=$this->guard->requireAuth();return new Response(json_encode($this->service->balance($actor,InputValidator::positiveInt('product_id',$request->query()['product_id']??''),InputValidator::positiveInt('warehouse_id',$request->query()['warehouse_id']??'')),JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);}
    public function source(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$source=$this->service->source($actor,InputValidator::positiveInt('id',$request->query()['id']??''));
        // The return line is entered in a unit of the original movement's product (D-16, ADR-022).
        $product=$this->products->findById((int)$source['product_id']);
        if($product!==null) { $set=($this->units??new \App\Service\ProductUnitCatalog($this->products))->forProduct($product);$source['base_unit']=$set->baseCode();$source['units']=$set->describe($product); }
        return new Response(json_encode($source,JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);
    }
    /** @param array<string,mixed> $old */
    private function form(array $old,string $error='',int $status=200): Response {return $this->render('create',['old'=>$old,'error'=>$error,'products'=>$this->products->active(),'warehouses'=>$this->warehouses->all()],$status);}
    /** @param array<string,mixed> $data */
    private function render(string $view,array $data,int $status=200): Response
    {
        // Units per product (D-16, ADR-022): unit choices on the proposal form, quantity breakdown on the detail page.
        $catalog=$this->units??new \App\Service\ProductUnitCatalog($this->products);
        if(isset($data['operation']) && is_array($data['operation'])) { $data['unitSets']=$catalog->forProductIds(array_map(static fn(array $item): int => (int)$item['product_id'],$data['operation']['items'])); }
        if(isset($data['products']) && is_array($data['products'])) { $data['unitSets']=$catalog->forProducts($data['products']); }
        extract($data,EXTR_SKIP);ob_start();require dirname(__DIR__,2).'/views/inventory-operations/'.$view.'.php';$body=ob_get_clean();return Response::html(is_string($body)?$body:'',$status);}
}
