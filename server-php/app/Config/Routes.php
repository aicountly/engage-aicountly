<?php

namespace Config;

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', static function () {
    return service('response')->setJSON([
        'ok'      => true,
        'service' => 'aicountly-engage-api',
        'version' => 'v1',
        'docs'    => '/api/v1',
    ]);
});

$routes->get('/health', static function () {
    $jwtSecret = (string) env('ENGAGE_JWT_SECRET', '');
    $jwtOk     = $jwtSecret !== '' && strlen($jwtSecret) >= 32;
    $dbUser    = (string) env('ENGAGE_DB_USER', '');
    $dbName    = (string) env('ENGAGE_DB_NAME', '');
    $dbOk      = $dbUser !== '' && $dbName !== '';
    $consoleOk = (string) env('CONSOLE_API_BASE_URL', '') !== '' && (string) env('CONSOLE_API_TOKEN', '') !== '';
    $roleSyncOk = (string) env('CONSOLE_API_URL', '') !== '' && (string) env('CONSOLE_API_TOKEN', '') !== '';
    $workerOk  = (string) env('WORKER_BASE_URL', '') !== '' && (string) env('WORKER_API_TOKEN', '') !== '';
    $reachOk   = (string) env('REACH_INBOUND_TOKEN', '') !== '';
    $svcKeyOk  = (string) env('ENGAGE_SERVICE_KEY', '') !== '';
    $partnerOk = (string) env('PARTNER_PORTAL_API_URL', '') !== '' && (string) env('PARTNER_PORTAL_ADMIN_KEY', '') !== '';
    $sellerKeyOk = strlen(trim((string) env('SELLER_PORTAL_KEY', ''))) >= 32;
    $dataKeyOk = (new \App\Libraries\Seller\SellerCrypto())->isConfigured();

    $dbAlive = false;
    if ($dbOk) {
        try {
            $db = \Config\Database::connect();
            $db->query('SELECT 1');
            $dbAlive = true;
        } catch (\Throwable $e) {
            $dbAlive = false;
        }
    }

    $ok = $jwtOk && $dbOk && $dbAlive;

    return service('response')->setJSON([
        'ok'        => $ok,
        'service'   => 'aicountly-engage-api',
        'status'    => $ok ? 'ready' : 'misconfigured',
        'timestamp' => gmdate('c'),
        'checks'    => [
            'jwt_secret'          => $jwtOk ? 'ok' : 'missing or too short (need 32+ chars in api/.env)',
            'db_env'              => $dbOk ? 'ok' : 'missing ENGAGE_DB_USER / ENGAGE_DB_NAME',
            'db_connection'       => $dbAlive ? 'ok' : 'unreachable',
            'console_outbound'    => $consoleOk ? 'ok' : 'CONSOLE_API_* not configured',
            'console_role_sync'   => $roleSyncOk ? 'ok' : 'CONSOLE_API_URL / CONSOLE_API_TOKEN not set — token refresh cannot re-check roles with Console (required in production)',
            'worker_outbound'     => $workerOk ? 'ok' : 'WORKER_* not configured',
            'reach_inbound_token' => $reachOk ? 'ok' : 'REACH_INBOUND_TOKEN not set',
            'console_inbound_key' => $svcKeyOk ? 'ok' : 'ENGAGE_SERVICE_KEY not set',
            'partner_portal'      => $partnerOk ? 'ok' : 'PARTNER_PORTAL_API_URL / PARTNER_PORTAL_ADMIN_KEY not configured — Partner Master screen will fail',
            'seller_portal_key'   => $sellerKeyOk ? 'ok' : 'SELLER_PORTAL_KEY missing or shorter than 32 characters — seller.aicountly.com gets 503',
            'seller_data_key'     => $dataKeyOk ? 'ok' : 'ENGAGE_DATA_KEY not set — seller KYC cannot be stored (503)',
            'seller_mailer'       => \Config\Services::sellerMailer()->status(),
            'reach_outcomes'      => \Config\Services::reachOutcomes()->status(),
            'seller_payouts'      => 'pending — weekly payouts are not built yet (docs/SELLER_PROGRAM.md)',
        ],
    ]);
});

