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
    $workerOk  = (string) env('WORKER_BASE_URL', '') !== '' && (string) env('WORKER_API_TOKEN', '') !== '';
    $reachOk   = (string) env('REACH_INBOUND_TOKEN', '') !== '';
    $svcKeyOk  = (string) env('ENGAGE_SERVICE_KEY', '') !== '';

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
            'worker_outbound'     => $workerOk ? 'ok' : 'WORKER_* not configured',
            'reach_inbound_token' => $reachOk ? 'ok' : 'REACH_INBOUND_TOKEN not set',
            'console_inbound_key' => $svcKeyOk ? 'ok' : 'ENGAGE_SERVICE_KEY not set',
        ],
    ]);
});

$routes->group('v1', static function ($routes) {
    // Public auth endpoints — no JWT.
    $routes->post('auth/login', 'Api\\V1\\AuthController::login');
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

    // Internal Reach ingest — validated by X-Portal-Token header.
    $routes->group('internal', ['filter' => 'internal-token'], static function ($routes) {
        $routes->post('reach/leads', 'Internal\\ReachController::ingest');
    });

    // Authenticated portal endpoints (superadmin JWT).
    $routes->group('', ['filter' => 'jwt'], static function ($routes) {
        $routes->get('me', 'Api\\V1\\AuthController::me');
        $routes->post('auth/logout', 'Api\\V1\\AuthController::logout');

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
        $routes->put('bot/settings',  'Api\\V1\\BotSettingsController::update');
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
