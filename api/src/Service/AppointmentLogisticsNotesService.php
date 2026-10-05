<?php
declare(strict_types=1);

namespace Wellness\Service;

use PDO;
use Throwable;
use Wellness\Auth\AuthContext;
use Wellness\Database;
use Wellness\Http\ApiException;

/** Appointment-specific operational directions, never treatment documentation. */
final class AppointmentLogisticsNotesService
{
    public function __construct(private readonly Database $database, private readonly AuditLogger $audit) {}

    public static function authorize(AuthContext $actor): bool
    {
        if ($actor->userType !== 'staff' || !$actor->hasAnyRole('super_admin', 'clinic_admin', 'reception', 'practitioner')) {
            throw new ApiException(403, 'forbidden', 'You cannot access appointment logistics notes.');
        }
        // Scheduling on behalf of someone else does not grant access to their notes.
        return !$actor->hasAnyRole('super_admin', 'clinic_admin', 'reception');
    }

    public static function validateNote(array $input): string
    {
        if (!is_string($input['note'] ?? null)) {
            throw new ApiException(422, 'validation_error', 'A logistics note is required.');
        }
        $note = trim($input['note']);
        $length = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
        if ($note === '' || $length > 500 || strlen($note) > 2000 || preg_match('//u', $note) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)) {
            throw new ApiException(422, 'validation_error', 'The logistics note must be 1 to 500 characters of plain text.');
        }
        return $note;
    }

    public function list(AuthContext $actor, int $appointmentId, string $correlationId): array
    {
        $ownOnly = self::authorize($actor);
        $pdo = $this->database->connection();
        $this->appointment($pdo, $actor, $appointmentId, $ownOnly);
        $query = $pdo->prepare('SELECT n.id,n.note_text,n.created_at,u.display_name author_name
            FROM appointment_logistics_notes n JOIN users u ON u.id=n.author_user_id AND u.clinic_id=n.clinic_id
            WHERE n.clinic_id=:clinic AND n.appointment_id=:appointment ORDER BY n.id DESC LIMIT 101');
        $query->execute(['clinic'=>$actor->clinicId, 'appointment'=>$appointmentId]);
        $notes = $query->fetchAll();
        $truncated = count($notes) > 100;
        if ($truncated) $notes = array_slice($notes, 0, 100);
        $this->audit->write($actor->clinicId, $actor, $correlationId, 'appointment.logistics_notes.view', 'appointment', $appointmentId);
        return ['appointment_id'=>$appointmentId, 'notes'=>$notes, 'truncated'=>$truncated];
    }

    public function create(AuthContext $actor, int $appointmentId, array $input, string $correlationId): array
    {
        $ownOnly = self::authorize($actor);
        $note = self::validateNote($input);
        $pdo = $this->database->connection();
        $pdo->beginTransaction();
        try {
            $this->appointment($pdo, $actor, $appointmentId, $ownOnly, true);
            $insert = $pdo->prepare('INSERT INTO appointment_logistics_notes(clinic_id,appointment_id,author_user_id,note_text) VALUES(:clinic,:appointment,:author,:note)');
            $insert->execute(['clinic'=>$actor->clinicId, 'appointment'=>$appointmentId, 'author'=>$actor->userId, 'note'=>$note]);
            $noteId = (int)$pdo->lastInsertId();
            $created = $pdo->prepare('SELECT n.id,n.note_text,n.created_at,u.display_name author_name
                FROM appointment_logistics_notes n JOIN users u ON u.id=n.author_user_id AND u.clinic_id=n.clinic_id
                WHERE n.id=:note AND n.clinic_id=:clinic AND n.appointment_id=:appointment');
            $created->execute(['note'=>$noteId, 'clinic'=>$actor->clinicId, 'appointment'=>$appointmentId]);
            $result = $created->fetch(PDO::FETCH_ASSOC);
            if (!$result) throw new \RuntimeException('Saved logistics note could not be loaded.');
            $this->audit->write($actor->clinicId, $actor, $correlationId, 'appointment.logistics_note.create', 'appointment', $appointmentId, 'success', ['note_id'=>$noteId]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        return ['appointment_id'=>$appointmentId, 'note'=>$result];
    }

    private function appointment(PDO $pdo, AuthContext $actor, int $appointmentId, bool $ownOnly, bool $lock = false): void
    {
        $query = $pdo->prepare('SELECT a.id FROM appointments a JOIN practitioners p ON p.id=a.practitioner_id
            WHERE a.id=:appointment AND a.clinic_id=:clinic' . ($ownOnly ? ' AND p.user_id=:author' : '') . ($lock ? ' FOR UPDATE' : ''));
        $params = ['appointment'=>$appointmentId, 'clinic'=>$actor->clinicId];
        if ($ownOnly) $params['author'] = $actor->userId;
        $query->execute($params);
        if (!$query->fetchColumn()) throw new ApiException(404, 'not_found', 'Appointment not found.');
    }
}
