<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Api;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface;
use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;

/**
 * EN: Advertises personal-follow routes to the shared API core.
 *
 * HR: Oglašava API rute osobnih praćenja zajedničkoj API jezgri.
 */
final readonly class SimbiozaUserApiExtension implements ApiExtensionInterface
{
    /**
     * EN: Returns the stable API-extension identifier.
     *
     * HR: Vraća stabilni identifikator API proširenja.
     */
    public function id(): string
    {
        return 'simbioza-user';
    }

    /**
     * EN: Registers every module route in the shared API registry.
     *
     * HR: Registrira sve rute modula u zajedničkom API registru.
     */
    public function register(ApiRouteRegistry $routes): void
    {
        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $routes->add($method, $path, SimbiozaUserResourceController::class, $action, $name);
        }
    }

    /**
     * EN: Returns the closed list of methods, paths, actions, and route names.
     *
     * HR: Vraća zatvoreni popis metoda, putanja, akcija i naziva ruta.
     *
     * @return list<array{string,string,string,string}>
     */
    private function routeDefinitions(): array
    {
        return [
            ['GET', '/api/v1/me/follows', 'listFollows', 'api.v1.me.follows.list'],
            ['POST', '/api/v1/me/follows', 'createFollow', 'api.v1.me.follows.create'],
            ['DELETE', '/api/v1/me/follows/{type}/{targetId}', 'deleteFollow', 'api.v1.me.follows.delete'],
            ['GET', '/api/v1/me/follow-preferences', 'getPreferences', 'api.v1.me.follow-preferences.get'],
            ['PATCH', '/api/v1/me/follow-preferences', 'updatePreferences', 'api.v1.me.follow-preferences.update'],
            ['GET', '/api/v1/me/personal-workspace', 'getPersonalWorkspace', 'api.v1.me.personal-workspace.get'],
        ];
    }
}
