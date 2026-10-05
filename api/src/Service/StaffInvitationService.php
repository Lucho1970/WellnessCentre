<?php
declare(strict_types=1);
namespace Wellness\Service;

use Wellness\Auth\AuthContext;
use Wellness\Auth\VerifiedIdentity;
use Wellness\Config;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Practitioner-only invitation pilot. Claims require explicit recipient review. */
final class StaffInvitationService
{
    public function __construct(private Database $database,private Config $config,private AuditLogger $audit){}
    private function enabled(): void {if(!$this->config->staffInvitationsEnabled)throw new ApiException(503,'staff_invitations_disabled','Staff invitations are not enabled.');}
    public static function authorize(AuthContext $actor): void {if($actor->userType!=='staff'||!$actor->hasAnyRole('super_admin'))throw new ApiException(403,'forbidden','Only a super administrator can manage staff invitations.');}
    public static function usable(array $invite): void {if($invite['revoked_at']!==null||$invite['accepted_at']!==null||strtotime($invite['expires_at'].' UTC')<=time())throw new ApiException(409,'invitation_unavailable','This invitation is expired, revoked or already accepted.');}
    private function q(string $sql,array $params=[]): \PDOStatement {$q=$this->database->connection()->prepare($sql);$q->execute($params);return $q;}
    private function tx(callable $action): mixed {$db=$this->database->connection();$db->beginTransaction();try{$result=$action();$db->commit();return $result;}catch(\Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof \PDOException && $e->getCode()==='23000')throw new ApiException(409,'invitation_conflict','The invitation conflicts with an existing account or membership.');throw $e;}}
    private function manager(AuthContext $actor): void {
        self::authorize($actor);$this->enabled();
        if(!$this->q("SELECT u.id FROM users u JOIN clinics c ON c.id=u.clinic_id AND c.status='active' JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='super_admin' WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND u.user_type='staff' FOR UPDATE",[$actor->userId,$actor->clinicId])->fetchColumn())throw new ApiException(403,'forbidden','Active administrator access is required.');
    }
    public function list(AuthContext $actor): array {
        self::authorize($actor);$this->enabled();
        return ['items'=>$this->q("SELECT i.id,i.recipient_email,i.given_name,i.family_name,i.discipline,i.existing_user_id,i.expires_at,i.revoked_at,i.accepted_at,c.claimant_name,c.verification_code,c.status claim_status,c.id claim_id FROM staff_invitations i LEFT JOIN staff_invitation_claims c ON c.invitation_id=i.id WHERE i.clinic_id=? ORDER BY i.id DESC LIMIT 50",[$actor->clinicId])->fetchAll()];
    }
    public function create(AuthContext $actor,array $body,string $cid): array {
        self::authorize($actor);$this->enabled();
        $data=[];foreach(['recipient_email'=>190,'given_name'=>100,'family_name'=>100,'discipline'=>100] as $key=>$limit){$value=$body[$key]??null;if(!is_string($value)||trim($value)===''||strlen(trim($value))>$limit)throw new ApiException(422,'validation_error','Complete the invitation details.');$data[$key]=trim($value);}
        $data['recipient_email']=strtolower($data['recipient_email']);if(!filter_var($data['recipient_email'],FILTER_VALIDATE_EMAIL))throw new ApiException(422,'validation_error','Enter a valid email address.');
        if(strlen($data['given_name'].' '.$data['family_name'])>150)throw new ApiException(422,'validation_error','The combined name must be at most 150 characters.');
        $location=filter_var($body['location_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $existing=$body['existing_user_id']??null;if($existing!==null){$existing=filter_var($existing,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$existing)throw new ApiException(422,'validation_error','Invalid staff user ID.');}
        if(!$location)throw new ApiException(422,'validation_error','Choose a clinic location.');
        if(isset($body['role'])&&$body['role']!=='practitioner')throw new ApiException(422,'validation_error','Invitations can grant only practitioner access.');
        return $this->tx(function()use($actor,$data,$location,$existing,$cid){
            $this->manager($actor);
            if(!$this->q('SELECT id FROM locations WHERE id=? AND clinic_id=? AND is_bookable=1 FOR UPDATE',[$location,$actor->clinicId])->fetchColumn())throw new ApiException(404,'location_not_found','Location not found.');
            if($existing){if(!$this->q("SELECT id FROM users WHERE id=? AND clinic_id=? AND user_type='staff' AND status='active' FOR UPDATE",[$existing,$actor->clinicId])->fetchColumn())throw new ApiException(404,'staff_not_found','Staff account not found.');
                if($this->q('SELECT id FROM staff_memberships WHERE local_user_id=?',[$existing])->fetchColumn())throw new ApiException(409,'membership_exists','This account already has a membership; use a reviewed identity migration.');}
            $token=bin2hex(random_bytes(32));$expires=gmdate('Y-m-d H:i:s',time()+172800);
            $this->q('INSERT INTO staff_invitations(clinic_id,invited_by,recipient_email,given_name,family_name,discipline,location_id,existing_user_id,token_hash,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?)',[$actor->clinicId,$actor->userId,$data['recipient_email'],$data['given_name'],$data['family_name'],$data['discipline'],$location,$existing,hash('sha256',$token),$expires]);
            $id=(int)$this->database->connection()->lastInsertId();$this->audit->write($actor->clinicId,$actor,$cid,'staff.invitation.create','staff_invitation',$id);
            return ['id'=>$id,'token'=>$token,'expires_at'=>$expires,'delivery'=>'manual'];
        });
    }
    public function claim(int $clinic,VerifiedIdentity $identity,array $body,string $cid): array {
        $this->enabled();if($identity->adapter!=='entra-external-staff')throw new ApiException(403,'staff_identity_required','A separate staff sign-in is required.');
        $token=$body['token']??'';$name=$body['claimant_name']??'';
        if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token)||!is_string($name)||trim($name)===''||strlen(trim($name))>150)throw new ApiException(422,'validation_error','Enter the invitation and your name.');
        return $this->tx(function()use($clinic,$identity,$token,$name,$cid){
            $invite=$this->q('SELECT * FROM staff_invitations WHERE clinic_id=? AND token_hash=? FOR UPDATE',[$clinic,hash('sha256',$token)])->fetch();
            if(!$invite)throw new ApiException(404,'invitation_not_found','Invitation not found.');self::usable($invite);
            $existing=$this->q('SELECT * FROM staff_invitation_claims WHERE invitation_id=?',[$invite['id']])->fetch();
            if($existing){if($existing['issuer']!==$identity->issuer||$existing['subject']!==$identity->subject||$existing['status']!=='pending')throw new ApiException(409,'invitation_claimed','This invitation already has a claim.');return ['status'=>'pending','verification_code'=>$existing['verification_code']];}
            $code=strtoupper(bin2hex(random_bytes(6)));
            $this->q('INSERT INTO staff_invitation_claims(invitation_id,issuer,subject,claimant_name,verification_code) VALUES(?,?,?,?,?)',[$invite['id'],$identity->issuer,$identity->subject,trim($name),$code]);
            $this->audit->write($clinic,null,$cid,'staff.invitation.claim','staff_invitation',(int)$invite['id']);return ['status'=>'pending','verification_code'=>$code];
        });
    }
    public function revoke(AuthContext $actor,int $id,string $cid): array {return $this->tx(function()use($actor,$id,$cid){$this->manager($actor);$invite=$this->q('SELECT * FROM staff_invitations WHERE id=? AND clinic_id=? FOR UPDATE',[$id,$actor->clinicId])->fetch();if(!$invite)throw new ApiException(404,'invitation_not_found','Invitation not found.');if($invite['accepted_at']!==null)throw new ApiException(409,'invitation_unavailable','Accepted invitations cannot be revoked; deactivate the staff account instead.');$this->q('UPDATE staff_invitations SET revoked_at=UTC_TIMESTAMP() WHERE id=?',[$id]);$this->audit->write($actor->clinicId,$actor,$cid,'staff.invitation.revoke','staff_invitation',$id);return ['revoked'=>true];});}
    public function approve(AuthContext $actor,int $id,array $body,string $cid): array {
        if(($body['recipient_verified']??false)!==true)throw new ApiException(422,'recipient_verification_required','Verify the claimant through the intended recipient before approving.');
        return $this->tx(function()use($actor,$id,$body,$cid){
            $this->manager($actor);$invite=$this->q('SELECT * FROM staff_invitations WHERE id=? AND clinic_id=? FOR UPDATE',[$id,$actor->clinicId])->fetch();
            if(!$invite)throw new ApiException(404,'invitation_not_found','Invitation not found.');self::usable($invite);
            $claim=$this->q("SELECT * FROM staff_invitation_claims WHERE invitation_id=? AND status='pending' FOR UPDATE",[$id])->fetch();if(!$claim)throw new ApiException(409,'claim_required','A pending signed-in claim is required.');
            if(!is_string($body['verification_code']??null)||!hash_equals($claim['verification_code'],strtoupper(trim($body['verification_code']))))throw new ApiException(422,'recipient_verification_required','Confirm the verification code with the intended recipient.');
            if(!$this->q("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id JOIN roles r ON r.id=ur.role_id AND r.code='super_admin' WHERE u.id=? AND u.clinic_id=? AND u.status='active' AND u.user_type='staff' FOR UPDATE",[$invite['invited_by'],$actor->clinicId])->fetchColumn())throw new ApiException(409,'inviter_inactive','The inviter is no longer authorized.');
            if(!$this->q('SELECT id FROM locations WHERE id=? AND clinic_id=? AND is_bookable=1 FOR UPDATE',[$invite['location_id'],$actor->clinicId])->fetchColumn())throw new ApiException(409,'location_not_found','The invitation location is unavailable.');
            $identity=$this->q("SELECT id,status FROM product_identities WHERE adapter='entra-external-staff' AND issuer=? AND subject=? FOR UPDATE",[$claim['issuer'],$claim['subject']])->fetch();
            if($identity&&$identity['status']!=='active')throw new ApiException(409,'identity_inactive','This identity is inactive.');
            if(!$identity){$this->q("INSERT INTO product_identities(adapter,issuer,subject) VALUES('entra-external-staff',?,?)",[$claim['issuer'],$claim['subject']]);$identity=['id'=>(int)$this->database->connection()->lastInsertId()];}
            if($this->q('SELECT id FROM staff_memberships WHERE identity_id=? AND clinic_id=?',[$identity['id'],$actor->clinicId])->fetchColumn())throw new ApiException(409,'membership_exists','This identity already has a clinic membership.');
            $user=$invite['existing_user_id'];
            if($user){
                $local=$this->q("SELECT id FROM users WHERE id=? AND clinic_id=? AND user_type='staff' AND status='active' FOR UPDATE",[$user,$actor->clinicId])->fetchColumn();
                if(!$local||$this->q('SELECT id FROM staff_memberships WHERE local_user_id=?',[$user])->fetchColumn())throw new ApiException(409,'account_binding_conflict','Existing staff binding requires review.');
                if(!$this->q('SELECT id FROM practitioners WHERE user_id=? AND active=1',[$user])->fetchColumn())throw new ApiException(409,'practitioner_required','Existing account must already be an active practitioner.');
                if($this->q('SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code<>\'practitioner\'',[$user])->fetchColumn())throw new ApiException(409,'role_conflict','Existing elevated roles require a separate migration.');
            }else{
                // Same-email accounts are conflicts, never automatic identity matches.
                if($this->q('SELECT id FROM users WHERE clinic_id=? AND email=?',[$actor->clinicId,$invite['recipient_email']])->fetchColumn())throw new ApiException(409,'email_already_exists','A local account already uses that email; review its explicit user ID.');
                $this->q("INSERT INTO users(clinic_id,email,given_name,family_name,display_name,user_type,status) VALUES(?,?,?,?,?,'staff','active')",[$actor->clinicId,$invite['recipient_email'],$invite['given_name'],$invite['family_name'],trim($invite['given_name'].' '.$invite['family_name'])]);$user=(int)$this->database->connection()->lastInsertId();
                $this->q('INSERT INTO staff_accounts(user_id,mfa_required) VALUES(?,1)',[$user]);
                $this->q("INSERT INTO practitioners(user_id,discipline,booking_mode) VALUES(?,?,'practitioner_managed')",[$user,$invite['discipline']]);$practitioner=(int)$this->database->connection()->lastInsertId();
                $this->q('INSERT INTO practitioner_locations(practitioner_id,location_id,active) VALUES(?,?,1)',[$practitioner,$invite['location_id']]);
            }
            $this->q("INSERT INTO user_roles(user_id,role_id,location_id,assigned_by) SELECT ?,id,?,? FROM roles WHERE code='practitioner' AND NOT EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=? AND ur.role_id=roles.id)",[$user,$invite['location_id'],$actor->userId,$user]);
            if(!$this->q("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=? AND r.code='practitioner'",[$user])->fetchColumn())throw new ApiException(503,'role_not_configured','Practitioner role is unavailable.');
            $this->q("INSERT INTO staff_memberships(identity_id,clinic_id,local_user_id,status) VALUES(?,?,?,'active')",[$identity['id'],$actor->clinicId,$user]);$membership=(int)$this->database->connection()->lastInsertId();
            $this->q("UPDATE staff_invitation_claims SET status='approved',reviewed_by=?,reviewed_at=UTC_TIMESTAMP() WHERE id=?",[$actor->userId,$claim['id']]);
            $this->q('UPDATE staff_invitations SET accepted_at=UTC_TIMESTAMP(),accepted_membership_id=? WHERE id=?',[$membership,$id]);
            $this->audit->write($actor->clinicId,$actor,$cid,'staff.invitation.approve','staff_invitation',$id,'success',['user_id'=>(int)$user,'membership_id'=>$membership]);return ['user_id'=>(int)$user,'membership_id'=>$membership,'status'=>'approved'];
        });
    }
}