$routes->group('v1', static function ($routes) {
    // Public auth endpoints — no JWT.
    $routes->post('auth/login', 'Api\\V1\\AuthController::login');
    $routes->get('auth/sso-callback', 'Api\\V1\\AuthController::ssoCallback');
    $routes->post('auth/controller-sso', 'Api\\V1\\AuthController::controllerSso');
    $routes->post('auth/console-session', 'Api\\V1\\AuthController::consoleSession');
    $routes->post('auth/refresh', 'Api\\V1\\AuthController::refresh');

    // Portal endpoints Console calls back into Engage.
    // Auth: Authorization: Bearer <ENGAGE_SERVICE_KEY>
    $routes->group('portal/bot', ['filter' => 'console-portal'], static function ($routes) {
        $routes->get('health',              'Portal\\HealthController::show');
        $routes->get('reports',             'Portal\\ReportsController::index');
        $routes->get('reports/(:num)',      'Portal\\ReportsController::show/$1');
        $routes->put('mode',                'Portal\\ModeController::update');
        $routes->post('approval-callback',  'Portal\\ApprovalCallbackController::store');
    });

    // seller.aicountly.com BFF → Engage (docs/SELLER_PROGRAM.md). Every route: X-Seller-Portal-Key
    // (SELLER_PORTAL_KEY); signed-in routes also X-Seller-Session. Idempotency-Key replays writes.
    $routes->group('seller-portal', ['filter' => 'seller-portal-key'], static function ($routes) {
        // Shapes: seller-aicountly docs/ENGAGE_SELLER_API.md (27 endpoints) + a few Engage extras.
        $P = 'SellerPortal\\';
        $idem    = ['filter' => 'idempotency'];
        $session = ['filter' => 'seller-session'];
        $sessionIdem = ['filter' => ['seller-session', 'idempotency']];

        $routes->get('health',                        $P . 'HealthController::show');
        $routes->post('applications',                 $P . 'ApplicationsController::create', $idem);
        $routes->post('auth/login',                   $P . 'AuthController::login');
        $routes->post('auth/login/otp',               $P . 'AuthController::otp');
        $routes->post('auth/logout',                  $P . 'AuthController::logout');
        $routes->post('auth/activation/inspect',      $P . 'AuthController::activationInspect');
        $routes->post('auth/activation',              $P . 'AuthController::activate');
        $routes->post('auth/password-reset/request',  $P . 'AuthController::resetRequest');
        $routes->post('auth/password-reset/inspect',  $P . 'AuthController::resetInspect');
        $routes->post('auth/password-reset',          $P . 'AuthController::reset');

        $routes->get('me',                            $P . 'AccountController::me', $session);
        $routes->patch('me',                          $P . 'AccountController::updateMe', $sessionIdem);
        $routes->post('me/password',                  $P . 'AccountController::password', $session);
        $routes->get('dashboard',                     $P . 'AccountController::dashboard', $session);
        $routes->get('agreement',                     $P . 'AccountController::agreement', $session);
        $routes->post('agreement/acceptances',        $P . 'AccountController::acceptAgreement', $sessionIdem);
        $routes->get('referral',                      $P . 'AccountController::referral', $session);
        $routes->get('kyc',                           $P . 'KycController::show', $session);
        $routes->put('kyc',                           $P . 'KycController::saveDraft', $sessionIdem);
        $routes->post('kyc/submission',               $P . 'KycController::submit', $sessionIdem);
        $routes->post('kyc/documents',                $P . 'KycController::upload', $sessionIdem);
        $routes->delete('kyc/documents/(:segment)',   $P . 'KycController::deleteDocument/$1', $sessionIdem);
        $routes->get('kyc/documents/(:segment)',      $P . 'KycController::download/$1', $session);
        $routes->post('leads',                        $P . 'LeadsController::create', $sessionIdem);
        $routes->get('leads',                         $P . 'LeadsController::index', $session);
        $routes->get('leads/(:segment)',              $P . 'LeadsController::show/$1', $session);
        $routes->get('commissions',                   $P . 'EarningsController::commissions', $session);
        $routes->get('payouts',                       $P . 'EarningsController::payouts', $session);
        $routes->get('payouts/(:segment)',            $P . 'EarningsController::payout/$1', $session);
        $routes->get('payouts/(:segment)/statement',  $P . 'EarningsController::payout/$1', $session);
    });

    // Internal Reach ingest — validated by X-Portal-Token header.
    $routes->group('internal', ['filter' => 'internal-token'], static function ($routes) {
        $routes->post('reach/leads', 'Internal\\ReachController::ingest');
    });

    // Authenticated portal endpoints (staff JWT). Roles are assigned in
    // Console → Roles & Access and mirrored at sign-in / refresh:
    //   super_admin (Engage Administrator) — everything
    //   member      (Engage Member)        — everything except role:super_admin routes
    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        $routes->get('me', 'Api\\V1\\AuthController::me');
        $routes->post('auth/logout', 'Api\\V1\\AuthController::logout');
        $routes->get('auth/controller-apps/launcher', 'Api\\V1\\AuthController::controllerAppsLauncher');
        $routes->get('auth/sso/launch-url', 'Api\\V1\\AuthController::ssoLaunchUrl');

        // Settings & audit.
        $routes->get('settings', 'Api\\V1\\SettingsController::index');
        $routes->put('settings', 'Api\\V1\\SettingsController::update', ['filter' => 'role:super_admin']);
        $routes->get('audit-logs', 'Api\\V1\\AuditLogsController::index');

        // Dashboard rollups.
        $routes->get('dashboard/summary', 'Api\\V1\\DashboardController::summary');

        // Integration status pages.
        $routes->get('status/worker',       'Api\\V1\\WorkerStatusController::index');
        $routes->get('status/console-sync', 'Api\\V1\\ConsoleSyncStatusController::index');
        $routes->get('status/health',       'Api\\V1\\HealthController::index');

        // Catalogs.
        $routes->resource('products',        ['controller' => 'Api\\V1\\ProductsController']);
        $routes->resource('plans',           ['controller' => 'Api\\V1\\PlansController']);
        $routes->resource('lead-sources',    ['controller' => 'Api\\V1\\LeadSourcesController']);
        $routes->resource('pipeline-stages', ['controller' => 'Api\\V1\\PipelineController']);
        $routes->get('pipeline/summary', 'Api\\V1\\PipelineController::summary');

        // Partner Master — Engage owns every partner write; partner.aicountly.com only reads.
        // Engage Administrator only (it was open to every signed-in role before the seller programme).
        $partnerOnly = ['filter' => 'role:super_admin'];
        $routes->post('partners/(:num)/activate',   'Api\\V1\\PartnersController::activate/$1', $partnerOnly);
        $routes->post('partners/(:num)/deactivate', 'Api\\V1\\PartnersController::deactivate/$1', $partnerOnly);
        $routes->post('partners/(:num)/password',   'Api\\V1\\PartnersController::setPassword/$1', $partnerOnly);
        $routes->post('partners/(:num)/unlock',     'Api\\V1\\PartnersController::unlock/$1', $partnerOnly);
        $routes->post('partners/(:num)/restore',    'Api\\V1\\PartnersController::restore/$1', $partnerOnly);
        $routes->resource('partners', ['controller' => 'Api\\V1\\PartnersController', 'filter' => 'role:super_admin']);

        // Seller programme (docs/SELLER_PROGRAM.md). Roles come from Console (ConsoleRoleSync::CATALOG).
        $sellerView  = ['filter' => 'role:super_admin,sales_manager,seller_manager,kyc_reviewer'];
        $sellerAdmin = ['filter' => 'role:super_admin,seller_manager'];
        $kycReview   = ['filter' => 'role:super_admin,seller_manager,kyc_reviewer'];
        $commission  = ['filter' => 'role:super_admin,sales_manager'];
        $S = 'Api\\V1\\Sellers\\';
        $routes->get('seller-applications',                 $S . 'SellerApplicationsController::index', $sellerView);
        $routes->get('seller-applications/(:num)',          $S . 'SellerApplicationsController::show/$1', $sellerView);
        $routes->post('seller-applications/(:num)/review',  $S . 'SellerApplicationsController::review/$1', $sellerAdmin);
        $routes->post('seller-applications/(:num)/approve', $S . 'SellerApplicationsController::approve/$1', $sellerAdmin);
        $routes->post('seller-applications/(:num)/reject',  $S . 'SellerApplicationsController::reject/$1', $sellerAdmin);

        $routes->get('sellers',                              $S . 'SellersController::index', $sellerView);
        $routes->get('sellers/(:num)',                       $S . 'SellersController::show/$1', $sellerView);
        $routes->post('sellers/(:num)/suspend',              $S . 'SellersController::suspend/$1', $sellerAdmin);
        $routes->post('sellers/(:num)/reactivate',           $S . 'SellersController::reactivate/$1', $sellerAdmin);
        $routes->post('sellers/(:num)/terminate',            $S . 'SellersController::terminate/$1', $sellerAdmin);
        $routes->post('sellers/(:num)/resend-activation',    $S . 'SellersController::resendActivation/$1', $sellerAdmin);
        $routes->post('sellers/(:num)/reset-password',       $S . 'SellersController::resetPassword/$1', $sellerAdmin);
        $routes->post('sellers/(:num)/unlock',               $S . 'SellersController::unlock/$1', $sellerAdmin);
        $routes->get('sellers/(:num)/kyc',                   $S . 'SellersController::kyc/$1', $kycReview);
        $routes->get('sellers/(:num)/kyc/documents/(:segment)', $S . 'SellersController::kycDocument/$1/$2', $kycReview);
        $routes->post('sellers/(:num)/kyc/verify',           $S . 'SellersController::kycVerify/$1', $kycReview);
        $routes->post('sellers/(:num)/kyc/reject',           $S . 'SellersController::kycReject/$1', $kycReview);
        $routes->post('sellers/(:num)/kyc/reveal',           $S . 'SellersController::kycReveal/$1', $kycReview);
        $routes->get('seller-kyc',                           $S . 'SellersController::kycQueue', $kycReview);

        $routes->get('seller-agreements',                    $S . 'SellerAgreementsController::index', $sellerView);
        $routes->post('seller-agreements',                   $S . 'SellerAgreementsController::create', $sellerAdmin);
        $routes->put('seller-agreements/(:num)',             $S . 'SellerAgreementsController::update/$1', $sellerAdmin);
        $routes->post('seller-agreements/(:num)/publish',    $S . 'SellerAgreementsController::publish/$1', $sellerAdmin);

        $routes->get('commission-plans',                     $S . 'CommissionPlansController::index', $sellerView);
        $routes->get('commission-plans/(:num)',              $S . 'CommissionPlansController::show/$1', $sellerView);
        $routes->post('commission-plans',                    $S . 'CommissionPlansController::create', $commission);
        $routes->put('commission-plans/(:num)',              $S . 'CommissionPlansController::update/$1', $commission);
        $routes->post('commission-plans/(:num)/publish',     $S . 'CommissionPlansController::publish/$1', $commission);
        $routes->post('commission-plans/(:num)/retire',      $S . 'CommissionPlansController::retire/$1', $commission);
        $routes->post('commission-plans/(:num)/new-version', $S . 'CommissionPlansController::newVersion/$1', $commission);

        $routes->get('commission-entries',                   $S . 'CommissionEntriesController::index', $sellerView);
        $routes->post('commission-entries/(:num)/reverse',   $S . 'CommissionEntriesController::reverse/$1', $commission);
        $routes->get('leads/(:num)/payments',                $S . 'CommissionEntriesController::payments/$1');
        $routes->post('leads/(:num)/payments',               $S . 'CommissionEntriesController::confirmPayment/$1', $commission);
        $routes->post('lead-payments/(:num)/refund',         $S . 'CommissionEntriesController::refund/$1', $commission);
        $routes->post('leads/(:num)/seller',                 $S . 'CommissionEntriesController::attribute/$1', ['filter' => 'role:super_admin,sales_manager,seller_manager']);
        $routes->get('status/reach-outcomes',                $S . 'CommissionEntriesController::outboxStatus');

        // Sales core.
        $routes->resource('accounts', ['controller' => 'Api\\V1\\AccountsController']);
        $routes->resource('contacts', ['controller' => 'Api\\V1\\ContactsController']);

        $routes->get('leads/kanban',            'Api\\V1\\LeadsController::kanban');
        $routes->post('leads/(:num)/assign',    'Api\\V1\\LeadsController::assign/$1');
        $routes->post('leads/(:num)/move-stage','Api\\V1\\LeadsController::moveStage/$1');
        $routes->post('leads/(:num)/score',     'Api\\V1\\LeadsController::score/$1');
        $routes->post('leads/(:num)/note',      'Api\\V1\\LeadsController::note/$1');
        $routes->resource('leads', ['controller' => 'Api\\V1\\LeadsController']);
        $routes->get('leads/(:num)/activities', 'Api\\V1\\LeadsController::activities/$1');

        // Licensing / subscription.
        $routes->resource('licensing-interests',   ['controller' => 'Api\\V1\\LicensingInterestsController']);
        $routes->resource('subscription-inquiries',['controller' => 'Api\\V1\\SubscriptionInquiriesController']);

        $routes->get('proposals/(:num)/lines',        'Api\\V1\\ProposalsController::lines/$1');
        $routes->post('proposals/(:num)/lines',       'Api\\V1\\ProposalsController::addLine/$1');
        $routes->delete('proposals/(:num)/lines/(:num)', 'Api\\V1\\ProposalsController::removeLine/$1/$2');
        $routes->resource('proposals', ['controller' => 'Api\\V1\\ProposalsController']);

        $routes->post('discount-requests/(:num)/approve', 'Api\\V1\\DiscountRequestsController::approve/$1');
        $routes->post('discount-requests/(:num)/reject',  'Api\\V1\\DiscountRequestsController::reject/$1');
        $routes->resource('discount-requests', ['controller' => 'Api\\V1\\DiscountRequestsController']);

        $routes->resource('follow-ups', ['controller' => 'Api\\V1\\FollowUpsController']);
        $routes->post('communication-drafts/(:num)/approve', 'Api\\V1\\CommunicationDraftsController::approve/$1');
        $routes->post('communication-drafts/(:num)/reject',  'Api\\V1\\CommunicationDraftsController::reject/$1');
        $routes->resource('communication-drafts', ['controller' => 'Api\\V1\\CommunicationDraftsController']);

        $routes->resource('renewals', ['controller' => 'Api\\V1\\RenewalsController']);

        // Credit ledger.
        $routes->resource('credit-ledger', ['controller' => 'Api\\V1\\CreditLedgerController']);

        // Campaigns from Reach.
        $routes->resource('campaigns', ['controller' => 'Api\\V1\\CampaignsController']);

        // Sales Bot subsystem.
        $routes->get('bot/settings',  'Api\\V1\\BotSettingsController::index');
        // Bot mode / auto-action config is administrator-only (Engage `member` cannot change it).
        $routes->put('bot/settings',  'Api\\V1\\BotSettingsController::update', ['filter' => 'role:super_admin']);
        $routes->get('bot/actions',   'Api\\V1\\BotActionsController::index');
        $routes->get('bot/queue',     'Api\\V1\\BotQueueController::index');
        $routes->post('bot/queue',    'Api\\V1\\BotQueueController::create');
        $routes->post('bot/queue/(:num)/retry', 'Api\\V1\\BotQueueController::retry/$1');
        $routes->get('bot/reports',           'Api\\V1\\BotReportsController::index');
        $routes->get('bot/reports/(:num)',    'Api\\V1\\BotReportsController::show/$1');
        $routes->get('bot/reports-local',     'Api\\V1\\BotReportsController::localReports');

        // Approvals.
        $routes->post('approvals/(:num)/approve', 'Api\\V1\\ApprovalsController::approve/$1');
        $routes->post('approvals/(:num)/reject',  'Api\\V1\\ApprovalsController::reject/$1');
        $routes->post('approvals/(:num)/execute', 'Api\\V1\\ApprovalsController::execute/$1');
        $routes->resource('approvals', ['controller' => 'Api\\V1\\ApprovalsController']);
    });
});
