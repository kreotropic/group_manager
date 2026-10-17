<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * The read side of the Users view: a light account list and the settings
 * the edit and create forms need. Every write (account fields, profile,
 * enable/disable, create, delete) goes straight to core's provisioning API
 * from the frontend, so core's own validation, password policy, rate limits
 * and audit log apply; group membership goes through GroupService, as on
 * the group side.
 *
 * Not core's GET cloud/users/details for the list: that sets up every
 * listed user's filesystem to report their storage, far too heavy for a
 * scrolling list. The details call is only made for the one user opened.
 */
class UserService {

    private const MIN_PAGE_SIZE = 1;
    private const MAX_PAGE_SIZE = 200;
    private const DEFAULT_PAGE_SIZE = 50;

    public function __construct(
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IUserSession $userSession,
        private IConfig $config,
        private IFactory $l10nFactory,
        private IL10N $l,
    ) {
    }

    /**
     * @param string $filter 'all' or 'disabled'
     * @return array{users: list<array<string, mixed>>, hasMore: bool}
     */
    public function listUsers(string $search, ?int $pageSize, int $offset, string $filter): array {
        $pageSize ??= self::DEFAULT_PAGE_SIZE;
        if ($pageSize < self::MIN_PAGE_SIZE || $pageSize > self::MAX_PAGE_SIZE || $offset < 0) {
            throw new GroupServiceException($this->l->t('Invalid pagination parameters'), 'INVALID_PAGINATION', 400);
        }
        if (!in_array($filter, ['all', 'disabled'], true)) {
            throw new GroupServiceException($this->l->t('Unknown filter'), 'INVALID_FILTER', 400);
        }

        // One extra row tells whether another page exists without a count,
        // which some backends (LDAP) can't give cheaply.
        $search = trim($search);
        $users = $filter === 'disabled'
            ? $this->userManager->getDisabledUsers($pageSize + 1, $offset, $search)
            : $this->userManager->search($search, $pageSize + 1, $offset);

        $hasMore = count($users) > $pageSize;
        $users = array_slice(array_values($users), 0, $pageSize);

        return [
            'users' => array_map(fn (IUser $user) => $this->describe($user), $users),
            'hasMore' => $hasMore,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getUser(string $uid): array {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            throw new GroupServiceException($this->l->t('User not found'), 'USER_NOT_FOUND', 404);
        }
        return $this->describe($user) + [
            // The raw setting, which core's details call can't give: it
            // reports the effective quota, so "default" and an explicit value
            // equal to the default look the same there.
            'quotaSetting' => $this->config->getUserValue($user->getUID(), 'files', 'quota', 'default'),
        ];
    }

    /**
     * What the edit and create forms need from the server's configuration.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array {
        $languages = $this->l10nFactory->getLanguages();
        $presets = array_values(array_filter(array_map(
            'trim',
            explode(',', $this->config->getAppValue('files', 'quota_preset', '1 GB, 5 GB, 10 GB')),
        ), fn (string $preset) => $preset !== '' && $preset !== 'default' && $preset !== 'none'));

        return [
            'currentUser' => $this->userSession->getUser()?->getUID(),
            'languages' => array_values(array_merge(
                $languages['commonLanguages'] ?? [],
                $languages['otherLanguages'] ?? [],
            )),
            'quotaPresets' => $presets,
            'defaultQuota' => $this->config->getAppValue('files', 'default_quota', 'none'),
            'sendEmail' => $this->config->getAppValue('core', 'newUser.sendEmail', 'yes') === 'yes',
            'requireEmail' => $this->config->getAppValue('core', 'newUser.requireEmail', 'no') === 'yes',
            'generateUserId' => $this->config->getAppValue('core', 'newUser.generateUserID', 'no') === 'yes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(IUser $user): array {
        // A user from search() can be a LazyUser whose backend resolves on
        // first access; one flaky LDAP lookup must not 500 the whole page.
        try {
            $email = $user->getEMailAddress();
        } catch (\Exception) {
            $email = null;
        }
        try {
            $enabled = $user->isEnabled();
        } catch (\Exception) {
            $enabled = true;
        }
        try {
            $backendName = $user->getBackendClassName();
        } catch (\Exception) {
            $backendName = '';
        }

        return [
            'uid' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'email' => $email,
            'enabled' => $enabled,
            'backend' => match ($backendName) {
                'Database' => 'local',
                'LDAP' => 'ldap',
                default => 'other',
            },
            'backendName' => $backendName,
            'isAdmin' => $this->groupManager->isAdmin($user->getUID()),
            'lastLogin' => $user->getLastLogin(),
        ];
    }
}
