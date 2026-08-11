<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseApiController;
use App\Models\PartnersModel;
use Throwable;

/**
 * Partner Master CRUD. Engage is the only place partners are created, edited,
 * activated/deactivated or given portal credentials — partner.aicountly.com
 * never writes to this table and has no signup path.
 */
class PartnersController extends BaseApiController
{
    private const STATUSES = ['active', 'inactive'];

    private const MIN_PASSWORD_LENGTH = 10;

    private PartnersModel $m;

    public function __construct()
    {
        $this->m = new PartnersModel();
    }

    public function index()
    {
        $q = $this->request->getGet();
        [$page, $limit, $offset] = $this->paging();

        $qb = $this->m->orderBy('name', 'ASC');

        if (! empty($q['include_deleted'])) {
            $qb->withDeleted();
        }
        if (! empty($q['only_deleted'])) {
            $qb->onlyDeleted();
        }
        if (! empty($q['q'])) {
            $term = trim((string) $q['q']);
            $qb->groupStart()
                ->like('name', $term)
                ->orLike('contact_name', $term)
                ->orLike('email', $term)
                ->orLike('phone', $term)
                ->orLike('partner_uid', $term)
                ->groupEnd();
        }
        if (! empty($q['status']) && in_array($q['status'], self::STATUSES, true)) {
            $qb->where('status', $q['status']);
        }
        if (! empty($q['partner_type'])) {
            $qb->where('partner_type', $q['partner_type']);
        }
        if (! empty($q['country'])) {
            $qb->where('country', $q['country']);
        }
        if (isset($q['has_portal_access']) && $q['has_portal_access'] !== '') {
            in_array($q['has_portal_access'], ['1', 'true'], true)
                ? $qb->where('password_set_at IS NOT NULL')
                : $qb->where('password_set_at IS NULL');
        }

        $total = $qb->countAllResults(false);
        $rows  = $qb->findAll($limit, $offset);

        return $this->ok([
            'items' => PartnersModel::publicRows($rows),
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    public function show($id = null)
    {
        $row = $this->m->withDeleted()->find((int) $id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }

        return $this->ok(PartnersModel::publicRow($row));
    }

    public function create()
    {
        $data = $this->input();

        $errors = $this->validatePayload($data, true);
        if ($errors !== []) {
            return $this->fail($this->summarise($errors), 422, $errors);
        }

        // Portal credentials may be issued here or later from the partner's page.
        $generate = ! empty($data['generate']);
        $password = $generate ? self::generatePassword() : (string) ($data['password'] ?? '');

        $row = $this->writableFields($data);

        $row['partner_uid'] = PartnersModel::newPartnerUid();
        $row['status']      = $this->normaliseStatus($data['status'] ?? 'active');

        if ($password !== '') {
            $row['password_hash']   = password_hash($password, PASSWORD_DEFAULT);
            $row['password_set_at'] = date('Y-m-d H:i:s');
        }

        try {
            $id = $this->m->insert($row, true);
        } catch (Throwable $e) {
            return $this->databaseFailure('create partner', $e);
        }

        $this->audit('partner_create', [
            'subject_kind' => 'partner',
            'subject_id'   => $id,
            'metadata'     => [
                'email'             => $row['email'],
                'status'            => $row['status'],
                'portal_access_set' => $password !== '',
                'password_generated'=> $generate,
            ],
        ]);

        $created = PartnersModel::publicRow($this->m->find($id));

        // Returned once, only when Engage generated it. Never stored in clear text.
        if ($generate) {
            $created['generated_password'] = $password;
        }

        return $this->ok($created, 201);
    }

    public function update($id = null)
    {
        $id      = (int) $id;
        $current = $this->m->find($id);
        if (! $current) {
            return $this->fail('Partner not found.', 404);
        }

        $data   = $this->input();
        $errors = $this->validatePayload($data, false, $id);
        if ($errors !== []) {
            return $this->fail($this->summarise($errors), 422, $errors);
        }

        $row = $this->writableFields($data);
        if (array_key_exists('status', $data)) {
            $row['status'] = $this->normaliseStatus($data['status']);
        }

        // Credentials are only ever changed through the dedicated endpoint.
        unset($row['password_hash'], $row['password_set_at']);

        if ($row === []) {
            return $this->ok(PartnersModel::publicRow($current));
        }

        try {
            $this->m->update($id, $row);
        } catch (Throwable $e) {
            return $this->databaseFailure('update partner', $e);
        }

        $this->audit('partner_update', [
            'subject_kind' => 'partner',
            'subject_id'   => $id,
            'metadata'     => ['fields' => array_keys($row)],
        ]);

        return $this->ok(PartnersModel::publicRow($this->m->find($id)));
    }

    /**
     * Soft delete — the partner keeps its history and can never authenticate again.
     */
    public function delete($id = null)
    {
        $id  = (int) $id;
        $row = $this->m->find($id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }

        try {
            $this->m->delete($id);
        } catch (Throwable $e) {
            return $this->databaseFailure('delete partner', $e);
        }

        $this->audit('partner_delete', [
            'subject_kind' => 'partner',
            'subject_id'   => $id,
            'metadata'     => ['email' => $row['email']],
        ]);

        return $this->ok(['deleted' => true, 'id' => $id]);
    }

    public function restore($id = null)
    {
        $id  = (int) $id;
        $row = $this->m->withDeleted()->find($id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }
        if (empty($row['deleted_at'])) {
            return $this->fail('Partner is not deleted.', 409);
        }
        if ($this->m->emailTaken((string) $row['email'], $id)) {
            return $this->fail('Another live partner already uses this email address.', 409);
        }

        try {
            $this->m->builder()->where('id', $id)->update(['deleted_at' => null]);
        } catch (Throwable $e) {
            return $this->databaseFailure('restore partner', $e);
        }

        $this->audit('partner_restore', ['subject_kind' => 'partner', 'subject_id' => $id]);

        return $this->ok(PartnersModel::publicRow($this->m->find($id)));
    }

    public function activate($id = null)
    {
        return $this->setStatus((int) $id, 'active');
    }

    public function deactivate($id = null)
    {
        return $this->setStatus((int) $id, 'inactive');
    }

    /**
     * Set or reset the partner's Partner Portal password.
     *
     * Body: { "password": "..." } or { "generate": true }.
     * A generated password is returned once and never stored in clear text.
     */
    public function setPassword($id = null)
    {
        $id  = (int) $id;
        $row = $this->m->find($id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }

        $data     = $this->input();
        $generate = ! empty($data['generate']);
        $password = (string) ($data['password'] ?? '');

        if ($generate) {
            $password = self::generatePassword();
        }

        if ($password === '') {
            return $this->fail('password is required (or send generate: true).', 422, [
                'password' => 'Provide a password or ask Engage to generate one.',
            ]);
        }
        if (($error = self::passwordError($password)) !== null) {
            return $this->fail('Password does not meet the minimum requirements.', 422, ['password' => $error]);
        }

        try {
            $this->m->update($id, [
                'password_hash'   => password_hash($password, PASSWORD_DEFAULT),
                'password_set_at' => date('Y-m-d H:i:s'),
                'failed_attempts' => 0,
                'locked_until'    => null,
            ]);
        } catch (Throwable $e) {
            return $this->databaseFailure('set partner password', $e);
        }

        $this->audit('partner_password_set', [
            'subject_kind' => 'partner',
            'subject_id'   => $id,
            'metadata'     => ['generated' => $generate],
        ]);

        return $this->ok([
            'partner'            => PartnersModel::publicRow($this->m->find($id)),
            // Only present when Engage generated it — shown once to the admin.
            'generated_password' => $generate ? $password : null,
        ]);
    }

    /**
     * Clear a lockout caused by repeated failed logins on the Partner Portal.
     */
    public function unlock($id = null)
    {
        $id  = (int) $id;
        $row = $this->m->find($id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }

        $this->m->update($id, ['failed_attempts' => 0, 'locked_until' => null]);
        $this->audit('partner_unlock', ['subject_kind' => 'partner', 'subject_id' => $id]);

        return $this->ok(PartnersModel::publicRow($this->m->find($id)));
    }

    // -----------------------------------------------------------------------

    private function setStatus(int $id, string $status)
    {
        $row = $this->m->find($id);
        if (! $row) {
            return $this->fail('Partner not found.', 404);
        }

        try {
            $this->m->update($id, ['status' => $status]);
        } catch (Throwable $e) {
            return $this->databaseFailure('change partner status', $e);
        }

        $this->audit($status === 'active' ? 'partner_activate' : 'partner_deactivate', [
            'subject_kind' => 'partner',
            'subject_id'   => $id,
        ]);

        return $this->ok(PartnersModel::publicRow($this->m->find($id)));
    }

    /**
     * @return array<string, string> field => message
     */
    private function validatePayload(array $data, bool $isCreate, ?int $exceptId = null): array
    {
        $errors = [];

        if ($isCreate || array_key_exists('name', $data)) {
            if (trim((string) ($data['name'] ?? '')) === '') {
                $errors['name'] = 'Partner name is required.';
            }
        }

        if ($isCreate || array_key_exists('email', $data)) {
            $email = trim((string) ($data['email'] ?? ''));
            if ($email === '') {
                $errors['email'] = 'Email is required.';
            } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif (strlen($email) > 191) {
                $errors['email'] = 'Email must be 191 characters or fewer.';
            } elseif ($this->m->emailTaken($email, $exceptId)) {
                $errors['email'] = 'Another partner already uses this email address.';
            }
        }

        if (array_key_exists('status', $data) && $data['status'] !== null && $data['status'] !== '') {
            if (! in_array((string) $data['status'], self::STATUSES, true)) {
                $errors['status'] = 'Status must be active or inactive.';
            }
        }

        if (! empty($data['website']) && ! filter_var((string) $data['website'], FILTER_VALIDATE_URL)) {
            $errors['website'] = 'Enter a valid URL (including https://).';
        }

        // A generated password replaces anything typed, so there is nothing to check.
        if ($isCreate && empty($data['generate']) && ! empty($data['password'])) {
            if (($error = self::passwordError((string) $data['password'])) !== null) {
                $errors['password'] = $error;
            }
        }

        return $errors;
    }

    /**
     * Turn field errors into one sentence, so clients that only surface the
     * top-level message still tell the user what actually went wrong.
     */
    private function summarise(array $errors): string
    {
        return implode(' ', array_values($errors));
    }

    /**
     * Whitelist + normalise the editable partner columns present in the payload.
     */
    private function writableFields(array $data): array
    {
        $simple = [
            'name', 'contact_name', 'email', 'phone', 'partner_type',
            'website', 'country', 'city', 'notes',
        ];

        $row = [];
        foreach ($simple as $field) {
            if (array_key_exists($field, $data)) {
                $value       = is_string($data[$field]) ? trim($data[$field]) : $data[$field];
                $row[$field] = ($value === '') ? null : $value;
            }
        }

        // name and email are NOT NULL — never blank them out.
        foreach (['name', 'email'] as $required) {
            if (array_key_exists($required, $row) && $row[$required] === null) {
                unset($row[$required]);
            }
        }

        foreach (['account_id', 'owner_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $row[$field] = ($data[$field] === '' || $data[$field] === null) ? null : (int) $data[$field];
            }
        }

        if (array_key_exists('metadata', $data)) {
            $row['metadata'] = is_array($data['metadata']) ? json_encode($data['metadata']) : $data['metadata'];
        }

        return $row;
    }

    private function normaliseStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, self::STATUSES, true) ? $status : 'active';
    }

    private function databaseFailure(string $action, Throwable $e)
    {
        // Never surface SQL/driver internals to the client.
        log_message('error', 'Partner master failed to ' . $action . ': ' . $e->getMessage());

        return $this->fail('Could not ' . $action . ' right now. Please try again.', 500);
    }

    private static function passwordError(string $password): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        }
        if (! preg_match('/[A-Za-z]/', $password) || ! preg_match('/\d/', $password)) {
            return 'Password must contain at least one letter and one number.';
        }

        return null;
    }

    private static function generatePassword(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out      = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Guarantee the letter+digit rule regardless of the draw.
        return $out . 'a7';
    }
}
