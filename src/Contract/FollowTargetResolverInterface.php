<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Contract;

/**
 * EN: Contract for ACL-safe resolution of a polymorphic follow target.
 *
 * HR: Ugovor za ACL-sigurno razrješavanje polimorfnog cilja praćenja.
 */
interface FollowTargetResolverInterface
{
    /**
     * EN: Returns a label and path only while the user can still access the target.
     *
     * HR: Vraća naziv i putanju samo kada korisnik još ima pristup cilju.
     *
     * @param array<string,mixed> $context
     * @return array{accessible:bool,type:string,id:string,label:string,url:string,workspace_id:?int,page_id:?int,document_id:?string}
     */
    public function describe(string $type, string $id, int $userId, array $context = []): array;
}
