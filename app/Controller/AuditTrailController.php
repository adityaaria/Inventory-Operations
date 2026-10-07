<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\AuditTrailService;
use InvalidArgumentException;
final class AuditTrailController
{
    public function __construct(private readonly AuditTrailService $audit,private readonly AuthGuard $guard) {}
    public function index(Request $request): Response
    {
        $actor=$this->guard->requireUserManagement(); $error=''; $status=200;
        try { $result=$this->audit->preview($actor,$request->query()); $filters=$this->audit->filters($request->query()); }
        catch (InvalidArgumentException $exception) { $error=$exception->getMessage(); $status=422; $filters=$this->audit->filters([]); $result=null; }
        $paginationPath='/audit-trail'; $paginationLabel='Audit records'; $paginationQuery=$filters;
        ob_start(); require dirname(__DIR__,2).'/views/audit-trail/index.php'; $body=ob_get_clean();
        return Response::html(is_string($body)?$body:'',$status);
    }
}
