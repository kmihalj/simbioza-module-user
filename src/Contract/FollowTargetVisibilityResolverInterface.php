<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Contract;

/**
 * EN: Bulk target authorization without labels, ambient user state or retained ACL decisions.
 *
 * HR: Skupna provjera ciljeva bez oznaka, ambijentalnog korisnika ili zadržanih ACL odluka.
 */
interface FollowTargetVisibilityResolverInterface
{
    /**
     * EN: Resolves current access in list order; document context is required for task lists.
     *
     * HR: Provjerava aktualni pristup redoslijedom liste; liste zadataka traže kontekst dokumenta.
     *
     * @param list<array{type:string,id:string,document_id?:mixed}> $targets
     * @return list<bool>
     */
    public function canViewTargets(int $userId, array $targets): array;
}
