<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleWorkspace\Contract\WorkspacePresentationProviderInterface;
use HeartPhrame\Localization\TranslatorInterface;

use function is_numeric;
use function is_scalar;
use function preg_match;
use function sprintf;
use function trim;

/**
 * HR: Lokalizira generirani naziv i opis osobnih područja samo tijekom prikaza.
 *     Izvorni Workspace zapis i korisnički definirani slug ostaju nepromijenjeni.
 * EN: Localizes generated personal-space names and descriptions only during
 *     presentation. The source Workspace record and user-defined slug stay unchanged.
 */
final readonly class PersonalWorkspacePresentationProvider implements WorkspacePresentationProviderInterface
{
    /** HR: Prima mapiranja osobnih područja i prevoditelj. EN: Receives personal-space mappings and the translator. */
    public function __construct(
        private PersonalWorkspaceService $personalWorkspaces,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * HR: Grupno lokalizira samo mapirana osobna područja.
     * EN: Localizes only mapped personal Workspaces in one batch.
     *
     * @param list<array<string,mixed>> $workspaces
     * @return list<array<string,mixed>>
     */
    public function present(array $workspaces, string $locale): array
    {
        $workspaceIds = [];
        foreach ($workspaces as $workspace) {
            if (is_numeric($workspace['id'] ?? null)) {
                $workspaceIds[] = (int)$workspace['id'];
            }
        }

        $owners = $this->personalWorkspaces->presentationOwners($workspaceIds);
        foreach ($workspaces as &$workspace) {
            $workspaceId = is_numeric($workspace['id'] ?? null) ? (int)$workspace['id'] : 0;
            $ownerName = $owners[$workspaceId] ?? '';
            if ($workspaceId <= 0 || $ownerName === '') {
                continue;
            }

            $storedName = is_scalar($workspace['name'] ?? null) ? trim((string)$workspace['name']) : '';
            if (preg_match('/^(?:Područje od|Space of|Workspace of):\s*.+$/u', $storedName) === 1) {
                $workspace['name'] = sprintf(
                    $this->translator->trans('Područje od: %s', locale: $locale),
                    $ownerName,
                );
            }

            $storedDescription = is_scalar($workspace['description'] ?? null)
                ? trim((string)$workspace['description'])
                : '';
            $generatedDescription = preg_match(
                '/^(?:Osobno područje korisnika|Personal space of user|Personal Workspace of user)\s+.+\.$/u',
                $storedDescription,
            ) === 1;
            if ($generatedDescription) {
                $workspace['description'] = sprintf(
                    $this->translator->trans('Osobno područje korisnika %s.', locale: $locale),
                    $ownerName,
                );
            }

            $workspace['is_personal_workspace'] = true;
        }

        unset($workspace);

        return $workspaces;
    }
}
