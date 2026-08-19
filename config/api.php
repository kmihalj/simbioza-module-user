<?php

/**
 * HR: Simbioza User oglašava samo osobna praćenja vlasnika API ključa.
 * EN: Simbioza User advertises only follows owned by the API-key owner.
 */

declare(strict_types=1);

return [
    'module' => 'simbioza-user',
    'extension' => \AaiEduHr\SimbiozaModuleUser\Api\SimbiozaUserApiExtension::class,
    'resources' => [
        'follows' => [
            'label' => ['hr' => 'Osobna praćenja', 'en' => 'Personal follows'],
            'description' => [
                'hr' => 'Pregled i upravljanje vlastitim praćenjima i pravilima dostave.',
                'en' => 'Read and manage personal follows and delivery preferences.',
            ],
            'scopes' => [
                'follows:read' => [
                    'label' => ['hr' => 'Čitanje', 'en' => 'Read'],
                    'description' => [
                        'hr' => 'Dohvat vlastitih praćenja i osobnih postavki.',
                        'en' => 'Read personal follows and preferences.',
                    ],
                ],
                'follows:write' => [
                    'label' => ['hr' => 'Upravljanje', 'en' => 'Manage'],
                    'description' => [
                        'hr' => 'Dodavanje i uklanjanje vlastitih praćenja te izmjena postavki.',
                        'en' => 'Add and remove personal follows and change preferences.',
                    ],
                ],
            ],
        ],
    ],
];
