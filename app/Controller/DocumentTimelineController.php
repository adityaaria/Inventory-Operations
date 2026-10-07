<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\{Request,Response};
use App\Security\AuthGuard;
use App\Service\DocumentTimelineService;
use App\Validation\InputValidator;
final class DocumentTimelineController
{
    public function __construct(private readonly DocumentTimelineService $timeline,private readonly AuthGuard $guard) {}
    public function index(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$kind=InputValidator::requiredString('kind',$request->query()['kind']??'',20);$id=InputValidator::positiveInt('id',$request->query()['id']??'');$history=$this->timeline->forDocument($actor,$kind,$id);
        $title='Transaction Timeline';$subtitle=$kind.' #'.$id.' · latest recorded events first. Missing historical events are not reconstructed.';$path=match($kind){'PO'=>'/purchase-orders/show','SO'=>'/sales-orders/show',default=>'/inventory-operations/show'};$toolbar='<a class="button" href="'.$path.'?id='.$id.'">Back to document</a>';
        ob_start();require dirname(__DIR__,2).'/views/timeline/index.php';$body=ob_get_clean();return Response::html(is_string($body)?$body:'');
    }
}
