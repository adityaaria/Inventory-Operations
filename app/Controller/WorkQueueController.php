<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\{Request,Response};
use App\Security\AuthGuard;
use App\Service\WorkQueueService;
use InvalidArgumentException;
final class WorkQueueController
{
    public function __construct(private readonly WorkQueueService $queue,private readonly AuthGuard $guard) {}
    public function index(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$error='';$status=200;
        try{$data=$this->queue->search($actor,$request->query());}catch(InvalidArgumentException $exception){$data=$this->queue->search($actor,[]);$error=$exception->getMessage();$status=422;}
        extract($data,EXTR_SKIP);ob_start();require dirname(__DIR__,2).'/views/work-queue/index.php';$body=ob_get_clean();return Response::html(is_string($body)?$body:'',$status);
    }
}
