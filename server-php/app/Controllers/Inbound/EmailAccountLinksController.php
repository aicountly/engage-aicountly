<?php
declare(strict_types=1);
namespace App\Controllers\Inbound;

use App\Services\Licensing\EmailAccountLinks;
use CodeIgniter\Controller;

final class EmailAccountLinksController extends Controller
{
    public function preview() { return $this->respond(false); }
    public function commit() { return $this->respond(true); }
    private function respond(bool $commit)
    {
        // Authentication, environment, nonce and rate limiting are enforced by aic-s2s:email,issue.
        $ctx = $this->request->aicS2s ?? [];
        if (($ctx['client'] ?? '') !== 'email' || ($ctx['scope'] ?? '') !== 'issue') {
            return $this->response->setStatusCode(403)->setJSON(['message' => 'Email service authentication required.']);
        }
        try { $body = $this->request->getJSON(true); } catch (\Throwable) { $body = null; }
        if (!is_array($body)) return $this->response->setStatusCode(422)->setJSON(['message' => 'A JSON object is required.']);
        $result = (new EmailAccountLinks())->process($body, $commit);
        return $this->response->setStatusCode($result['status'])->setHeader('Cache-Control', 'no-store')->setJSON($result);
    }
}
