<?php
declare(strict_types=1);
namespace App\Controller;
use App\Http\{Request,Response,View};
use App\Security\AuthGuard;
use App\Service\DocumentTimelineService;
use App\Validation\InputValidator;
final class DocumentTimelineController
{
    public function __construct(private readonly DocumentTimelineService $timeline,private readonly AuthGuard $guard) {}
    public function index(Request $request): Response
    {
        $actor=$this->guard->requireAuth();$kind=InputValidator::requiredString('kind',$request->query()['kind']??'',20);$id=InputValidator::positiveInt('id',$request->query()['id']??'');$path=match($kind){'PO'=>'/purchase-orders/show','SO'=>'/sales-orders/show',default=>'/inventory-operations/show'};
        return View::render('timeline/index.php', [
            'history' => $this->timeline->forDocument($actor,$kind,$id),
            'title' => 'Transaction Timeline',
            'subtitle' => $kind.' #'.$id.' · latest recorded events first. Missing historical events are not reconstructed.',
            'toolbar' => '<a class="button" href="'.$path.'?id='.$id.'">Back to document</a>',
        ]);
    }
}
