<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Note: the /{gid}/members route must be declared before the bare /{gid}
// routes — {gid} matches "engineering/members" too and, being listed first,
// would otherwise win the match and 404 inside the service instead.
return [
    'routes' => [
        ['name' => 'group#index', 'url' => '/api/groups', 'verb' => 'GET'],
        ['name' => 'group#create', 'url' => '/api/groups', 'verb' => 'POST'],
        ['name' => 'group#members', 'url' => '/api/groups/{gid}/members', 'verb' => 'GET', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#show', 'url' => '/api/groups/{gid}', 'verb' => 'GET', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#rename', 'url' => '/api/groups/{gid}', 'verb' => 'PUT', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#destroy', 'url' => '/api/groups/{gid}', 'verb' => 'DELETE', 'requirements' => ['gid' => '[^/]+']],
    ],
];
