<?php

namespace Tests\Support;

use App\Libraries\AicS2s\AicS2sSignature;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;

/**
 * Base for licensing tests (docs/LICENSING.md): staff tokens, fixtures (account, licence type, published offer,
 * company mapping, licence) and signed AIC-S2S v1 calls as Manage makes them.
 */
abstract class LicensingTestCase extends DatabaseTestCase
{
    use FeatureTestTrait;

    protected $seed = [\App\Database\Seeds\ProductsSeeder::class];

    public const READ_KEY_ID  = 'manage-read-2026-10';
    public const READ_SECRET  = 'manage-read-secret-0123456789abcdefghijklmnop';
    public const ISSUE_KEY_ID = 'manage-issue-2026-10';
    public const ISSUE_SECRET = 'manage-issue-secret-0123456789abcdefghijklmno';

    /** @var list<string> */
    private array $envSet = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(false);
    }

    protected function tearDown(): void
    {
        foreach ($this->envSet as $k) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        }
        $this->envSet = [];
        Services::reset(false);
        parent::tearDown();
    }

    protected function setEnv(string $k, ?string $v): void
    {
        if ($v === null) {
            putenv($k);
            unset($_ENV[$k], $_SERVER[$k]);
        } else {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        $this->envSet[] = $k;
    }

    /** Engage configured for Manage: environment "sandbox", one read key (and optionally an issue key). */
    protected function configureManage(bool $withIssueKey = false): void
    {
        $this->setEnv('ENGAGE_S2S_ENVIRONMENT', 'sandbox');
        $this->setEnv('ENGAGE_INBOUND_MANAGE_KEY_IDS', self::READ_KEY_ID . ($withIssueKey ? ',' . self::ISSUE_KEY_ID : ''));
        $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_READ_2026_10', self::READ_SECRET);
        if ($withIssueKey) {
            $this->setEnv('ENGAGE_INBOUND_MANAGE_SECRET_MANAGE_ISSUE_2026_10', self::ISSUE_SECRET);
            $this->setEnv('ENGAGE_INBOUND_MANAGE_SCOPE_MANAGE_ISSUE_2026_10', 'issue');
        }
    }

    protected function db()
    {
        return Database::connect('tests');
    }

    protected function json(TestResponse $r): array
    {
        return json_decode((string) $r->response()->getBody(), true) ?? [];
    }

    protected function code(TestResponse $r): int
    {
        return $r->response()->getStatusCode();
    }

    /**
     * A signed call as Manage's server makes it. $override replaces / removes (null) individual headers after signing.
     *
     * @param array<string, ?string> $override
     */
    protected function s2s(string $method, string $path, string $query = '', array $override = [], string $body = '', ?string $keyId = null, ?string $secret = null, ?int $ts = null): TestResponse
    {
        $keyId ??= self::READ_KEY_ID;
        $secret ??= self::READ_SECRET;
        $h = AicS2sSignature::headers('manage', $keyId, $secret, 'sandbox', strtoupper($method), '/v1/' . $path, $query, $body, 'uuid-actor-1', $ts);
        foreach ($override as $k => $v) {
            if ($v === null) {
                unset($h[$k]);
            } else {
                $h[$k] = $v;
            }
        }
        $this->headers = [];
        $this->requestBody = '';
        $this->bodyFormat = '';
        $req = $this->withHeaders($h);
        $url = 'v1/' . $path . ($query !== '' ? '?' . $query : '');
        if ($body !== '') {
            $req = $req->withBody($body);
        }

        return $req->call(strtolower($method), $url);
    }

    /** A staff member holding $roles; returns a bearer token. */
    protected function staff(array $roles, string $email = 'staff@aicountly.org'): string
    {
        $db = $this->db();
        $row = $db->table('engage_users')->where('email', $email)->get()->getRowArray();
        if ($row) {
            $id = (int) $row['id'];
            $db->table('engage_user_roles')->where('user_id', $id)->delete();
        } else {
            $db->table('engage_users')->insert(['email' => $email, 'name' => 'Staff', 'password_hash' => 'x', 'status' => 'active']);
            $id = (int) $db->insertID();
        }
        foreach ($roles as $code) {
            $role = $db->table('engage_roles')->where('code', $code)->get()->getRow();
            if (! $role) {
                $db->table('engage_roles')->insert(['code' => $code, 'name' => $code]);
                $roleId = (int) $db->insertID();
            } else {
                $roleId = (int) $role->id;
            }
            $db->table('engage_user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return Services::jwt()->issue($id, $email, $roles);
    }

    protected function asStaff(string $token, string $method, string $path, array $body = []): TestResponse
    {
        $this->headers = [];
        $this->requestBody = '';
        $this->bodyFormat = '';
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
        if ($method === 'get') {
            return $req->get($path . ($body !== [] ? '?' . http_build_query($body) : ''));
        }

        return $req->withBodyFormat('json')->{$method}($path, $body);
    }

    protected function admin(): string
    {
        return $this->staff(['sales_manager'], 'sales-mgr@aicountly.org');
    }

    protected function account(string $name = 'Acme Traders'): int
    {
        $this->db()->table('engage_accounts')->insert(['name' => $name, 'status' => 'customer']);

        return (int) $this->db()->insertID();
    }

    protected function licenceType(string $code = 'standard', string $kind = 'paid'): void
    {
        $r = $this->asStaff($this->admin(), 'post', 'v1/licence-types', ['code' => $code, 'label' => ucfirst($code) . ' licence', 'lifecycle_kind' => $kind]);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
    }

    /** A published offer; returns its row id. */
    protected function publishedOffer(string $code = 'books-annual', array $extra = []): int
    {
        if (! $this->db()->table('engage_licence_types')->where('code', 'standard')->countAllResults()) {
            $this->licenceType();
        }
        $admin = $this->admin();
        $r = $this->asStaff($admin, 'post', 'v1/offers', array_merge([
            'offer_code' => $code, 'product_code' => 'books', 'licence_type_code' => 'standard', 'label' => 'Books — test annual',
            'price_paise' => 1180000, 'price_basis' => 'inclusive', 'gst_rate' => '18', 'sac' => '997331',
            'term_months' => 12, 'term_days' => 0, 'seats' => 3,
            'entitlements' => [['code' => 'companies', 'label' => 'Companies', 'value' => 1, 'unit' => null]],
            'display' => ['attributes' => [['label' => 'Support', 'value' => 'Email']]],
        ], $extra));
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());
        $id = (int) $this->json($r)['data']['id'];
        $p = $this->asStaff($admin, 'post', "v1/offers/{$id}/publish");
        $this->assertSame(200, $this->code($p), (string) $p->response()->getBody());

        return $id;
    }

    protected function mapCompany(int $accountId, int $cmpId): int
    {
        $r = $this->asStaff($this->admin(), 'post', 'v1/account-companies', ['account_id' => $accountId, 'cmp_id' => $cmpId, 'company_name' => 'Acme Pvt Ltd', 'verification_note' => 'Confirmed with the owner on a call.']);
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

        return (int) $this->json($r)['data']['id'];
    }

    /** Issue a licence (offer + mapping created when missing); returns the staff detail. */
    protected function issue(int $cmpId = 42, array $extra = [], ?int $accountId = null): array
    {
        if (! $this->db()->table('engage_offers')->where('offer_code', 'books-annual')->where('status', 'published')->countAllResults()) {
            $this->publishedOffer();
        }
        if ($accountId === null) {
            $m = $this->db()->table('engage_account_companies')->where('cmp_id', $cmpId)->where('status', 'active')->get()->getRowArray();
            $accountId = $m ? (int) $m['account_id'] : $this->account('Account for ' . $cmpId);
            if (! $m) {
                $this->mapCompany($accountId, $cmpId);
            }
        }
        $r = $this->asStaff($this->admin(), 'post', 'v1/licences', array_merge(['account_id' => $accountId, 'cmp_id' => $cmpId, 'offer_code' => 'books-annual'], $extra));
        $this->assertSame(201, $this->code($r), (string) $r->response()->getBody());

        return $this->json($r)['data'];
    }
}
