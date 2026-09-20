<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Config;
use Wellness\Http\ApiException;

/** Customer-only domain. Browser-supplied user/clinic IDs are never ownership evidence. */
final class CustomerOnboarding
{
    public function __construct(private readonly PDO $db, private readonly Config $config) {}
    private static function stamp(?int $time = null): string { return gmdate('Y-m-d H:i:s', $time ?? time()); }
    private function query(string $sql, array $params = []): \PDOStatement {
        $s = $this->db->prepare($sql); $s->execute($params); return $s;
    }
    private function transaction(callable $action): mixed {
        try { $this->db->beginTransaction(); $result = $action(); $this->db->commit(); return $result; }
        catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            if ($e instanceof \PDOException && in_array((int)($e->errorInfo[1] ?? 0), [1062, 1213, 1205], true))
                throw new ApiException(409, 'onboarding_conflict', 'This request could not be completed. Refresh or contact the clinic; no record was automatically linked.');
            throw $e;
        }
    }
    private function clinic(bool $lock = false): void {
        if (!$this->config->customerOnboardingEnabled || $this->config->customerClinicId < 1)
            throw new ApiException(503, 'onboarding_unavailable', 'Client onboarding is not enabled.');
        if (!$this->query("SELECT id FROM clinics WHERE id=? AND status='active'" . ($lock ? ' FOR UPDATE' : ''), [$this->config->customerClinicId])->fetchColumn())
            throw new ApiException(503, 'onboarding_unavailable', 'Client onboarding is not enabled.');
    }
    private function audit(string $action, ?int $entity, string $cid, ?AuthContext $actor = null, array $metadata = []): void {
        $this->query('INSERT INTO audit_logs(clinic_id,actor_user_id,correlation_id,action,entity_type,entity_id,metadata) VALUES(?,?,?,?,?,?,?)',
            [$this->config->customerClinicId, $actor?->userId, $cid, $action, 'customer_onboarding', $entity, $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null]);
    }
    public static function identityHash(array $claims): string {
        return hash('sha256', json_encode([$claims['iss'], $claims['sub']], JSON_THROW_ON_ERROR));
    }
    private function identity(array $claims): int {
        if (strlen($claims['sub']) > 255 || strlen($claims['iss']) > 255) throw new ApiException(401, 'invalid_customer_token', 'Invalid customer identity.');
        $hash = self::identityHash($claims);
        $this->query('INSERT INTO customer_identities(identity_hash,issuer,subject,created_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)', [$hash, $claims['iss'], $claims['sub'], self::stamp()]);
        return (int)$this->query('SELECT id FROM customer_identities WHERE identity_hash=?', [$hash])->fetchColumn();
    }
    public function rate(string $purpose, string $key, int $limit, int $seconds): void {
        $bucket = hash_hmac('sha256', $purpose . ':' . $key . ':' . intdiv(time(), $seconds), $this->config->appKey);
        $this->query('INSERT INTO customer_rate_limits(bucket_hash,attempts,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE attempts=attempts+1', [$bucket, self::stamp(time() + $seconds)]);
        if ((int)$this->query('SELECT attempts FROM customer_rate_limits WHERE bucket_hash=?', [$bucket])->fetchColumn() > $limit)
            throw new ApiException(429, 'rate_limited', 'Too many attempts. Please wait before trying again.');
    }
    public function challenge(string $ip): array {
        $this->clinic(); $this->rate('challenge', $ip, 20, 600);
        $nonce = bin2hex(random_bytes(32));
        $this->query('INSERT INTO customer_auth_challenges(nonce_hash,created_at,expires_at) VALUES(?,?,?)', [hash('sha256', $nonce), self::stamp(), self::stamp(time() + 600)]);
        return ['nonce' => $nonce];
    }
    public static function validateFreshProof(array $access, array $proof, array $challenge, int $now): void {
        if (!is_string($access['oid'] ?? null) || !preg_match('/^[a-f0-9-]{36}$/iD', $access['oid'])
            || ($proof['oid'] ?? null) !== $access['oid'] || ($proof['tid'] ?? null) !== $access['tid']
            || !is_int($proof['auth_time'] ?? null) || $proof['auth_time'] < $now - 600 || $proof['auth_time'] > $now + 60
            || $proof['auth_time'] < strtotime($challenge['created_at'] . ' UTC') - 60
            || $challenge['consumed_at'] !== null || strtotime($challenge['expires_at'] . ' UTC') <= $now)
            throw new ApiException(401, 'fresh_sign_in_required', 'A fresh sign-in is required. If this continues, ask the clinic to check the identity-provider authentication-time claims.');
    }
    public function startSession(array $access, array $proof, string $cid): array {
        $this->clinic(); $this->rate('session', self::identityHash($access), 15, 600);
        $nonce = $proof['nonce'] ?? null;
        if (!is_string($nonce) || !preg_match('/^[a-f0-9]{64}$/D', $nonce)) throw new ApiException(401, 'fresh_sign_in_required', 'Start a new sign-in from this portal.');
        return $this->transaction(function () use ($access, $proof, $nonce, $cid) {
            $challenge = $this->query('SELECT * FROM customer_auth_challenges WHERE nonce_hash=? FOR UPDATE', [hash('sha256', $nonce)])->fetch();
            if (!$challenge) throw new ApiException(401, 'fresh_sign_in_required', 'Start a new sign-in from this portal.');
            self::validateFreshProof($access, $proof, $challenge, time());
            $identity = $this->identity($access);
            $this->query('UPDATE customer_auth_challenges SET consumed_at=? WHERE nonce_hash=?', [self::stamp(), hash('sha256', $nonce)]);
            $token = bin2hex(random_bytes(32));
            $expires = $proof['auth_time'] + 28800;
            $this->query('INSERT INTO customer_sessions(token_hash,identity_id,authenticated_at,created_at,last_activity_at,expires_at) VALUES(?,?,?,?,?,?)',
                [hash('sha256', $token), $identity, self::stamp($proof['auth_time']), self::stamp(), self::stamp(), self::stamp($expires)]);
            $this->audit('customer.session.start', $identity, $cid);
            return ['session_token' => $token, 'idle_expires_at' => time() + 1800, 'absolute_expires_at' => $expires];
        });
    }
    public static function sessionIsActive(array $session, int $now): bool {
        return $session['revoked_at'] === null && strtotime($session['expires_at'] . ' UTC') > $now
            && strtotime($session['last_activity_at'] . ' UTC') + 1800 > $now;
    }
    public function session(array $claims, ?string $token, bool $activity = false): array {
        $this->clinic();
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new ApiException(401, 'customer_session_required', 'Please sign in again to start a secure client session.');
        return $this->transaction(function () use ($claims, $token, $activity) {
            $row = $this->query('SELECT s.* FROM customer_sessions s JOIN customer_identities i ON i.id=s.identity_id WHERE s.token_hash=? AND i.identity_hash=? FOR UPDATE', [hash('sha256', $token), self::identityHash($claims)])->fetch();
            if (!$row || !self::sessionIsActive($row, time())) throw new ApiException(401, 'customer_session_expired', 'Your client session has ended. Please sign in again.');
            if ($activity) {
                $row['last_activity_at'] = self::stamp();
                $this->query('UPDATE customer_sessions SET last_activity_at=? WHERE token_hash=?', [$row['last_activity_at'], $row['token_hash']]);
            }
            return ['identity_id' => (int)$row['identity_id'], 'idle_expires_at' => strtotime($row['last_activity_at'] . ' UTC') + 1800, 'absolute_expires_at' => strtotime($row['expires_at'] . ' UTC')];
        });
    }
    public function logout(?string $token, string $cid): array {
        $this->clinic();
        if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
            $this->transaction(function () use ($token, $cid) {
                $row = $this->query('SELECT identity_id FROM customer_sessions WHERE token_hash=? FOR UPDATE', [hash('sha256', $token)])->fetch();
                $this->query('UPDATE customer_sessions SET revoked_at=? WHERE token_hash=?', [self::stamp(), hash('sha256', $token)]);
                if ($row) $this->audit('customer.session.end', (int)$row['identity_id'], $cid);
            });
        }
        return ['signed_out' => true];
    }
    private function link(int $identity, bool $lock = false): ?array {
        $row = $this->query('SELECT l.client_id,u.status,u.user_type FROM customer_client_links l JOIN users u ON u.id=l.client_id AND u.clinic_id=l.clinic_id WHERE l.identity_id=? AND l.clinic_id=?' . ($lock ? ' FOR UPDATE' : ''), [$identity, $this->config->customerClinicId])->fetch();
        if ($row && ($row['status'] !== 'active' || $row['user_type'] !== 'client')) throw new ApiException(403, 'client_unavailable', 'Client access is unavailable. Contact the clinic.');
        return $row ?: null;
    }
    public function status(int $identity): array {
        $link = $this->link($identity);
        $claim = $this->query("SELECT c.review_code FROM client_link_claims c JOIN client_link_invitations i ON i.id=c.invitation_id WHERE c.identity_id=? AND c.status='pending' AND i.clinic_id=? ORDER BY c.id DESC LIMIT 1", [$identity, $this->config->customerClinicId])->fetch();
        return ['authenticated' => true, 'authentication_context' => 'customer', 'onboarding_status' => $link ? 'linked' : ($claim ? 'pending_review' : 'not_linked'),
            'review_code' => $claim['review_code'] ?? null, 'capabilities' => $link ? ['own_profile', 'own_appointments', 'book_own_appointments'] : []];
    }
    public function bookingActor(int $identity): AuthContext {
        $link = $this->link($identity);
        if (!$link) throw new ApiException(403, 'client_not_linked', 'Your client record is not linked yet.');
        $row = $this->query("SELECT id,clinic_id,email,display_name FROM users WHERE id=? AND clinic_id=? AND user_type='client' AND status='active'", [$link['client_id'], $this->config->customerClinicId])->fetch();
        if (!$row) throw new ApiException(403, 'client_unavailable', 'Client access is unavailable. Contact the clinic.');
        return new AuthContext((int)$row['id'], (int)$row['clinic_id'], '', (string)$row['email'], (string)$row['display_name'], 'client', []);
    }
    public static function profileInput(array $body): array {
        $allowed = ['given_name','family_name','email','phone','preferred_contact','address','revision'];
        if (array_diff(array_keys($body), $allowed)) throw new ApiException(422, 'validation_error', 'Unexpected profile fields.');
        $data = ClientService::validate($body);
        if (!$data['phone']) throw new ApiException(422, 'validation_error', 'A contact phone number is required.');
        $data['address'] = Delivery::destination(['delivery_mode' => 'mobile', 'destination' => $body['address'] ?? null]);
        return $data;
    }
    public function register(int $identity, array $body, string $cid): array {
        $data = self::profileInput($body);
        return $this->transaction(function () use ($identity, $data, $cid) {
            $this->clinic(true); // Serializes onboarding ownership changes within this clinic.
            if ($this->link($identity)) return $this->status($identity);
            if ($this->status($identity)['onboarding_status'] === 'pending_review') throw new ApiException(409, 'claim_pending', 'Your invitation is awaiting staff review.');
            $this->query("INSERT INTO users(clinic_id,given_name,family_name,display_name,email,status,user_type) VALUES(?,?,?,?,?,'active','client')", [$this->config->customerClinicId, $data['given_name'], $data['family_name'], $data['display_name'], $data['email']]);
            $client = (int)$this->db->lastInsertId();
            $this->query("INSERT INTO client_email_addresses(clinic_id,client_id,email,is_primary,source) VALUES(?,?,?,1,'customer')", [$this->config->customerClinicId, $client, $data['email']]);
            $this->query('INSERT INTO client_profiles(user_id,phone,preferred_contact) VALUES(?,?,?)', [$client, $data['phone'], $data['preferred_contact']]);
            $this->query('INSERT INTO client_contact_addresses(client_id,address_json) VALUES(?,?)', [$client, json_encode($data['address'], JSON_THROW_ON_ERROR)]);
            $this->query('INSERT INTO customer_client_links(identity_id,client_id,clinic_id,created_at) VALUES(?,?,?,?)', [$identity, $client, $this->config->customerClinicId, self::stamp()]);
            $this->audit('customer.register', $client, $cid, null, ['identity_id' => $identity]);
            return $this->status($identity);
        });
    }
    private function ownProfile(int $identity, bool $lock = false): array {
        $link = $this->link($identity, $lock);
        if (!$link) throw new ApiException(403, 'client_not_linked', 'Your client record is not linked yet.');
        $row = $this->query('SELECT u.given_name,u.family_name,u.email,p.phone,p.preferred_contact,a.address_json FROM users u LEFT JOIN client_profiles p ON p.user_id=u.id LEFT JOIN client_contact_addresses a ON a.client_id=u.id WHERE u.id=? AND u.clinic_id=?' . ($lock ? ' FOR UPDATE' : ''), [$link['client_id'], $this->config->customerClinicId])->fetch();
        $row['address'] = $row['address_json'] ? json_decode($row['address_json'], true, 32, JSON_THROW_ON_ERROR) : null; unset($row['address_json']);
        $row['revision'] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR)); return $row;
    }
    public function profile(int $identity, string $cid): array {
        $row = $this->ownProfile($identity); $this->audit('customer.profile.view', (int)$this->link($identity)['client_id'], $cid, null, ['identity_id' => $identity]); return $row;
    }
    public function saveProfile(int $identity, array $body, string $cid): array {
        $data = self::profileInput($body);
        return $this->transaction(function () use ($identity, $body, $data, $cid) {
            $this->clinic(true); $link = $this->link($identity, true);
            if (!$link) throw new ApiException(403, 'client_not_linked', 'Your client record is not linked yet.');
            $client = (int)$link['client_id'];
            $this->query('SELECT id FROM users WHERE id=? FOR UPDATE', [$client]);
            // Current locking reads, not an earlier REPEATABLE READ snapshot, protect staff edits.
            $current = $this->ownProfile($identity, true);
            if (!is_string($body['revision'] ?? null) || !hash_equals($current['revision'], $body['revision'])) throw new ApiException(409, 'profile_changed', 'Your profile changed. Reload it before saving.');
            if ($this->query('SELECT client_id FROM client_email_addresses WHERE clinic_id=? AND email=? AND client_id<>? FOR UPDATE', [$this->config->customerClinicId, $data['email'], $client])->fetchColumn()) throw new ApiException(409, 'email_in_use', 'This email is already used by another account in this clinic.');
            $this->query('UPDATE users SET given_name=?,family_name=?,display_name=?,email=? WHERE id=?', [$data['given_name'], $data['family_name'], $data['display_name'], $data['email'], $client]);
            $this->query('UPDATE client_email_addresses SET is_primary=0 WHERE client_id=?', [$client]);
            $this->query("INSERT INTO client_email_addresses(clinic_id,client_id,email,is_primary,source) VALUES(?,?,?,1,'customer') ON DUPLICATE KEY UPDATE is_primary=1", [$this->config->customerClinicId, $client, $data['email']]);
            $this->query('INSERT INTO client_profiles(user_id,phone,preferred_contact) VALUES(?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone),preferred_contact=VALUES(preferred_contact)', [$client, $data['phone'], $data['preferred_contact']]);
            $this->query('INSERT INTO client_contact_addresses(client_id,address_json) VALUES(?,?) ON DUPLICATE KEY UPDATE address_json=VALUES(address_json)', [$client, json_encode($data['address'], JSON_THROW_ON_ERROR)]);
            $this->audit('customer.profile.update', $client, $cid, null, ['identity_id' => $identity]); return $this->ownProfile($identity, true);
        });
    }
    public function appointments(int $identity, string $cid): array {
        $link = $this->link($identity);
        if (!$link) throw new ApiException(403, 'client_not_linked', 'Your client record is not linked yet.');
        $rows = $this->query('SELECT a.id,a.starts_at,a.ends_at,a.status,a.delivery_mode,s.name AS service,p.display_name AS practitioner,l.name AS location,l.timezone FROM appointments a JOIN services s ON s.id=a.service_id JOIN practitioners pr ON pr.id=a.practitioner_id JOIN users p ON p.id=pr.user_id JOIN locations l ON l.id=a.location_id WHERE a.client_id=? AND a.clinic_id=? ORDER BY a.starts_at DESC LIMIT 100', [$link['client_id'], $this->config->customerClinicId])->fetchAll(PDO::FETCH_ASSOC);
        $this->audit('customer.appointments.view', (int)$link['client_id'], $cid, null, ['identity_id' => $identity]); return ['items' => $rows, 'limit' => 100];
    }
    private function staffClient(AuthContext $actor, int $client): void {
        ClientService::authorize($actor); $this->clinic();
        if ($actor->clinicId !== $this->config->customerClinicId || !$this->query("SELECT id FROM users WHERE id=? AND clinic_id=? AND user_type='client'", [$client, $actor->clinicId])->fetchColumn()) throw new ApiException(404, 'client_not_found', 'Client not found.');
    }
    public function invitations(AuthContext $actor, int $client): array {
        $this->staffClient($actor, $client);
        return ['linked' => (bool)$this->query('SELECT identity_id FROM customer_client_links WHERE client_id=?', [$client])->fetchColumn(),
            'items' => $this->query('SELECT i.id,i.created_at,i.expires_at,i.consumed_at,i.revoked_at,c.id AS claim_id,c.claimant_name,c.status AS claim_status FROM client_link_invitations i LEFT JOIN client_link_claims c ON c.invitation_id=i.id WHERE i.client_id=? AND i.clinic_id=? ORDER BY i.id DESC LIMIT 20', [$client, $actor->clinicId])->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function invite(AuthContext $actor, int $client, string $cid): array {
        $this->staffClient($actor, $client);
        return $this->transaction(function () use ($actor, $client, $cid) {
            $this->clinic(true);
            $status = $this->query('SELECT status FROM users WHERE id=? FOR UPDATE', [$client])->fetchColumn();
            if ($status !== 'active' || $this->invitations($actor, $client)['linked']) throw new ApiException(409, 'invitation_unavailable', 'Only active, unlinked clients can be invited.');
            $this->query("UPDATE client_link_claims c JOIN client_link_invitations i ON i.id=c.invitation_id SET c.status='rejected',c.reviewed_at=?,c.reviewed_by=? WHERE i.client_id=? AND c.status='pending'", [self::stamp(), $actor->userId, $client]);
            $this->query('UPDATE client_link_invitations SET revoked_at=? WHERE client_id=? AND revoked_at IS NULL', [self::stamp(), $client]);
            $token = bin2hex(random_bytes(32)); $expires = time() + 172800;
            $this->query('INSERT INTO client_link_invitations(clinic_id,client_id,token_hash,created_by,created_at,expires_at) VALUES(?,?,?,?,?,?)', [$actor->clinicId, $client, hash('sha256', $token), $actor->userId, self::stamp(), self::stamp($expires)]);
            $id = (int)$this->db->lastInsertId(); $this->audit('customer.invitation.issue', $id, $cid, $actor);
            return ['id' => $id, 'token' => $token, 'expires_at' => $expires, 'delivery' => 'manual'];
        });
    }
    public function accept(int $identity, array $body, string $cid): array {
        $this->rate('invitation', (string)$identity, 10, 600);
        $token = $body['token'] ?? ''; $name = $body['claimant_name'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token) || !is_string($name) || trim($name) === '' || strlen($name) > 150) throw new ApiException(422, 'invalid_invitation', 'Enter your name and the complete invitation code.');
        return $this->transaction(function () use ($identity, $token, $name, $cid) {
            $this->clinic(true);
            if ($this->link($identity)) throw new ApiException(409, 'already_linked', 'This sign-in is already linked to a client record.');
            $invite = $this->query('SELECT * FROM client_link_invitations WHERE token_hash=? AND clinic_id=? FOR UPDATE', [hash('sha256', $token), $this->config->customerClinicId])->fetch();
            if (!$invite || $invite['revoked_at'] !== null || strtotime($invite['expires_at'] . ' UTC') <= time()) throw new ApiException(409, 'invalid_invitation', 'This invitation is unavailable. Request a new invitation from the clinic.');
            if ($invite['consumed_at'] !== null) {
                $owner = $this->query("SELECT identity_id FROM client_link_claims WHERE invitation_id=? AND status='pending'", [$invite['id']])->fetchColumn();
                if ((int)$owner === $identity) return $this->status($identity);
                throw new ApiException(409, 'invalid_invitation', 'This invitation is unavailable. Request a new invitation from the clinic.');
            }
            if ($this->status($identity)['onboarding_status'] === 'pending_review') throw new ApiException(409, 'claim_pending', 'You already have an invitation awaiting review.');
            $state = $this->query("SELECT status FROM users WHERE id=? AND user_type='client' FOR UPDATE", [$invite['client_id']])->fetchColumn();
            if ($state !== 'active' || $this->query('SELECT identity_id FROM customer_client_links WHERE client_id=?', [$invite['client_id']])->fetchColumn()) throw new ApiException(409, 'invalid_invitation', 'This invitation is unavailable. Request a new invitation from the clinic.');
            $this->query('UPDATE client_link_invitations SET consumed_at=? WHERE id=?', [self::stamp(), $invite['id']]);
            $this->query('INSERT INTO client_link_claims(invitation_id,identity_id,claimant_name,review_code,created_at) VALUES(?,?,?,?,?)', [$invite['id'], $identity, trim($name), strtoupper(bin2hex(random_bytes(6))), self::stamp()]);
            $this->audit('customer.invitation.accept', (int)$invite['id'], $cid, null, ['identity_id' => $identity]); return $this->status($identity);
        });
    }
    public function review(AuthContext $actor, int $client, int $invitation, array $body, string $cid): array {
        $this->staffClient($actor, $client);
        $action = $body['action'] ?? '';
        if (!in_array($action, ['approve','reject','revoke'], true) || ($action === 'approve' && ($body['identity_verified'] ?? null) !== true)) throw new ApiException(422, 'verification_required', 'Confirm independent identity verification before approval.');
        return $this->transaction(function () use ($actor, $client, $invitation, $body, $action, $cid) {
            $this->clinic(true);
            $invite = $this->query('SELECT * FROM client_link_invitations WHERE id=? AND client_id=? AND clinic_id=? FOR UPDATE', [$invitation, $client, $actor->clinicId])->fetch();
            if (!$invite) throw new ApiException(404, 'invitation_not_found', 'Invitation not found.');
            $claim = $this->query('SELECT * FROM client_link_claims WHERE invitation_id=? FOR UPDATE', [$invitation])->fetch();
            if ($action === 'approve') {
                if (!$claim || $claim['status'] !== 'pending' || $invite['revoked_at'] !== null || strtotime($invite['expires_at'] . ' UTC') <= time()) throw new ApiException(409, 'claim_unavailable', 'This claim is no longer available for approval.');
                if (!is_string($body['review_code'] ?? null) || !hash_equals($claim['review_code'], strtoupper(trim($body['review_code'])))) throw new ApiException(422, 'verification_required', 'Enter the review code provided by the independently verified client.');
                if ($this->query('SELECT status FROM users WHERE id=? FOR UPDATE', [$client])->fetchColumn() !== 'active') throw new ApiException(409, 'client_unavailable', 'Client is inactive.');
                $this->query('INSERT INTO customer_client_links(identity_id,client_id,clinic_id,approved_by,created_at) VALUES(?,?,?,?,?)', [$claim['identity_id'], $client, $actor->clinicId, $actor->userId, self::stamp()]);
            } elseif ($claim && $claim['status'] === 'approved') throw new ApiException(409, 'already_approved', 'Approved links require a separate recovery process; this action cannot unlink them.');
            if ($claim) $this->query('UPDATE client_link_claims SET status=?,reviewed_at=?,reviewed_by=? WHERE id=?', [$action === 'approve' ? 'approved' : 'rejected', self::stamp(), $actor->userId, $claim['id']]);
            if ($action !== 'approve') $this->query('UPDATE client_link_invitations SET revoked_at=? WHERE id=?', [self::stamp(), $invitation]);
            $this->audit('customer.invitation.' . $action, $invitation, $cid, $actor);
            return $this->invitations($actor, $client);
        });
    }
}
