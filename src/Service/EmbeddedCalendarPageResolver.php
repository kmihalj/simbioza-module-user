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
        if ($calendarId <= 0 || !$this->tablesReady()) {
            return [];
        }

        $calendar = $this->database->table(ModuleCalendar::TABLE_CALENDARS)
            ->select(['uuid'])
            ->where('id', '=', $calendarId)
            ->first();
        $uuid = is_array($calendar) && is_scalar($calendar['uuid'] ?? null)
            ? strtolower(trim((string)$calendar['uuid']))
            : '';
        if ($uuid === '') {
            return [];
        }

        $nodes = [];
        $nodeRows = $this->database->table(ModuleWorkspace::TABLE_WORKSPACE_NODES)
            ->where('is_enabled', '=', true)
            ->get();
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
        $nodeByDocument = [];
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
            $nodeByDocument[$node['document_key']] = $node;
        }

        $matched = [];
        foreach ($requests as $language => $versionsByDocument) {
            foreach ($this->versions->loadPublishedVersionsForIndexing($versionsByDocument, $language) as $version) {
                if (!$this->htmlReferencesCalendar($version->html, $uuid)) {
                    continue;
                }

                $node = $nodeByDocument[$version->documentId] ?? null;
                if (is_array($node)) {
                    $matched[(int)$node['id']] = $node;
                }
            }
        }

        return array_values($matched);
    }

    /** HR: Potvrđuje točan UUID u calendar embed atributu. EN: Confirms the exact UUID in a calendar-embed attribute. */
    private function htmlReferencesCalendar(string $html, string $uuid): bool
    {
        if ($html === '' || !str_contains(strtolower($html), $uuid)) {
            return false;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadHTML('<div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD)) {
                return false;
            }

            foreach ($document->getElementsByTagName('*') as $element) {
                if (!$element instanceof DOMElement) {
                    continue;
                }

                $raw = $element->getAttribute('data-calendar-uuids')
                    ?: $element->getAttribute('data-calendar-uuid');
                foreach (explode(',', strtolower($raw)) as $candidate) {
                    if (trim($candidate) === $uuid) {
                        return true;
                    }
                }
            }

            return false;
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
