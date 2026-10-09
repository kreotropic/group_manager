<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\GroupManager\Controller\PreferencesController;
use OCA\GroupManager\Quota\GetQuotaListener;
use OCP\User\GetQuotaEvent;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\IUserSession;

class Application extends App implements IBootstrap {
    public const APP_ID = 'group_manager';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerEventListener(GetQuotaEvent::class, GetQuotaListener::class);
    }

    /**
     * Optional top-bar shortcut to the admin page, opt-in per admin (see
     * PreferencesController). The decision is made here, not inside the
     * closure: NavigationManager requires a closure to return a complete
     * entry (it reads $entry['id'] unconditionally), so "nothing to show"
     * has to mean "never registered".
     */
    public function boot(IBootContext $context): void {
        $server = $context->getServerContainer();
        $user = $server->get(IUserSession::class)->getUser();
        if ($user === null
            || $server->get(IConfig::class)->getUserValue($user->getUID(), self::APP_ID, PreferencesController::KEY_QUICK_ACCESS, '0') !== '1'
            || !$server->get(IGroupManager::class)->isAdmin($user->getUID())) {
            return;
        }

        $server->get(INavigationManager::class)->add(function () use ($server): array {
            $url = $server->get(IURLGenerator::class);
            return [
                'id' => self::APP_ID,
                'order' => 90,
                'href' => $url->linkToRoute('settings.AdminSettings.index', ['section' => self::APP_ID]),
                'icon' => $url->imagePath(self::APP_ID, 'app-header.svg'),
                'name' => $server->get(IFactory::class)->get(self::APP_ID)->t('Group Manager'),
            ];
        });
    }
}
