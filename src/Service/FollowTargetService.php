<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleAuth\ModuleAuth;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceAccessService;
use AaiEduHr\HeartPhrameModuleWorkspace\Service\WorkspaceRepository;
use AaiEduHr\SimbiozaModuleUser\Contract\FollowTargetResolverInterface;
use HeartPhrame\Routing\UrlGenerator;
use Psr\Container\ContainerInterface;

use function is_array;
use function is_numeric;
use function is_object;
use function is_scalar;
use function method_exists;
use function rawurlencode;
use function rtrim;
use function trim;

/**
 * HR: Jedino mjesto koje pretvara polimorfno praćenje u naziv, poveznicu i
 *     aktualnu ACL odluku bez otkrivanja nedostupnog sadržaja.
 * EN: The single place that turns a polymorphic follow into a label, link, and
 *     current ACL decision without disclosing inaccessible content.
 *
 * @phpstan-type TargetDescriptor array{
 *     accessible:bool,
 *     type:string,
 *     id:string,
 *     label:string,
 *     url:string,
 *     workspace_id:?int,
 *     page_id:?int,
 *     document_id:?string
 * }
 */
final readonly class FollowTargetService implements FollowTargetResolverInterface
{
    public const TYPE_WORKSPACE = 'workspace';

    public const TYPE_PAGE = 'page';

    public const TYPE_CALENDAR = 'calendar';

    public const TYPE_TASK_LIST = 'task_list';

    /** @var list<string> */
    public const TYPES = [self::TYPE_WORKSPACE, self::TYPE_PAGE, self::TYPE_CALENDAR, self::TYPE_TASK_LIST];

    /**
     * HR: Prima obavezne Workspace servise i container samo za opcionalne integracije.
     * EN: Receives required Workspace services and the container only for optional integrations.
     */
    public function __construct(
        private Database $database,
        private WorkspaceRepository $workspaces,
        private WorkspaceAccessService $access,
        private UrlGenerator $urls,
        private ContainerInterface $container,
    ) {
    }

    /**
     * HR: Vraća siguran opis cilja za određenog korisnika.
     * EN: Returns a safe target descriptor for a specific user.
     *
     * @param array<string,mixed> $context
     * @return array{accessible:bool,type:string,id:string,label:string,url:string,workspace_id:?int,page_id:?int,document_id:?string}
     */
    public function describe(string $type, string $id, int $userId, array $context = []): array
    {
        $type = trim($type);
        $id = trim($id);
        $empty = [
            'accessible' => false,
            'type' => $type,
            'id' => $id,
            'label' => __('Nedostupan sadržaj'),
            'url' => '',
            'workspace_id' => null,
            'page_id' => null,
            'document_id' => null,
        ];
        if ($userId <= 0 || $id === '') {
            return $empty;
        }

        return match ($type) {
            self::TYPE_WORKSPACE => $this->workspaceDescriptor((int)$id, $userId, $empty),
            self::TYPE_PAGE => $this->pageDescriptor((int)$id, $userId, $empty),
            self::TYPE_CALENDAR => $this->calendarDescriptor((int)$id, $userId, $empty),
            self::TYPE_TASK_LIST => $this->taskListDescriptor($id, $userId, $context, $empty),
            default => $empty,
        };
    }

    /**
     * HR: Učitava minimalni auth payload potreban ACL servisima.
     * EN: Loads the minimal auth payload required by ACL services.
     *
     * @return array<string,mixed>|null
     */
    public function userPayload(int $userId): ?array
    {
        if ($userId <= 0 || !$this->database->schema()->hasTable(ModuleAuth::TABLE_AUTH_USERS)) {
            return null;
        }

        $row = $this->database->table(ModuleAuth::TABLE_AUTH_USERS)
            ->where('id', '=', $userId)
            ->where('is_active', '=', true)
            ->first();

        return is_array($row) ? $this->stringKeys($row) : null;
    }

    /**
     * HR: Razrješava područje i primjenjuje njegovo trenutačno pravo pregleda.
     * EN: Resolves a workspace and applies its current view permission.
     *
     * @param TargetDescriptor $empty
     * @return TargetDescriptor
     */
    private function workspaceDescriptor(int $workspaceId, int $userId, array $empty): array
    {
        $workspace = $workspaceId > 0 ? $this->workspaces->findWorkspaceById($workspaceId) : null;
        $user = $this->userPayload($userId);
        if (!is_array($workspace) || !is_array($user)) {
            return $empty;
        }

        $permissions = $this->access->workspacePermissions($workspace, $user);
        if (!(bool)($permissions['can_view'] ?? false)) {
            return $empty;
        }

        $slug = $this->text($workspace['slug'] ?? null);
        $url = $this->path('workspace.show', '/w/' . rawurlencode($slug), ['workspaceSlug' => $slug]);

        return [
            ...$empty,
            'accessible' => true,
            'label' => $this->text($workspace['name'] ?? null) ?: __('Područje'),
            'url' => $url,
            'workspace_id' => $workspaceId,
        ];
    }

    /**
     * HR: Razrješava stranicu, nadređeno područje i dodatni page ACL.
     * EN: Resolves a page, its workspace, and the additional page ACL.
     *
     * @param TargetDescriptor $empty
     * @return TargetDescriptor
     */
    private function pageDescriptor(int $pageId, int $userId, array $empty): array
    {
        $page = $pageId > 0 ? $this->workspaces->findNodeById($pageId) : null;
        $workspaceId = is_array($page) && is_numeric($page['workspace_id'] ?? null)
            ? (int)$page['workspace_id']
            : 0;
        $workspace = $workspaceId > 0 ? $this->workspaces->findWorkspaceById($workspaceId) : null;
        $user = $this->userPayload($userId);
        if (!is_array($page) || !is_array($workspace) || !is_array($user)) {
            return $empty;
        }

        $permissions = $this->access->nodePermissions($workspace, $page, $user);
        if (!(bool)($permissions['can_view'] ?? false)) {
            return $empty;
        }

        $workspaceSlug = $this->text($workspace['slug'] ?? null);
        $pageSlug = $this->text($page['slug'] ?? null);
        $url = $this->path(
            'workspace.node.show',
            '/w/' . rawurlencode($workspaceSlug) . '/' . rawurlencode($pageSlug),
            ['workspaceSlug' => $workspaceSlug, 'nodeSlug' => $pageSlug],
        );

        return [
            ...$empty,
            'accessible' => true,
            'label' => $this->text($page['title'] ?? null) ?: __('Stranica'),
            'url' => $url,
            'workspace_id' => $workspaceId,
            'page_id' => $pageId,
            'document_id' => is_scalar($page['document_key'] ?? null)
                ? trim((string)$page['document_key']) ?: null
                : null,
        ];
    }

    /**
     * HR: Razrješava opcionalni kalendar kroz njegov vlasnički ACL servis.
     * EN: Resolves an optional calendar through its owning ACL service.
     *
     * @param TargetDescriptor $empty
     * @return TargetDescriptor
     */
    private function calendarDescriptor(int $calendarId, int $userId, array $empty): array
    {
        $service = \AaiEduHr\HeartPhrameModuleCalendar\Service\CalendarManagerInterface::class;
        if ($calendarId <= 0 || !interface_exists($service)) {
            return $empty;
        }

        try {
            $manager = $this->container->get($service);
            $user = $this->userPayload($userId);
            $calendar = is_object($manager) && method_exists($manager, 'calendar')
                ? $manager->calendar($calendarId, $user)
                : null;
        } catch (\Throwable) {
            return $empty;
        }

        if (!is_array($calendar)) {
            return $empty;
        }

        $uuid = $this->text($calendar['uuid'] ?? null);

        return [
            ...$empty,
            'accessible' => true,
            'label' => $this->text($calendar['name'] ?? null) ?: __('Kalendar'),
            'url' => $this->path(
                'calendar.show',
                '/calendars/view/' . rawurlencode($uuid),
                ['calendarUuid' => $uuid],
            ),
        ];
    }

    /**
     * HR: Veže listu zadataka uz čitljivu Workspace stranicu i nasljeđuje njezin ACL.
     * EN: Binds a task list to a readable Workspace page and inherits its ACL.
     *
     * @param array<string,mixed> $context
     * @param TargetDescriptor $empty
     * @return TargetDescriptor
     */
    private function taskListDescriptor(string $listId, int $userId, array $context, array $empty): array
    {
        $documentId = is_scalar($context['document_id'] ?? null) ? trim((string)$context['document_id']) : '';
        $page = $documentId !== '' ? $this->workspaces->findNodeByDocumentKey($documentId) : null;
        if (!is_array($page) || !is_numeric($page['id'] ?? null)) {
            return $empty;
        }

        $pageDescriptor = $this->pageDescriptor((int)$page['id'], $userId, $empty);
        if (!(bool)($pageDescriptor['accessible'] ?? false)) {
            return $empty;
        }

        return [
            ...$pageDescriptor,
            'type' => self::TYPE_TASK_LIST,
            'id' => $listId,
            'label' => $this->text($context['label_snapshot'] ?? null) !== ''
                ? $this->text($context['label_snapshot'] ?? null)
                : __('Lista zadataka'),
            'document_id' => $documentId,
        ];
    }

    /**
     * HR: Gradi imenovanu putanju ili sigurni fallback pod instalacijskim prefiksom.
     * EN: Builds a named path or a safe fallback under the installation prefix.
     *
     * @param array<string,scalar> $parameters
     */
    private function path(string $name, string $fallback, array $parameters = []): string
    {
        if ($this->urls->namedRouteExists($name)) {
            return $this->urls->getPathFor($name, $parameters);
        }

        return rtrim($this->urls->getBasePath(), '/') . $fallback;
    }

    /**
     * HR: Filtrira ORM redak na tekstualne ključeve.
     * EN: Filters an ORM row to string keys.
     *
     * @param array<array-key,mixed> $row
     * @return array<string,mixed>
     */
    private function stringKeys(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** HR: Sigurno normalizira skalarni ORM ili opcionalni podatak. EN: Safely normalizes scalar ORM or optional data. */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }
}
