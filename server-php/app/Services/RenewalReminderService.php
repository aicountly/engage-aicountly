<?php

namespace App\Services;

/**
 * Thin facade over SalesBot's prepare_renewal capability so cron jobs can call
 *   php spark tasks:cron
 * or an external scheduler can hit /api/v1/bot/queue with action=prepare_renewal.
 */
class RenewalReminderService
{
    public function runNow(): array
    {
        return \Config\Services::salesBot()->dispatch('prepare_renewal', ['requester_kind' => 'cron']);
    }
}
