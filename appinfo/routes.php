<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// Note: routes with a longer, more specific path must be declared before a
// shorter route sharing its prefix — a greedy {gid}/{uid} match on a route
// listed first would otherwise win and 404 inside the service instead. Every
// {gid}/{uid} requirement is scoped to '[^/]+' for the same reason (a bare
// id can't contain a slash anyway).
return [
    'routes' => [
        ['name' => 'group#index', 'url' => '/api/groups', 'verb' => 'GET'],
        ['name' => 'group#create', 'url' => '/api/groups', 'verb' => 'POST'],
        ['name' => 'group#candidates', 'url' => '/api/groups/{gid}/candidates', 'verb' => 'GET', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#removeMember', 'url' => '/api/groups/{gid}/members/{uid}', 'verb' => 'DELETE', 'requirements' => ['gid' => '[^/]+', 'uid' => '[^/]+']],
        ['name' => 'group#addMember', 'url' => '/api/groups/{gid}/members', 'verb' => 'POST', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#members', 'url' => '/api/groups/{gid}/members', 'verb' => 'GET', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#show', 'url' => '/api/groups/{gid}', 'verb' => 'GET', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#rename', 'url' => '/api/groups/{gid}', 'verb' => 'PUT', 'requirements' => ['gid' => '[^/]+']],
        ['name' => 'group#destroy', 'url' => '/api/groups/{gid}', 'verb' => 'DELETE', 'requirements' => ['gid' => '[^/]+']],
    ],
];
