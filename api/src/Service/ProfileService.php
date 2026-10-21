<?php
declare(strict_types=1);
namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

final class ProfileService
{
    private const STAFF_ROLES=['super_admin','clinic_admin','practitioner','reception','accountant'];
    public function __construct(private readonly Database $db,private readonly AuditLogger $audit) {}

    /** An explicit target is moderation, including when it is the administrator's own ID. */
    private function access(AuthContext $actor,?int $target,callable $work): array
    {
        $roles=$target===null?self::STAFF_ROLES:['super_admin'];
        if($actor->userType!=='staff'||!$actor->hasAnyRole(...$roles))throw new ApiException(403,'forbidden','Staff avatar access is not permitted.');
        $pdo=$this->db->connection();
        if($pdo->inTransaction())throw new \LogicException('Avatar operations require their own transaction.');
        $lock=new ConsentLock($pdo);$lock->acquire($actor->clinicId);
        try{
            $pdo->beginTransaction();
            $q=$pdo->prepare("SELECT id FROM clinics WHERE id=? AND status='active' FOR UPDATE");$q->execute([$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Staff avatar access is not permitted.');
            $marks=implode(',',array_fill(0,count($roles),'?'));
            $q=$pdo->prepare("SELECT u.id FROM users u WHERE u.id=? AND u.clinic_id=? AND u.user_type='staff' AND u.status='active'
                AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.code IN ($marks)) FOR UPDATE");
            $q->execute([$actor->userId,$actor->clinicId,...$roles]);
            if(!$q->fetchColumn())throw new ApiException(403,'forbidden','Staff avatar access is not permitted.');
            $uid=$target??$actor->userId;
            $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND clinic_id=? AND user_type='staff' FOR UPDATE");$q->execute([$uid,$actor->clinicId]);
            if(!$q->fetchColumn())throw new ApiException(404,'user_not_found','User not found.');
            $result=$work($pdo,$uid);$pdo->commit();return ['user_id'=>$uid]+$result;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        finally{$lock->release();}
    }

    public function avatar(AuthContext $actor,?int $target=null): array
    {
        return $this->access($actor,$target,static function(PDO $pdo,int $uid):array{
            $q=$pdo->prepare('SELECT mime_type,image_data,updated_at FROM user_profile_images WHERE user_id=?');$q->execute([$uid]);$row=$q->fetch();
            return $row?['mime_type'=>$row['mime_type'],'image_base64'=>base64_encode($row['image_data']),'updated_at'=>$row['updated_at']]:['mime_type'=>null,'image_base64'=>null,'updated_at'=>null];
        });
    }

    public function save(AuthContext $actor,array $body,string $cid,?int $target=null): array
    {
        return $this->access($actor,$target,function(PDO $pdo,int $uid)use($actor,$body,$cid):array{
            $encoded=$body['image_base64']??null;
            if(!is_string($encoded)||$encoded===''||strlen($encoded)>750000)throw new ApiException(422,'validation_error','Choose an image smaller than 500 KB.');
            $data=base64_decode($encoded,true);
            if($data===false||strlen($data)>500000)throw new ApiException(422,'validation_error','The image is invalid or too large.');
            $info=@getimagesizefromstring($data);
            if($info===false||($info['mime']??'')!=='image/webp'||$info[0]<64||$info[1]<64||$info[0]>512||$info[1]>512)throw new ApiException(422,'validation_error','The image must be WebP and between 64 and 512 pixels.');
            $q=$pdo->prepare("INSERT INTO user_profile_images(user_id,mime_type,image_data,byte_size,content_hash,updated_by) VALUES(:user,'image/webp',:data,:size,:hash,:actor) ON DUPLICATE KEY UPDATE image_data=VALUES(image_data),byte_size=VALUES(byte_size),content_hash=VALUES(content_hash),updated_by=VALUES(updated_by)");
            $q->bindValue(':user',$uid,PDO::PARAM_INT);$q->bindValue(':data',$data,PDO::PARAM_LOB);$q->bindValue(':size',strlen($data),PDO::PARAM_INT);$q->bindValue(':hash',hash('sha256',$data));$q->bindValue(':actor',$actor->userId,PDO::PARAM_INT);$q->execute();
            $this->audit->write($actor->clinicId,$actor,$cid,'profile.avatar.update','user',$uid,'success',['moderated'=>$uid!==$actor->userId]);return ['updated'=>true];
        });
    }

    public function delete(AuthContext $actor,string $cid,?int $target=null): array
    {
        return $this->access($actor,$target,function(PDO $pdo,int $uid)use($actor,$cid):array{
            $q=$pdo->prepare('DELETE FROM user_profile_images WHERE user_id=?');$q->execute([$uid]);$deleted=$q->rowCount()>0;
            $this->audit->write($actor->clinicId,$actor,$cid,'profile.avatar.delete','user',$uid,'success',['moderated'=>$uid!==$actor->userId]);return ['deleted'=>$deleted];
        });
    }
}
