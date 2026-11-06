import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Alert, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { sessionFetch } from '../auth/serverSession';
import { ApiError, apiBaseUrl, normalizeNumericIds } from '../shared/api';

type Document = { id: number; kind: string; version_code: string; title_en: string; title_fr: string; body_en: string; body_fr: string; content_hash: string; status: string; review_reference: string | null };
const blank = () => ({ kind: 'records_agreement', version_code: '', title_en: '', title_fr: '', body_en: '', body_fr: '' });
export function AdminPolicyDocuments() {
  const { t } = useTranslation();
  const { getAccessToken } = useStaffAuth();
  const [items, setItems] = useState<Document[]>([]);
  const [draft, setDraft] = useState(blank);
  const [publishing, setPublishing] = useState<Document | null>(null);
  const [reference, setReference] = useState('');
  const [confirmed, setConfirmed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const load = useCallback(async () => {
    setBusy(true); setError('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/admin/practitioner-documents`, { headers: { Authorization: `Bearer ${token}` } });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to load practitioner documents.'));
      const result = normalizeNumericIds<{items: Document[]}>(body.data);
      if (!Array.isArray(result?.items)) throw new Error(t('Unable to load practitioner documents.'));
      setItems(result.items);
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to load practitioner documents.')); }
    finally { setBusy(false); }
  }, [getAccessToken, t]);
  useEffect(() => { void load(); }, [load]);
  const create = async (event: FormEvent) => {
    event.preventDefault(); setBusy(true); setError(''); setNotice('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/admin/practitioner-documents`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(draft),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to save document draft.'));
      setDraft(blank()); setNotice(t('Document draft saved.')); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to save document draft.')); }
    finally { setBusy(false); }
  };
  const publish = async () => {
    if (!publishing || !confirmed) return;
    setBusy(true); setError(''); setNotice('');
    try {
      const token = await getAccessToken();
      const response = await sessionFetch(`${apiBaseUrl}/admin/practitioner-documents/${publishing.id}/publish`, {
        method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
        body: JSON.stringify({ confirmed: true, review_reference: reference.trim() }),
      });
      const body = await response.json();
      if (!response.ok) throw new ApiError(body, response.status, t('Unable to publish document.'));
      setPublishing(null); setReference(''); setConfirmed(false); setNotice(t('Document published. Practitioners must acknowledge this version.')); await load();
    } catch (cause) { setError(cause instanceof Error ? cause.message : t('Unable to publish document.')); }
    finally { setBusy(false); }
  };
  const setField = (field: keyof ReturnType<typeof blank>, value: string) => setDraft(current => ({ ...current, [field]: value }));
  return <Stack spacing={2}>
    <Alert severity="warning">{t('Publish only after the clinic owner, Ontario privacy/legal reviewer and applicable clinical reviewer approve both languages. This screen records the review reference; it cannot perform that review.')}</Alert>
    {error && <Alert severity="error" action={<Button color="inherit" onClick={() => void load()}>{t('Retry')}</Button>}>{error}</Alert>}
    {notice && <Alert severity="success">{notice}</Alert>}
    <Paper variant="outlined" component="form" onSubmit={event => void create(event)} sx={{ p: 2.5 }}><Stack spacing={2}>
      <Typography variant="h6">{t('New practitioner document draft')}</Typography>
      <TextField select label={t('Document type')} value={draft.kind} onChange={event => setField('kind', event.target.value)}>{['services_agreement','records_agreement','confidentiality','departure'].map(kind => <MenuItem key={kind} value={kind}>{t(kind)}</MenuItem>)}</TextField>
      <TextField label={t('Version code')} value={draft.version_code} onChange={event => setField('version_code', event.target.value)} inputProps={{ maxLength: 40 }} required />
      <TextField label={t('English title')} value={draft.title_en} onChange={event => setField('title_en', event.target.value)} inputProps={{ maxLength: 190 }} required />
      <TextField label={t('French title')} value={draft.title_fr} onChange={event => setField('title_fr', event.target.value)} inputProps={{ maxLength: 190 }} required />
      <TextField label={t('English document text')} value={draft.body_en} onChange={event => setField('body_en', event.target.value)} multiline minRows={8} required />
      <TextField label={t('French document text')} value={draft.body_fr} onChange={event => setField('body_fr', event.target.value)} multiline minRows={8} required />
      <Button type="submit" variant="contained" disabled={busy}>{t('Save draft')}</Button>
    </Stack></Paper>
    <Typography variant="h6">{t('Stored document versions')}</Typography>
    {items.map(item => <Paper key={item.id} variant="outlined" sx={{ p: 2 }}><Stack spacing={1}>
      <Typography fontWeight={700}>{item.title_en} / {item.title_fr} · {item.version_code}</Typography>
      <Typography color="text.secondary">{t(item.kind)} · {t(item.status)} · SHA-256 {item.content_hash}</Typography>
      <Typography sx={{ whiteSpace: 'pre-wrap', maxHeight: 180, overflowY: 'auto' }}>{item.body_en}</Typography>
      <Typography sx={{ whiteSpace: 'pre-wrap', maxHeight: 180, overflowY: 'auto' }}>{item.body_fr}</Typography>
      {item.status==='draft' && <Button disabled={busy} onClick={() => { setPublishing(item); setReference(''); setConfirmed(false); }}>{t('Review for publication')}</Button>}
    </Stack></Paper>)}
    <Dialog open={publishing!==null} onClose={() => { if(!busy)setPublishing(null); }} fullWidth aria-labelledby="publish-document-title">
      <DialogTitle id="publish-document-title">{t('Publish practitioner document')}</DialogTitle>
      <DialogContent><Stack spacing={2} sx={{ pt: 1 }}>
        <Typography>{publishing?.title_en} · {publishing?.version_code}</Typography>
        <Typography>{t('Publication makes this exact bilingual version current and requires new practitioner acknowledgement. Confirm external legal and clinical review first.')}</Typography>
        <TextField label={t('Approval reference')} value={reference} onChange={event => setReference(event.target.value)} inputProps={{ maxLength: 120 }} required helperText={t('Use a review record identifier, not patient information.')} />
        <FormControlLabel control={<Checkbox checked={confirmed} onChange={event => setConfirmed(event.target.checked)} />} label={t('I confirm both languages were approved by the accountable reviewers.')} />
        {error && <Alert severity="error">{error}</Alert>}
      </Stack></DialogContent>
      <DialogActions><Button disabled={busy} onClick={() => setPublishing(null)}>{t('Cancel')}</Button><Button variant="contained" disabled={busy || !confirmed || reference.trim().length<8} onClick={() => void publish()}>{t('Publish version')}</Button></DialogActions>
    </Dialog>
  </Stack>;
}
