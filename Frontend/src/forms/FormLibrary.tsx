import { useEffect, useRef, useState } from 'react';
import { Alert, Button, Chip, Dialog, DialogContent, DialogTitle, MenuItem, Paper, Stack, Typography } from '@mui/material';
import { TextField, withFormValidation, useFormValidation } from '../shared/FormValidation';
import { useTranslation } from 'react-i18next';
import { useFormRequest } from './api';
import type { FormDocument } from './FormSourceDialog';
import type { Definition } from './FormTasks';
import { FormDefinitionEditor } from './FormDefinitionEditor';
import { FormPreview } from './FormPreview';
import { useUnsavedChanges } from '../shared/UnsavedChanges';

type Item = { id: number; entry_id: number; version: number; revision: number; scope: string; name: string; description: string; form_type: string; definition?: Definition; status: string; review_note: string; can_review: boolean; can_revise: boolean; can_retire: boolean; can_use: boolean };
type Props = { central?: boolean; practitioners?: { id: number; display_name?: string }[]; onUse?: (id: number, owner: number, key: string) => Promise<void>; source?: FormDocument; onSubmitted?: () => void };
const empty = (): Definition => ({ instructions: '', instructions_fr: '', sections: [], questions: [{ id: 'q1', label: '', label_fr: '', type: 'text', required: true }] });

const LibraryEditor = withFormValidation(function LibraryEditor({ initial, scope, maxQuestions, submit, cancel }: { initial?: Item | FormDocument; scope: string; maxQuestions: number; submit: (body: object) => Promise<void>; cancel: () => void }) {
  const { t } = useTranslation(), validation = useFormValidation();
  const [name, setName] = useState(initial?.name ?? ''), [description, setDescription] = useState(initial && 'description' in initial ? initial.description : '');
  const [type, setType] = useState(initial?.form_type ?? 'intake');
  const [definition, setDefinition] = useState<Definition>(() => structuredClone(initial?.definition ?? empty()));
  const [busy, setBusy] = useState(false), [error, setError] = useState('');
  const [key] = useState(() => crypto.randomUUID());
  useUnsavedChanges(true);
  return <Stack component="form" spacing={2} onSubmit={event => { event.preventDefault(); setBusy(true); setError(''); validation.clear(); void submit({ scope, name, description, form_type: type, definition, idempotency_key: key, ...(initial && 'entry_id' in initial ? { entry_id: initial.entry_id, expected_version: initial.version } : {}) }).catch(cause => { validation.capture(cause); setError(cause.message); }).finally(() => setBusy(false)); }}>
    <Alert severity="info">{t('Submitting sends this version for approval. Existing approved versions and copies remain unchanged.')}</Alert>
    {error && <Alert severity="error">{error}</Alert>}
    <TextField name="name" label={t('Form name')} required value={name} inputProps={{ maxLength: 190 }} disabled={busy} onChange={event => setName(event.target.value)}/>
    <TextField name="description" label={t('Template description')} required multiline minRows={2} value={description} inputProps={{ maxLength: 2000 }} disabled={busy} onChange={event => setDescription(event.target.value)}/>
    <TextField select label={t('Form type')} value={type} disabled={busy} onChange={event => setType(event.target.value)}>{['intake', 'consent', 'follow_up', 'questionnaire'].map(value => <MenuItem key={value} value={value}>{t(`Form type: ${value}`)}</MenuItem>)}</TextField>
    <FormDefinitionEditor definition={definition} setDefinition={setDefinition} disabled={busy} maxQuestions={maxQuestions}/>
    <Stack direction="row" gap={1}><Button type="submit" variant="contained" disabled={busy}>{t('Submit for approval')}</Button><Button disabled={busy} onClick={cancel}>{t('Cancel')}</Button></Stack>
  </Stack>;
});

