<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings {

    public function getForm(): TemplateResponse {
        return new TemplateResponse('group_manager', 'admin');
    }

    public function getSection(): string {
        return 'group_manager';
    }

    public function getPriority(): int {
        return 50;
    }
}
