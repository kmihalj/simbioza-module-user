<?php

declare(strict_types=1);

namespace AaiEduHr\SimbiozaModuleUser\Api;

use AaiEduHr\HeartPhrameModuleApi\Contract\ApiExtensionInterface;
use AaiEduHr\HeartPhrameModuleApi\Contract\ApiRouteRegistry;

/**
 * HR: Oglašava API rute osobnih praćenja zajedničkoj API jezgri.
 * EN: Advertises personal-follow routes to the shared API core.
 */
final readonly class SimbiozaUserApiExtension implements ApiExtensionInterface
{
    /**
     * HR: Vraća stabilni identifikator API proširenja.
     * EN: Returns the stable API-extension identifier.
     */
    public function id(): string
    {
        return 'simbioza-user';
    }

    /**
     * HR: Registrira sve rute modula u zajedničkom API registru.
     * EN: Registers every module route in the shared API registry.
     */
    public function register(ApiRouteRegistry $routes): void
    {
        foreach ($this->routeDefinitions() as [$method, $path, $action, $name]) {
            $routes->add($method, $path, SimbiozaUserResourceController::class, $action, $name);
        }
    }

    /**
     * HR: Vraća zatvoreni popis metoda, putanja, akcija i naziva ruta.
     * EN: Returns the closed list of methods, paths, actions, and route names.
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
        ];
    }
}
