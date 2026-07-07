<?php

namespace Config;

use App\Libraries\Jwt;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\ConsoleClient;
use App\Services\ConsoleIdentityService;
use App\Services\CreditService;
use App\Services\LeadScoringService;
use App\Services\LlmClient;
use App\Services\ReachIntakeService;
use App\Services\RenewalReminderService;
use App\Services\SalesBotService;
use App\Services\WorkerClient;
use CodeIgniter\Config\BaseService;

class Services extends BaseService
{
    public static function jwt(bool $getShared = true): Jwt
    {
        if ($getShared) {
            return static::getSharedInstance('jwt') ?? static::jwt(false);
        }
        return new Jwt();
    }

    public static function auditService(bool $getShared = true): AuditService
    {
        if ($getShared) {
            return static::getSharedInstance('auditService') ?? static::auditService(false);
        }
        return new AuditService();
    }

    public static function consoleClient(bool $getShared = true): ConsoleClient
    {
        if ($getShared) {
            return static::getSharedInstance('consoleClient') ?? static::consoleClient(false);
        }
        return new ConsoleClient();
    }

    public static function workerClient(bool $getShared = true): WorkerClient
    {
        if ($getShared) {
            return static::getSharedInstance('workerClient') ?? static::workerClient(false);
        }
        return new WorkerClient();
    }

    public static function llmClient(bool $getShared = true): LlmClient
    {
        if ($getShared) {
            return static::getSharedInstance('llmClient') ?? static::llmClient(false);
        }
        return new LlmClient();
    }

    public static function leadScoring(bool $getShared = true): LeadScoringService
    {
        if ($getShared) {
            return static::getSharedInstance('leadScoring') ?? static::leadScoring(false);
        }
        return new LeadScoringService();
    }

    public static function approvalService(bool $getShared = true): ApprovalService
    {
        if ($getShared) {
            return static::getSharedInstance('approvalService') ?? static::approvalService(false);
        }
        return new ApprovalService();
    }

    public static function creditService(bool $getShared = true): CreditService
    {
        if ($getShared) {
            return static::getSharedInstance('creditService') ?? static::creditService(false);
        }
        return new CreditService();
    }

    public static function reachIntake(bool $getShared = true): ReachIntakeService
    {
        if ($getShared) {
            return static::getSharedInstance('reachIntake') ?? static::reachIntake(false);
        }
        return new ReachIntakeService();
    }

    public static function salesBot(bool $getShared = true): SalesBotService
    {
        if ($getShared) {
            return static::getSharedInstance('salesBot') ?? static::salesBot(false);
        }
        return new SalesBotService();
    }

    public static function renewalReminder(bool $getShared = true): RenewalReminderService
    {
        if ($getShared) {
            return static::getSharedInstance('renewalReminder') ?? static::renewalReminder(false);
        }
        return new RenewalReminderService();
    }

    public static function consoleIdentity(bool $getShared = true): ConsoleIdentityService
    {
        if ($getShared) {
            return static::getSharedInstance('consoleIdentity') ?? static::consoleIdentity(false);
        }
        return new ConsoleIdentityService();
    }
}
