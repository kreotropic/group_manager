<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class AdminSection implements IIconSection {

    public function __construct(
        private IL10N $l,
        private IURLGenerator $url,
    ) {
    }

    public function getID(): string {
        return 'group_manager';
    }

    public function getName(): string {
        return $this->l->t('Group Manager');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->url->imagePath('group_manager', 'app-dark.svg');
    }
}
