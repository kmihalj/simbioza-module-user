<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Service;

use AaiEduHr\SimbiozaModuleUser\ModuleSimbiozaUser;
use HeartPhrame\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;

/** HR: Renderira pune stranice Simbioza User modula. EN: Renders full Simbioza User module pages. */
final readonly class SimbiozaUserModuleViewRenderer
{
    /** HR: Prima zajedničku tvornicu odgovora. EN: Receives the shared response factory. */
    public function __construct(private ResponseFactory $responses)
    {
    }

    /**
     * HR: Renderira modularni predložak kroz glavni layout aplikacije.
     * EN: Renders a module template through the application's main layout.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $view, array $data = [], int $status = 200): ResponseInterface
    {
        return $this->responses->viewForModule(ModuleSimbiozaUser::PACKAGE_NAME, $view, $data, true, $status);
    }
}
