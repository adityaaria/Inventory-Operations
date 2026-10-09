<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\{Request,Response,View};
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
        return View::render('work-queue/index.php', ['error' => $error] + $data, $status);
    }
}
