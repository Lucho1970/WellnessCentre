import { useCallback, useEffect, useState } from 'react';
import { Alert, Button, Checkbox, FormControlLabel, Paper, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { sessionFetch } from '../auth/serverSession';
import { ApiError, apiBaseUrl, normalizeNumericIds } from '../shared/api';

type Document = { id: number; kind: string; version_code: string; title_en: string; title_fr: string; body_en: string; body_fr: string; content_hash: string; accepted: { accepted_at: string; displayed_language: string } | null };
export function PractitionerPolicyDocuments() {
  const { t, i18n } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Document[]>([]);
  const [checked, setChecked] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const language = i18n.language.startsWith('fr') ? 'fr' : 'en';
  const load = useCallback(async () => {
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/practitioner/documents`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to load practitioner documents.'));
      const result = normalizeNumericIds<{items: Document[]}>(body.data);
      if (!Array.isArray(result?.items)) throw new Error(t('Unable to load practitioner documents.'));
      setItems(result.items);
    } catch (cause) { setItems([]); setError(cause instanceof Error ? cause.message : t('Unable to load practitioner documents.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const accept = async (document: Document) => {
    if (!checked[document.id]) return;
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/practitioner/documents/${document.id}/accept`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ accepted: true, language, content_hash: document.content_hash }),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to record acknowledgement.'));
      setChecked(current => ({ ...current, [document.id]: false })); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to record acknowledgement.')); }
    finally { setBusy(false); }
  };
  return <Stack spacing={2}>
    <Alert severity="info">{t('Read each current document in full. Your acknowledgement is recorded against its exact version and your signed-in identity. This is separate from patient treatment consent.')}</Alert>
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{error}</Alert>}
    {items.map(document => <Paper key={document.id} variant="outlined" sx={{ p: 2.5 }}><Stack spacing={2}>
      <Typography variant="h6">{language === 'fr' ? document.title_fr : document.title_en} · {document.version_code}</Typography>
      <Typography component="div" sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', maxHeight: 520, overflowY: 'auto', border: '1px solid', borderColor: 'divider', p: 2 }} tabIndex={0} aria-label={t('Document text')}>{language === 'fr' ? document.body_fr : document.body_en}</Typography>
      {document.accepted ? <Alert severity="success">{t('Acknowledged on {{date}}', { date: document.accepted.accepted_at })}</Alert> : <>
        <FormControlLabel control={<Checkbox checked={Boolean(checked[document.id])} onChange={event => setChecked(current => ({ ...current, [document.id]: event.target.checked }))} />} label={t('I have read and agree to this exact document version.')} />
        <Button variant="contained" disabled={busy || !checked[document.id]} onClick={() => void accept(document)} sx={{ alignSelf: 'flex-start' }}>{t('Record my acknowledgement')}</Button>
      </>}
    </Stack></Paper>)}
    {!busy && items.length===0 && !error && <Alert severity="warning">{t('The clinic has not published practitioner documents yet. Clinic note access stays closed.')}</Alert>}
  </Stack>;
}