export function FormLibrary({ central = false, practitioners = [], onUse, source, onSubmitted }: Props) {
  const { t } = useTranslation(), request = useFormRequest();
  const base = central ? '/application/form-library' : '/forms/library';
  const [scope, setScope] = useState('global'), [mode, setMode] = useState('browse'), [search, setSearch] = useState('');
  const [page, setPage] = useState(1), [reload, setReload] = useState(0), [data, setData] = useState<{ items: Item[]; has_more: boolean; can_review: boolean; max_questions: number }>();
  const [error, setError] = useState(''), [notice, setNotice] = useState(''), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false);
  const [editor, setEditor] = useState<Item | FormDocument | null | undefined>(source), [detail, setDetail] = useState<Item | null>(null), [note, setNote] = useState('');
  const [owner, setOwner] = useState(practitioners.length === 1 ? String(practitioners[0].id) : '');
  const copyKeys = useRef<Record<string, string>>({});
  useEffect(() => {
    const controller = new AbortController(); setLoading(true); setError('');
    void request<{ items: Item[]; has_more: boolean; can_review: boolean; max_questions: number }>(`${base}?${new URLSearchParams({ scope, mode, q: search, page: String(page) })}`, { signal: controller.signal })
      .then(value => { if (!controller.signal.aborted) setData(value); })
      .catch(cause => { if (!controller.signal.aborted) setError(cause.message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => controller.abort();
  }, [request, base, scope, mode, search, page, reload]);
  const run = async (action: () => Promise<void>) => { setBusy(true); setError(''); try { await action(); } catch (cause) { setError((cause as Error).message); } finally { setBusy(false); } };
  const load = async (item: Item) => request(`${base}/${item.id}`) as Promise<Item>;
  const review = (action: string) => void run(async () => {
    if (!detail || (action === 'retire' && !window.confirm(t('Retire this library template? Existing copies will remain available.')))) return;
    await request(`${base}/${detail.id}/review`, { method: 'POST', body: JSON.stringify({ action, revision: detail.revision, review_note: note }) });
    setDetail(null); setReload(value => value + 1); setNotice(t('Library review saved.'));
  });
  return <Paper variant="outlined" sx={{ p: 2 }}><Stack spacing={2}>
    <Typography variant="h5">{t('Form library')}</Typography>
    <Typography>{t('Choose an approved template to create your own editable draft. Copies contain no client answers or service assignments.')}</Typography>
    {error && <Alert severity="error">{error}</Alert>}{notice && <Alert severity="success">{notice}</Alert>}
    <Stack direction={{ xs: 'column', sm: 'row' }} gap={1}>
      {!central && <TextField select label={t('Library')} value={scope} disabled={busy} onChange={event => { setScope(event.target.value); setMode('browse'); setPage(1); }}><MenuItem value="global">{t('Global library')}</MenuItem><MenuItem value="clinic">{t('Clinic library')}</MenuItem></TextField>}
      <TextField select label={t('Library view')} value={mode} disabled={busy} onChange={event => { setMode(event.target.value); setPage(1); }}><MenuItem value="browse">{t('Approved templates')}</MenuItem><MenuItem value="submissions">{t('Submissions')}</MenuItem>{data?.can_review && <MenuItem value="review">{t('Awaiting approval')}</MenuItem>}</TextField>
      <TextField label={t('Search library')} value={search} onChange={event => { setSearch(event.target.value); setPage(1); }}/>
    </Stack>
    <Button disabled={busy || loading || !!error} onClick={() => setEditor(null)}>{t('Create library template')}</Button>
    {!central && onUse && <TextField select label={t('Assigned practitioner')} value={owner} disabled={busy} onChange={event => setOwner(event.target.value)}>{practitioners.map(p => <MenuItem key={p.id} value={p.id}>{p.display_name}</MenuItem>)}</TextField>}
    {loading ? <Typography>{t('Loading forms')}</Typography> : !error && !data?.items.length ? <Typography>{t('No library templates match this view.')}</Typography> : !error && data?.items.map(item => <Paper variant="outlined" key={item.id} sx={{ p: 2 }}><Stack spacing={1}>
      <Typography variant="h6">{item.name}</Typography><Typography sx={{ whiteSpace: 'pre-wrap' }}>{item.description}</Typography><Chip sx={{ alignSelf: 'flex-start' }} label={`${t(`Library status: ${item.status}`)} · ${t('Form version {{version}}', { version: item.version })}`}/>
      {item.review_note && <Typography>{item.review_note}</Typography>}
      <Stack direction="row" gap={1} flexWrap="wrap"><Button disabled={busy} onClick={() => void run(async () => { setDetail(await load(item)); setNote(''); })}>{t('Preview template')}</Button>
        {item.can_use && onUse && <Button disabled={busy || !owner} onClick={() => void run(async () => { const key = `${item.id}:${owner}`; copyKeys.current[key] ??= crypto.randomUUID(); await onUse(item.id, Number(owner), copyKeys.current[key]); })}>{t('Use template')}</Button>}
        {item.can_revise && item.status !== 'pending' && <Button disabled={busy} onClick={() => void run(async () => setEditor(await load(item)))}>{t('Revise library template')}</Button>}
      </Stack>
    </Stack></Paper>)}
    <Stack direction="row"><Button disabled={busy || loading || page === 1} onClick={() => setPage(value => value - 1)}>{t('Previous')}</Button><Button disabled={busy || loading || !data?.has_more} onClick={() => setPage(value => value + 1)}>{t('Next')}</Button><Button disabled={busy || loading} onClick={() => setReload(value => value + 1)}>{t('Refresh')}</Button></Stack>
    <Dialog open={editor !== undefined} fullWidth maxWidth="md"><DialogTitle>{t('Library template')}</DialogTitle><DialogContent><Stack spacing={2} sx={{ pt: 1 }}>
      {!central && (editor === null || (editor && !('entry_id' in editor))) && <TextField select label={t('Submit to library')} value={scope} onChange={event => setScope(event.target.value)}><MenuItem value="global">{t('Global library')}</MenuItem><MenuItem value="clinic">{t('Clinic library')}</MenuItem></TextField>}
      {editor !== undefined && <LibraryEditor key={editor && 'id' in editor ? editor.id : source ? 'source' : 'new'} initial={editor ?? undefined} scope={editor && 'scope' in editor ? editor.scope : scope} maxQuestions={data?.max_questions ?? 100} cancel={() => { setEditor(undefined); onSubmitted?.(); }} submit={async body => { await request(base, { method: 'POST', body: JSON.stringify(body) }); setEditor(undefined); setReload(value => value + 1); setNotice(t('Template submitted for approval.')); onSubmitted?.(); }}/>}
    </Stack></DialogContent></Dialog>
    <Dialog open={detail !== null} fullWidth maxWidth="md"><DialogTitle>{t('Template preview')}</DialogTitle><DialogContent><Stack spacing={2}>{error && <Alert severity="error">{error}</Alert>}
      {detail?.definition && <FormPreview key={detail.id} name={detail.name} definition={detail.definition}/>}
      {detail?.can_review && <><TextField label={t('Review note')} multiline value={note} onChange={event => setNote(event.target.value)} disabled={busy}/><Stack direction="row"><Button disabled={busy} onClick={() => review('approve')}>{t('Approve template')}</Button><Button disabled={busy || !note.trim()} onClick={() => review('reject')}>{t('Reject template')}</Button></Stack></>}
      {detail?.can_retire && <Button disabled={busy} onClick={() => review('retire')}>{t('Retire template')}</Button>}
      <Button disabled={busy} onClick={() => setDetail(null)}>{t('Close')}</Button>
    </Stack></DialogContent></Dialog>
  </Stack></Paper>;
}
