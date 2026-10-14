import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Button, FormControlLabel, Paper, Stack, Switch, Typography } from '@mui/material';
import { Alert, TextField, useFormValidation, withFormValidation } from '../shared/FormValidation';
import { apiRequest } from '../shared/api';
import { useStaffAuth } from '../auth/AuthProvider';
import { useTranslation } from 'react-i18next';

type Credential = { id: number; name: string; issuer: string; issued_on: string; expires_on: string | null; shared_here: number | string };
const blank = { name: '', issuer: '', issued_on: '', expires_on: '' };
function GlobalCredentialsForm({ onRegistered, onSharingChanged }: { onRegistered?: (registered: boolean) => void; onSharingChanged?: () => void }) {
  const { t } = useTranslation(); const { getAccessToken } = useStaffAuth(); const validation = useFormValidation();
  const [records, setRecords] = useState<Credential[]>([]); const [draft, setDraft] = useState({ ...blank });
  const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [message, setMessage] = useState('');
  const [ready, setReady] = useState(false);
  const headers = useCallback(async () => ({ Authorization: `Bearer ${await getAccessToken()}`, 'Content-Type': 'application/json' }), [getAccessToken]);
  const load = useCallback(async () => { const data = await apiRequest<{ items: Credential[] }>('/profile/global-credentials', { headers: await headers() }); if (!Array.isArray(data.items)) throw new Error(t('Global credentials are not available yet.')); setRecords(data.items); setError(''); setReady(true); onRegistered?.(true); }, [headers, onRegistered, t]);
  useEffect(() => { void load().catch(cause => setError(cause.message)); }, [load]);
  const save = async (path: string, method: string, body: object, success: string) => {
    setBusy(true); setError(''); setMessage(''); validation.clear();
    try { await apiRequest(path, { method, headers: await headers(), body: JSON.stringify(body) }); await load(); setMessage(t(success)); if (method === 'POST') setDraft({ ...blank }); else onSharingChanged?.(); }
    catch (cause) { validation.capture(cause); setError(cause instanceof Error ? cause.message : t('Unable to save credential.')); }
    finally { setBusy(false); }
  };
  const create = (event: FormEvent) => { event.preventDefault(); void save('/profile/global-credentials', 'POST', { ...draft, expires_on: draft.expires_on || null }, 'Credential saved privately. Choose whether to share it with this clinic.'); };
  return <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 } }}><Stack spacing={2}>
    <Typography variant="h6">{t('My global credentials')}</Typography>
    <Typography color="text.secondary">{t('Your library follows you across clinics. Only you choose which credentials to share here. Sharing submits the credential for this clinic to review; other clinics receive nothing.')}</Typography>
    {error && <Alert severity="error" action={<Button onClick={() => void load().catch(cause => setError(cause.message))}>{t('Retry')}</Button>}>{error}</Alert>}{message && <Alert severity="success">{message}</Alert>}
    {records.map(record => <Paper key={record.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
      <Typography fontWeight={700}>{record.name}</Typography><Typography>{record.issuer} · {record.issued_on} · {record.expires_on || t('No expiry')}</Typography>
      <FormControlLabel label={t('Share {{name}} with this clinic', { name: record.name })} control={<Switch disabled={busy} checked={Boolean(Number(record.shared_here))} onChange={(_, checked) => void save(`/profile/global-credentials/${record.id}/sharing`, 'PUT', { shared: checked }, checked ? 'Credential shared with this clinic for review.' : 'Credential sharing withdrawn from this clinic.')}/>}/>
    </Stack></Paper>)}
    {ready && <Stack component="form" spacing={1.5} onSubmit={create}>
      <Typography fontWeight={700}>{t('Add a private credential')}</Typography>
      <TextField name="name" label={t('Qualification name')} required value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })}/>
      <TextField name="issuer" label={t('Issuing organization')} required value={draft.issuer} onChange={e => setDraft({ ...draft, issuer: e.target.value })}/>
      <TextField name="issued_on" label={t('Issue date')} type="date" required slotProps={{ inputLabel: { shrink: true } }} value={draft.issued_on} onChange={e => setDraft({ ...draft, issued_on: e.target.value })}/>
      <TextField name="expires_on" label={t('Expiry date')} type="date" slotProps={{ inputLabel: { shrink: true } }} value={draft.expires_on} onChange={e => setDraft({ ...draft, expires_on: e.target.value })}/>
      <Button type="submit" variant="contained" disabled={busy}>{t('Save private credential')}</Button>
    </Stack>}
  </Stack></Paper>;
}
export const GlobalCredentials = withFormValidation(GlobalCredentialsForm);
