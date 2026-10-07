<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\{OrderExceptionRepositoryInterface,PurchaseOrderRepositoryInterface,SalesOrderRepositoryInterface,AuditLogRepositoryInterface};
use App\Security\{AuthContext,Authorization};
use App\Exception\{HttpException,ValidationException};
final class OrderExceptionService
{
    public function __construct(private readonly OrderExceptionRepositoryInterface $exceptions,private readonly PurchaseOrderRepositoryInterface $purchase,private readonly SalesOrderRepositoryInterface $sales,private readonly StockService $stock,private readonly AuditLogRepositoryInterface $audit) {}
    public function close(AuthContext $actor,int $id,string $reason): void
    {
        if (!Authorization::canManageUsers($actor)) { throw new HttpException(403,'Forbidden'); }
        $reason=BusinessOperationInput::reason($reason);
        $this->stock->transaction(function() use($actor,$id,$reason): void {
            $order=$this->purchase->lockById($id)??throw new HttpException(404,'Purchase order not found.');
            if ($this->exceptions->closure($id)!==null) { throw new ValidationException('The remainder is already closed.'); }
            if ($order->status()!=='PartiallyReceived') { throw new ValidationException('Only a partially received PO can close its remainder.'); }
            $this->exceptions->close($id,$actor->userId(),$reason);
            $this->audit->append($actor->userId(),'purchase-orders.close-remainder','PO',$id,'success','','',['reason'=>$reason]);
        });
    }
    public function reject(AuthContext $actor,int $id,string $reason): void
    {
        if (!Authorization::canManageUsers($actor)) { throw new HttpException(403,'Forbidden'); }
        $reason=BusinessOperationInput::reason($reason);
        $this->stock->transaction(function() use($actor,$id,$reason): void {
            $order=$this->sales->lockById($id)??throw new HttpException(404,'Sales order not found.');
            if ($order->status()!=='PendingApproval') { throw new ValidationException('Only PendingApproval SO can be rejected.'); }
            $this->exceptions->reject($id,$actor->userId(),$reason); $this->sales->cancel($id);
            $this->audit->append($actor->userId(),'sales-orders.reject','SO',$id,'success','','',['reason'=>$reason]);
        });
    }
    /** @return array<string,mixed>|null */
    public function closure(int $id): ?array { return $this->exceptions->closure($id); }
    /** @return array<string,mixed>|null */
    public function rejection(int $id): ?array { return $this->exceptions->rejection($id); }
}
