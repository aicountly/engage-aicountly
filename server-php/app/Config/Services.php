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
use App\Services\PartnerPortalClient;
use App\Services\ReachIntakeService;
use App\Services\RenewalReminderService;
use App\Services\SalesBotService;
use App\Services\StaffAccessService;
use App\Services\WorkerClient;
use App\Services\LeadLifecycleService;
use App\Services\ReachOutcomeOutbox;
use App\Services\Payouts\FinancePayoutOutbox;
use App\Services\Payouts\SellerPayoutService;
use App\Services\Seller\CommissionService;
use App\Services\Seller\SellerAccountService;
use App\Services\Seller\SellerAdminService;
use App\Services\Seller\SellerAgreementService;
use App\Services\Seller\SellerApplicationService;
use App\Services\Seller\SellerAuthService;
use App\Services\Seller\SellerKycService;
use App\Services\Seller\SellerLeadService;
use App\Services\Seller\SellerMailer;
use App\Services\Licensing\AccountCompanyService;
use App\Services\Licensing\LicenceService;
use App\Services\Licensing\LicenceTypeService;
use App\Services\Licensing\ManageReadService;
use App\Services\Licensing\OfferService;
use App\Services\Licensing\EntitlementOutbox;
use App\Services\Licensing\ProvisioningService;
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

    public static function partnerPortalClient(bool $getShared = true): PartnerPortalClient
    {
        if ($getShared) {
            return static::getSharedInstance('partnerPortalClient') ?? static::partnerPortalClient(false);
        }
        return new PartnerPortalClient();
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

    public static function staffAccess(bool $getShared = true): StaffAccessService
    {
        if ($getShared) {
            return static::getSharedInstance('staffAccess') ?? static::staffAccess(false);
        }
        return new StaffAccessService();
    }

    // Seller programme (docs/SELLER_PROGRAM.md).

    public static function sellerAccounts(bool $getShared = true): SellerAccountService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerAccounts') ?? static::sellerAccounts(false);
        }
        return new SellerAccountService();
    }

    public static function sellerMailer(bool $getShared = true): SellerMailer
    {
        if ($getShared) {
            return static::getSharedInstance('sellerMailer') ?? static::sellerMailer(false);
        }
        return new SellerMailer();
    }

    public static function sellerAuth(bool $getShared = true): SellerAuthService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerAuth') ?? static::sellerAuth(false);
        }
        return new SellerAuthService();
    }

    public static function sellerApplications(bool $getShared = true): SellerApplicationService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerApplications') ?? static::sellerApplications(false);
        }
        return new SellerApplicationService();
    }

    public static function sellerAdmin(bool $getShared = true): SellerAdminService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerAdmin') ?? static::sellerAdmin(false);
        }
        return new SellerAdminService();
    }

    public static function sellerKyc(bool $getShared = true): SellerKycService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerKyc') ?? static::sellerKyc(false);
        }
        return new SellerKycService();
    }

    public static function sellerLeads(bool $getShared = true): SellerLeadService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerLeads') ?? static::sellerLeads(false);
        }
        return new SellerLeadService();
    }

    public static function sellerAgreements(bool $getShared = true): SellerAgreementService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerAgreements') ?? static::sellerAgreements(false);
        }
        return new SellerAgreementService();
    }

    public static function commissions(bool $getShared = true): CommissionService
    {
        if ($getShared) {
            return static::getSharedInstance('commissions') ?? static::commissions(false);
        }
        return new CommissionService();
    }

    public static function reachOutcomes(bool $getShared = true): ReachOutcomeOutbox
    {
        if ($getShared) {
            return static::getSharedInstance('reachOutcomes') ?? static::reachOutcomes(false);
        }
        return new ReachOutcomeOutbox();
    }

    public static function leadLifecycle(bool $getShared = true): LeadLifecycleService
    {
        if ($getShared) {
            return static::getSharedInstance('leadLifecycle') ?? static::leadLifecycle(false);
        }
        return new LeadLifecycleService();
    }

    public static function financePayouts(bool $getShared = true): FinancePayoutOutbox
    {
        if ($getShared) {
            return static::getSharedInstance('financePayouts') ?? static::financePayouts(false);
        }
        return new FinancePayoutOutbox();
    }

    public static function sellerPayouts(bool $getShared = true): SellerPayoutService
    {
        if ($getShared) {
            return static::getSharedInstance('sellerPayouts') ?? static::sellerPayouts(false);
        }
        return new SellerPayoutService();
    }

    // Licensing, Phase A (docs/LICENSING.md).

    public static function licenceTypes(bool $getShared = true): LicenceTypeService
    {
        if ($getShared) {
            return static::getSharedInstance('licenceTypes') ?? static::licenceTypes(false);
        }
        return new LicenceTypeService();
    }

    public static function offers(bool $getShared = true): OfferService
    {
        if ($getShared) {
            return static::getSharedInstance('offers') ?? static::offers(false);
        }
        return new OfferService();
    }

    public static function accountCompanies(bool $getShared = true): AccountCompanyService
    {
        if ($getShared) {
            return static::getSharedInstance('accountCompanies') ?? static::accountCompanies(false);
        }
        return new AccountCompanyService();
    }

    public static function licences(bool $getShared = true): LicenceService
    {
        if ($getShared) {
            return static::getSharedInstance('licences') ?? static::licences(false);
        }
        return new LicenceService();
    }

    public static function manageLicensing(bool $getShared = true): ManageReadService
    {
        if ($getShared) {
            return static::getSharedInstance('manageLicensing') ?? static::manageLicensing(false);
        }
        return new ManageReadService();
    }

    /** Engage → Manage entitlement push outbox (docs/LICENSING.md §6). */
    public static function entitlementOutbox(bool $getShared = true): EntitlementOutbox
    {
        if ($getShared) {
            return static::getSharedInstance('entitlementOutbox') ?? static::entitlementOutbox(false);
        }
        return new EntitlementOutbox();
    }

    /** Basic provisioning (contract §3.2). */
    public static function provisioning(bool $getShared = true): ProvisioningService
    {
        if ($getShared) {
            return static::getSharedInstance('provisioning') ?? static::provisioning(false);
        }
        return new ProvisioningService();
    }
}
