<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\HeartPhrameModuleCalendar\ModuleCalendar;
use AaiEduHr\HeartPhrameModuleEditorHtml\Service\EditorPublishedVersionProviderInterface;
use AaiEduHr\HeartPhrameModuleOrm\Database\Database;
use AaiEduHr\HeartPhrameModuleWorkspace\ModuleWorkspace;
use DOMDocument;
use DOMElement;

use function array_keys;
use function array_values;
use function explode;
use function is_array;
use function is_numeric;
use function is_scalar;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function strtolower;
use function trim;

use const LIBXML_HTML_NODEFDTD;
use const LIBXML_HTML_NOIMPLIED;

/**
 * HR: Pronalazi objavljene Workspace stranice koje u trenutačnoj verziji
 *     ugrađuju zadani kalendar. Sadržaj nikada ne vraća korisniku; rezultat
 *     služi samo kasnijoj ACL provjeri dostave.
 * EN: Finds published Workspace pages whose current version embeds the requested
 *     calendar. It never returns page content to a user; the result only feeds
 *     the delivery layer's later ACL check.
 */
final readonly class EmbeddedCalendarPageResolver
{
    /** HR: Prima bazu i sigurni interni provider objavljenih verzija. EN: Receives the database and safe internal published-version provider. */
    public function __construct(
        private Database $database,
        private EditorPublishedVersionProviderInterface $versions,
    ) {
    }

    /**
     * HR: Vraća jedinstvene stranice povezane s kalendarom.
     * EN: Returns unique pages linked to the calendar.
     *
     * @return list<array{id:int,workspace_id:int,document_key:string,title:string}>
     */
    public function pagesForCalendar(int $calendarId): array
    {
        if ($calendarId <= 0) {
            return [];
        }

        return $this->calendarPageMap()[$calendarId] ?? [];
    }

    /**
     * HR: Vraća kalendare koje trenutačno objavljene stranice područja ugrađuju.
     *     Backup koristi rezultat bez izlaganja HTML sadržaja.
     * EN: Returns calendars embedded by currently published Workspace pages.
     *     Backup consumes the result without exposing HTML content.
     *
     * @return list<int>
     */
    public function calendarIdsForWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        return array_keys($this->calendarPageMap($workspaceId));
    }

    /**
     * HR: Jednim batch prolazom povezuje kalendare s objavljenim stranicama.
     * EN: Maps calendars to published pages in one batched pass.
     *
     * @return array<int,list<array{id:int,workspace_id:int,document_key:string,title:string}>>
     */
    private function calendarPageMap(?int $workspaceId = null): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        $nodes = [];
        $nodeQuery = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('is_enabled', '=', true);
        if (is_int($workspaceId) && $workspaceId > 0) {
            $nodeQuery->where('workspace_id', '=', $workspaceId);
        }

        $nodeRows = $nodeQuery->get();
        foreach ($nodeRows as $node) {
            $documentKey = is_array($node) && is_scalar($node['document_key'] ?? null)
                ? trim((string)$node['document_key'])
                : '';
            if (
                $documentKey === ''
                || !is_numeric($node['id'] ?? null)
                || !is_numeric($node['workspace_id'] ?? null)
            ) {
                continue;
            }

            $nodes[(int)$node['id']] = [
                'id' => (int)$node['id'],
                'workspace_id' => (int)$node['workspace_id'],
                'document_key' => $documentKey,
                'title' => is_scalar($node['title'] ?? null) ? trim((string)$node['title']) : '',
            ];
        }

        if ($nodes === []) {
            return [];
        }

        $requests = [];
        $nodesByDocument = [];
        $workflows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS)
            ->whereIn('node_id', array_keys($nodes))
            ->whereNotNull('published_version_number')
            ->get();
        foreach ($workflows as $workflow) {
            if (
                !is_array($workflow)
                || !is_numeric($workflow['node_id'] ?? null)
                || !is_numeric($workflow['published_version_number'] ?? null)
            ) {
                continue;
            }

            $node = $nodes[(int)$workflow['node_id']] ?? null;
            $language = is_scalar($workflow['language_code'] ?? null)
                ? strtolower(trim((string)$workflow['language_code']))
                : '';
            if (!is_array($node) || $language === '') {
                continue;
            }

            $requests[$language][$node['document_key']] = (int)$workflow['published_version_number'];
            $nodesByDocument[$node['document_key']][(int)$node['id']] = $node;
        }

        /** @var array<string,array<string,true>> $documentsByCalendarUuid */
        $documentsByCalendarUuid = [];
        foreach ($requests as $language => $versionsByDocument) {
            foreach ($this->versions->loadPublishedVersionsForIndexing($versionsByDocument, $language) as $version) {
                foreach ($this->calendarUuidsFromHtml($version->html) as $uuid) {
                    $documentsByCalendarUuid[$uuid][$version->documentId] = true;
                }
            }
        }

        if ($documentsByCalendarUuid === []) {
            return [];
        }

        $calendarRows = $this->database->table(ModuleCalendar::TABLE_CALENDARS)
            ->select(['id', 'uuid'])
            ->whereIn('uuid', array_keys($documentsByCalendarUuid))
            ->get();
        $pagesByCalendar = [];
        foreach ($calendarRows as $calendar) {
            if (!is_array($calendar) || !is_numeric($calendar['id'] ?? null)) {
                continue;
            }

            $uuid = is_scalar($calendar['uuid'] ?? null)
                ? strtolower(trim((string)$calendar['uuid']))
                : '';
            foreach (array_keys($documentsByCalendarUuid[$uuid] ?? []) as $documentKey) {
                foreach ($nodesByDocument[$documentKey] ?? [] as $nodeId => $node) {
                    $pagesByCalendar[(int)$calendar['id']][$nodeId] = $node;
                }
            }
        }

        foreach ($pagesByCalendar as $calendarId => $pages) {
            $pagesByCalendar[$calendarId] = array_values($pages);
        }

        return $pagesByCalendar;
    }

    /**
     * HR: Čita jedinstvene UUID-ove iz sigurnih calendar embed atributa.
     * EN: Reads unique UUIDs from safe calendar embed attributes.
     *
     * @return list<string>
     */
    private function calendarUuidsFromHtml(string $html): array
    {
        if ($html === '' || !str_contains(strtolower($html), 'data-calendar-uuid')) {
            return [];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                return [];
            }

            $uuids = [];
            foreach ($document->getElementsByTagName('*') as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $raw = $element->getAttribute('data-calendar-uuids')
                    ?: $element->getAttribute('data-calendar-uuid');
                foreach (explode(',', strtolower($raw)) as $candidate) {
                    $candidate = trim($candidate);
                    if ($candidate !== '') {
                        $uuids[$candidate] = true;
                    }
                }
            }

            return array_keys($uuids);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** HR: Sve tablice moraju postojati; u suprotnom integracija mirno ne radi. EN: All tables must exist; otherwise the integration fails closed. */
    private function tablesReady(): bool
    {
        $schema = $this->database->schema();

        return $schema->hasTable(ModuleCalendar::TABLE_CALENDARS)
            && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            && $schema->hasTable(ModuleWorkspace::TABLE_WORKSPACE_NODE_WORKFLOWS);
    }
}
