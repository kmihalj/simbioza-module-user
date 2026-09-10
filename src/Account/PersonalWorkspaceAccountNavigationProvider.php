<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Account;

use AaiEduHr\HeartPhrameModuleAuth\Account\AuthAccountNavigationProviderInterface;
use AaiEduHr\SimbiozaModuleUser\Service\PersonalWorkspaceService;
use HeartPhrame\Localization\TranslatorInterface;
use HeartPhrame\Routing\UrlGenerator;

use function is_array;
use function is_scalar;
use function rawurlencode;
use function rtrim;
use function trim;

/** HR: Dodaje poveznicu na postojeće aktivno osobno područje u korisnički meni. EN: Adds the existing active personal Workspace to the user menu. */
final readonly class PersonalWorkspaceAccountNavigationProvider implements AuthAccountNavigationProviderInterface
{
    /** HR: Prima mapiranje područja, rute i prevoditelj. EN: Receives Workspace mappings, routes, and the translator. */
    public function __construct(
        private PersonalWorkspaceService $personalWorkspaces,
        private UrlGenerator $urls,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * HR: Vraća poveznicu samo kada korisnik već ima aktivno osobno područje.
     * EN: Returns a link only when the user already has an active personal Workspace.
     *
     * @return list<array{key:string,label:string,path:string,order:int}>
     */
    public function itemsForUser(int $userId): array
    {
        $mapping = $this->personalWorkspaces->forUser($userId);
        $workspace = is_array($mapping['workspace'] ?? null) ? $mapping['workspace'] : null;
        $slug = is_array($workspace) && is_scalar($workspace['slug'] ?? null)
            ? trim((string)$workspace['slug'])
            : '';
        if (!is_array($mapping) || (bool)($mapping['is_deleted'] ?? false) || $slug === '') {
            return [];
        }

        $path = $this->urls->namedRouteExists('workspace.show')
            ? $this->urls->getPathFor('workspace.show', ['workspaceSlug' => $slug])
            : rtrim($this->urls->getBasePath(), '/') . '/workspace/' . rawurlencode($slug);

        return [[
            'key' => 'simbioza-user.personal-workspace',
            'label' => $this->translator->trans('Moje područje'),
            'path' => $path,
            'order' => 20,
        ]];
    }
}
