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
    public function __construct(private readonly BusinessOperationService $service,private readonly AuthGuard $guard,private readonly ProductRepositoryInterface $products,private readonly WarehouseRepositoryInterface $warehouses) {}
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
                $items[]=['product_id'=>$isReturn?0:InputValidator::positiveInt('product_id',$line['product_id']??''),'quantity'=>BusinessOperationInput::quantity($line['quantity']??null,$kind==='Adjustment'),'baseline'=>$line['baseline']??null,'source_ledger_id'=>$line['source_ledger_id']??null,'fit_for_stock'=>($line['fit_for_stock']??'')==='1'];
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
    public function balance(Request $request): Response {$actor=$this->guard->requireAuth();return new Response(json_encode($this->service->balance($actor,InputValidator::positiveInt('product_id',$request->query()['product_id']??''),InputValidator::positiveInt('warehouse_id',$request->query()['warehouse_id']??'')),JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);}
    public function source(Request $request): Response {$actor=$this->guard->requireAuth();return new Response(json_encode($this->service->source($actor,InputValidator::positiveInt('id',$request->query()['id']??'')),JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);}
    /** @param array<string,mixed> $old */
    private function form(array $old,string $error='',int $status=200): Response {return $this->render('create',['old'=>$old,'error'=>$error,'products'=>$this->products->active(),'warehouses'=>$this->warehouses->all()],$status);}
    /** @param array<string,mixed> $data */
    private function render(string $view,array $data,int $status=200): Response {extract($data,EXTR_SKIP);ob_start();require dirname(__DIR__,2).'/views/inventory-operations/'.$view.'.php';$body=ob_get_clean();return Response::html(is_string($body)?$body:'',$status);}
}
