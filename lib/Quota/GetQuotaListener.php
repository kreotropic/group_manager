<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Quota;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\GetQuotaEvent;
use Psr\Log\LoggerInterface;

/**
 * Hands Nextcloud's own quota wrapper the group-aware quota. Fails open: this
 * runs for every account on every request that reads a quota, so any error
 * here must leave the account's regular quota in place, never block a write.
 *
 * @template-implements IEventListener<GetQuotaEvent>
 */
class GetQuotaListener implements IEventListener {
    public function __construct(
        private QuotaResolver $resolver,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof GetQuotaEvent) {
            return;
        }
        try {
            if (!$this->resolver->isEnforced()) {
                return;
            }
            $bytes = $this->resolver->effectiveFor($event->getUser());
            if ($bytes !== null) {
                $event->setQuota($bytes . ' B');
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Group quota could not be resolved, leaving the personal quota in place', [
                'app' => 'group_manager',
                'exception' => $e,
            ]);
        }
    }
}
