<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Value;

/**
 * HR: Neutralni opis jedne promjene koju modul može povezati s praćenjima.
 * EN: Neutral description of one change the module can match to follows.
 */
final readonly class FollowActivity
{
    /**
     * HR: Sprema samo identifikatore i sigurne sažetke, nikada cijeli sadržaj stranice.
     * EN: Stores identifiers and safe summaries only, never full page content.
     *
     * @param array<string,mixed> $context
     */
    public function __construct(
        public string $eventKey,
        public string $targetType,
        public string $targetId,
        public string $title,
        public string $message,
        public ?int $actorUserId = null,
        public ?int $workspaceId = null,
        public ?int $pageId = null,
        public ?string $documentId = null,
        public string $importance = 'normal',
        public array $context = [],
        public ?string $relatedTitle = null,
        public ?string $relatedMessage = null,
        public ?string $dedupIdentity = null,
    ) {
    }
}
