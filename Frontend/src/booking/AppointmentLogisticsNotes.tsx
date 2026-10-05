import { useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, CircularProgress, Divider, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatDateTime } from '../i18n/format';

type Note = { id: number; note_text: string; author_name: string; created_at: string };
type NoteList = { notes: Note[]; truncated: boolean };
type Props = {
  appointmentId: number;
  timezone: string;
  enabled: boolean;
  request: (path: string, init?: RequestInit) => Promise<unknown>;
};

export function AppointmentLogisticsNotes({ appointmentId, timezone, enabled, request }: Props) {
  const { t, i18n } = useTranslation();
  const [result, setResult] = useState<NoteList | null>(null);
  const [note, setNote] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);

  useEffect(() => {
    if (!enabled) return;
    const controller = new AbortController();
    setLoading(true); setError(''); setResult(null);
    void request(`/appointments/${appointmentId}/logistics-notes`, { signal: controller.signal })
      .then(data => {
        if (controller.signal.aborted) return;
        if (!data || typeof data !== 'object' || !('notes' in data) || !Array.isArray(data.notes)) throw new Error(t('Unable to load logistics notes.'));
        setResult(data as NoteList);
      })
      .catch(cause => { if (!controller.signal.aborted) setError(cause instanceof Error ? cause.message : t('Unable to load logistics notes.')); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [appointmentId, enabled, refresh, request, t]);

  async function save(event: FormEvent) {
    event.preventDefault();
    const value = note.trim();
    if (!value || value.length > 500 || saving) return;
    setSaving(true); setError('');
    try {
      await request(`/appointments/${appointmentId}/logistics-notes`, { method: 'POST', body: JSON.stringify({ note: value }) });
      setNote('');
      setRefresh(current => current + 1);
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : t('Unable to save logistics note.'));
    } finally {
      setSaving(false);
    }
  }

  return <Stack spacing={1.5}>
    <Divider />
    <Stack direction="row" justifyContent="space-between" alignItems="center" gap={1}>
      <Typography variant="h6">{t('Appointment logistics')}</Typography>
      <Button size="small" onClick={() => setRefresh(current => current + 1)} disabled={loading || saving}>{t('Refresh')}</Button>
    </Stack>
    <Typography variant="body2" color="text.secondary">{t('For arrival, access, and equipment details only. Do not enter treatment or health information. These notes stay with staff and are not sent to the client.')}</Typography>
    {loading && <CircularProgress size={20} aria-label={t('Loading logistics notes')} />}
    {error && <Alert severity="error">{error}</Alert>}
    {result && (result.notes.length === 0
      ? <Typography color="text.secondary">{t('No logistics notes recorded.')}</Typography>
      : <Stack spacing={1.5}>
          {result.notes.map(item => <Stack key={item.id} spacing={0.5}>
            <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{item.note_text}</Typography>
            <Typography variant="caption" color="text.secondary">{item.author_name} · {formatDateTime(`${item.created_at.replace(' ', 'T')}Z`, i18n.resolvedLanguage, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' })}</Typography>
          </Stack>)}
          {result.truncated && <Typography variant="body2" color="text.secondary">{t('Showing the latest 100 logistics notes.')}</Typography>}
        </Stack>)}
    <Stack component="form" onSubmit={event => { void save(event); }} spacing={1}>
      <TextField multiline minRows={2} maxRows={4} label={t('Add logistics note')} value={note} onChange={event => setNote(event.target.value)} inputProps={{ maxLength: 500 }} helperText={t('Examples: use side entrance; call on arrival; bring portable table.')} fullWidth />
      <Button type="submit" variant="outlined" disabled={!note.trim() || saving}>{saving ? t('Saving...') : t('Save note')}</Button>
    </Stack>
  </Stack>;
}
