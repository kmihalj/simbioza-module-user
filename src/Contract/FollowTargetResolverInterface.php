<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Contract;

/**
 * HR: Ugovor za ACL-sigurno razrješavanje polimorfnog cilja praćenja.
 * EN: Contract for ACL-safe resolution of a polymorphic follow target.
 */
interface FollowTargetResolverInterface
{
    /**
     * HR: Vraća naziv i putanju samo kada korisnik još ima pristup cilju.
     * EN: Returns a label and path only while the user can still access the target.
     *
     * @param array<string,mixed> $context
     * @return array{accessible:bool,type:string,id:string,label:string,url:string,workspace_id:?int,page_id:?int,document_id:?string}
     */
    public function describe(string $type, string $id, int $userId, array $context = []): array;
}
