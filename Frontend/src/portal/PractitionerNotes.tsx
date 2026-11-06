import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { Alert, Button, FormControlLabel, Paper, Stack, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { sessionFetch } from '../auth/serverSession';
import { ApiError, apiBaseUrl, normalizeNumericIds } from '../shared/api';

type Note = { id: number; note_text: string; created_at: string; visibility: 'author' | 'care_team'; practitioner_id: number; author_name: string };
export function PractitionerNotes({ clientId }: { clientId: number }) {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [notes, setNotes] = useState<Note[]>([]);
  const [more, setMore] = useState(false);
  const [text, setText] = useState('');
  const [shared, setShared] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const requestKey = useRef<string | null>(null);
  const load = useCallback(async (beforeId?: number) => {
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const suffix = beforeId ? `?before_id=${beforeId}` : '';
      const response = await sessionFetch(`${apiBaseUrl}/practitioner/clients/${clientId}/notes${suffix}`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to load clinic notes.'));
      const result = normalizeNumericIds<{items: Note[]; has_more: boolean}>(body.data);
      if (!Array.isArray(result?.items) || typeof result.has_more !== 'boolean') throw new Error(t('Unable to load clinic notes.'));
      setNotes(current => beforeId ? [...current, ...result.items] : result.items);
      setMore(result.has_more);
    } catch (cause) { setNotes([]); setMore(false); setError(cause instanceof Error ? cause.message : t('Unable to load clinic notes.')); }
    finally { setBusy(false); }
  }, [clientId, getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const save = async (event: FormEvent) => {
    event.preventDefault(); if (!text.trim()) return;
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      requestKey.current ??= crypto.randomUUID();
      const response = await sessionFetch(`${apiBaseUrl}/practitioner/clients/${clientId}/notes`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ note_text: text.trim(), visibility: shared ? 'care_team' : 'author', idempotency_key: requestKey.current }),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to save clinic note.'));
      requestKey.current = null; setText(''); setShared(false); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save clinic note.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={1.5}>
    <Typography variant="h6">{t('Clinic care notes')}</Typography>
    <Alert severity="info">{t('Notes belong to the clinic record. They cannot be edited or deleted here. Add a correction as a new note. Share only when other assigned practitioners need it for care.')}</Alert>
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{error}</Alert>}
    <Stack component="form" spacing={1} onSubmit={event => void save(event)}>
      <TextField name="note_text" label={t('New clinic note')} multiline minRows={4} value={text} onChange={event => { requestKey.current = null; setText(event.target.value); }} inputProps={{ maxLength: 10000 }} required />
      <FormControlLabel control={<Switch checked={shared} onChange={event => { requestKey.current = null; setShared(event.target.checked); }} />} label={t('Share with assigned care team')} />
      <Button type="submit" variant="contained" disabled={busy || !text.trim()}>{t('Add note')}</Button>
    </Stack>
    {notes.map(note => <Paper key={note.id} variant="outlined" sx={{ p: 1.5 }}><Stack spacing={0.5}>
      <Typography variant="caption" color="text.secondary">{note.author_name} · {note.created_at} · {t(note.visibility === 'care_team' ? 'Shared with care team' : 'Author only')}</Typography>
      <Typography sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{note.note_text}</Typography>
    </Stack></Paper>)}
    {!busy && notes.length===0 && !error && <Typography>{t('No clinic notes available.')}</Typography>}
    {more && <Button disabled={busy} onClick={() => void load(notes.at(-1)?.id)}>{t('Load older notes')}</Button>}
  </Stack>;
}
