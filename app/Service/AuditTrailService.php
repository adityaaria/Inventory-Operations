<?php
declare(strict_types=1);
namespace App\Service;
use App\Repository\Contract\AuditQueryRepositoryInterface;
use App\Security\AuthContext;
use App\Security\Authorization;
use App\Exception\HttpException;
use App\Support\Pagination;
use App\Support\PaginatedResult;
use InvalidArgumentException;
final class AuditTrailService
{
    public function __construct(private readonly AuditQueryRepositoryInterface $repository) {}
    /** @param array<string,mixed> $input @return array<string,string> */
    public function filters(array $input): array
    {
        $filters=[];
        foreach (['action','status','actor_id','from','to'] as $key) {
            $value=$input[$key] ?? '';
            if (!is_string($value) || strlen($value)>80) { throw new InvalidArgumentException('Invalid audit filter.'); }
            $filters[$key]=trim($value);
        }
        if ($filters['status']!=='' && !in_array($filters['status'],['success','failure','blocked'],true)) { throw new InvalidArgumentException('Invalid status.'); }
        if ($filters['actor_id']!=='' && (!ctype_digit($filters['actor_id']) || (int)$filters['actor_id']<1 || (int)$filters['actor_id']>4294967295)) { throw new InvalidArgumentException('Invalid actor ID.'); }
        foreach (['from','to'] as $key) {
            if ($filters[$key]==='') { continue; }
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key]);
            if (!$date || $date->format('Y-m-d')!==$filters[$key]) { throw new InvalidArgumentException('Invalid date.'); }
        }
        if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) { throw new InvalidArgumentException('Invalid date range.'); }
        return $filters;
    }
    /** @param array<string,mixed> $input @return PaginatedResult<array<string,mixed>> */
    public function preview(AuthContext $actor,array $input): PaginatedResult
    {
        if (!Authorization::canManageUsers($actor)) { throw new HttpException(403,'Forbidden'); }
        $filters=$this->filters($input); $pagination=Pagination::fromArray($input);
        $total=$this->repository->count($filters);
        return new PaginatedResult($this->repository->page($filters,Pagination::PER_PAGE,$pagination->offsetForTotal($total)),$total,$pagination->pageForTotal($total),Pagination::PER_PAGE);
    }
}
