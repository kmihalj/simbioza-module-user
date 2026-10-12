<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * EN: Renders full Simbioza User module pages.
 *
 * HR: Renderira pune stranice Simbioza User modula.
 */
final readonly class SimbiozaUserModuleViewRenderer
{
    /**
     * EN: Receives the shared response factory.
     *
     * HR: Prima zajedničku tvornicu odgovora.
     */
    public function __construct(private ResponseFactory $responses)
    {
    }

    /**
     * EN: Renders a module template through the application's main layout.
     *
     * HR: Renderira modularni predložak kroz glavni layout aplikacije.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $view, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->responses->viewForModule(ModuleSimbiozaUser::PACKAGE_NAME, $view, $data, true, $status);
    }
}
