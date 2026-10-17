<?php

namespace Tests\Support;

use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\Services;

/**
 * Base for seller-programme feature tests: env, staff tokens, seller-portal calls through the BFF headers,
 * and a fake mailer (no email ever leaves a test).
 */
abstract class SellerTestCase extends DatabaseTestCase
{
    use FeatureTestTrait;

    protected $seed = [\App\Database\Seeds\PipelineStagesSeeder::class, \App\Database\Seeds\ProductsSeeder::class, \App\Database\Seeds\LeadSourcesSeeder::class];

    public const PORTAL_KEY = 'test-seller-portal-key-0123456789abcdefghij';
    public const DATA_KEY   = '00112233445566778899aabbccddeeff00112233445566778899aabbccddeeff';

    protected FakeSellerMailer $mailer;

    /** @var list<string> */
    private array $envSet = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::reset(false);
        cache()->clean();
        $this->setEnv('SELLER_PORTAL_KEY', self::PORTAL_KEY);
        $this->setEnv('ENGAGE_DATA_KEY', self::DATA_KEY);
        $this->setEnv('ENGAGE_SELLER_DOCS_PATH', WRITEPATH . 'seller-kyc-test');
        $this->mailer = new FakeSellerMailer();
        Services::injectMock('sellerMailer', $this->mailer);
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
            $roleId = (int) $db->table('engage_roles')->where('code', $code)->get()->getRow()->id;
            $db->table('engage_user_roles')->insert(['user_id' => $id, 'role_id' => $roleId]);
        }

        return Services::jwt()->issue($id, $email, $roles);
    }

    protected function asStaff(string $token, string $method, string $path, array $body = []): TestResponse
    {
        $this->headers = []; // FeatureTestTrait keeps headers between calls otherwise
        $req = $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
        if ($method === 'get') {
            return $req->get($path);
        }

        return $req->withBodyFormat('json')->{$method}($path, $body);
    }

    /** A call as the seller.aicountly.com BFF makes it. */
    protected function portal(string $method, string $path, array $body = [], ?string $session = null, array $headers = []): TestResponse
    {
        $h = array_merge(['X-Seller-Portal-Key' => self::PORTAL_KEY, 'X-Seller-Client-IP' => '203.0.113.7', 'X-Seller-Client-UA' => 'PHPUnit'], $headers);
        if ($session !== null) {
            $h['X-Seller-Session'] = $session;
        }
        $this->headers = [];
        $req = $this->withHeaders($h);
        if ($method === 'get') {
            return $req->get('v1/seller-portal/' . $path . ($body !== [] ? '?' . http_build_query($body) : ''));
        }

        return $req->withBodyFormat('json')->{$method}('v1/seller-portal/' . $path, $body);
    }

    protected function apply(string $email = 'ravi@example.com', array $extra = []): TestResponse
    {
        return $this->portal('post', 'applications', array_merge([
            'name' => 'Ravi Kumar', 'email' => $email, 'mobile' => '+91 98765 43210', 'organization' => 'Ravi Associates',
            'city' => 'Pune', 'state' => 'Maharashtra', 'profile' => 'reseller', 'message' => 'I sell to CA firms.',
        ], $extra));
    }

    protected function publishAgreement(string $label = '2026.1'): int
    {
        $admin = $this->staff(['super_admin'], 'agreement-admin@aicountly.org');
        $r = $this->asStaff($admin, 'post', 'v1/seller-agreements', ['version_label' => $label, 'title' => 'Seller agreement', 'body' => str_repeat('Terms and conditions. ', 5)]);
        $id = (int) $this->json($r)['data']['id'];
        $this->asStaff($admin, 'post', "v1/seller-agreements/{$id}/publish");

        return $id;
    }

    /** Apply + approve. @return array{seller_id:int, seller_code:string, token:?string, approve:array} */
    protected function approvedSeller(string $email = 'ravi@example.com'): array
    {
        $this->apply($email);
        $appId = (int) $this->db()->table('engage_seller_applications')->where('email', $email)->get()->getRow()->id;
        $mgr = $this->staff(['seller_manager'], 'seller-mgr@aicountly.org');
        $body = $this->json($this->asStaff($mgr, 'post', "v1/seller-applications/{$appId}/approve"));
        $link = $body['data']['activation']['link'] ?? null;
        $token = $link ? (string) substr($link, strpos($link, 'token=') + 6) : null;

        return ['seller_id' => (int) $body['data']['seller']['id'], 'seller_code' => $body['data']['seller']['seller_code'], 'token' => $token, 'approve' => $body];
    }

    /** Approved + activated (agreement accepted) seller with a live session. */
    protected function activeSellerSession(string $email = 'ravi@example.com'): array
    {
        $agreementId = (int) ($this->db()->table('engage_seller_agreement_versions')->where('status', 'current')->get()->getRow()->id ?? 0) ?: $this->publishAgreement();
        $s = $this->approvedSeller($email);
        $r = $this->portal('post', 'auth/activate', ['token' => $s['token'], 'password' => 'Str0ngPassword', 'agreement_version_id' => $agreementId]);
        $s['session'] = $this->json($r)['data']['session_token'] ?? null;
        $s['agreement_id'] = $agreementId;

        return $s;
    }
}
