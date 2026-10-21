import { useEffect, useRef, useState } from 'react';
import { Alert, Box, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useStaffAuth } from '../auth/AuthProvider';
import { useClinicConfig } from '../config/ClinicConfigProvider';
import { apiRequest, ApiError } from '../shared/api';
import { useUnsavedChanges } from '../shared/UnsavedChanges';
import { priceCents, readServiceWorkbook, type ImportRow } from './serviceImportWorkbook';

type Counts = { create: number; update: number; skip: number; invalid: number };
type Result = { preview_id: string; applied: boolean; counts: Counts; categories_created: number; rows: { row_number: number; service_id: number | null; action: string }[] };
type Preview = { preview_id: string; confirmation_hash: string; expires_at: string; can_import: boolean; counts: Counts; new_categories: string[]; rows: { row_number: number; service_id: number | null; existing_name: string | null; action: string; errors: string[]; warnings: string[] }[] };
export const importErrors: Record<string, string> = {
  invalid_row_number: 'Invalid source row number.', duplicate_row_number: 'Source row numbers must be unique.', unexpected_fields: 'Unexpected import fields.',
  invalid_category: 'Check the category name.', invalid_name: 'Check the English service name.', invalid_name_fr: 'Check the French service name.',
  invalid_public_summary: 'Check the English summary.', invalid_public_summary_fr: 'Check the French summary.', invalid_description: 'Check the English description.', invalid_description_fr: 'Check the French description.',
  invalid_type: 'Use Consultation, Treatment or Assessment for Type.', invalid_initial_consultation: 'Use Yes or No for Initial Consultation.',
  invalid_durations: 'Use unique durations from 15 to 480 minutes in 15-minute increments.', invalid_service_code: 'Check the service code or English name.',
  ambiguous_service: 'The service matches multiple existing records.', ambiguous_category: 'The category matches multiple existing records.',
  missing_category: 'The category does not exist. Enable category creation or correct its name.', missing_prices: 'Enter a price for every duration of this new service.', invalid_prices: 'Enter valid CAD prices for every duration. Zero explicitly means free.',
  invalid_service: 'Check the service values.', update_not_allowed: 'This service update is not permitted under current access restrictions.', duplicate_in_file: 'This service is repeated or conflicts with another row in the workbook.',
};
const actions: Record<string, string> = { create: 'Create', update: 'Update content', skip: 'Skip' };

export function ServiceImport({ onClose, onImported, onDenied }: { onClose: () => void; onImported: () => void; onDenied: (error: ApiError) => void }) {
  const { t } = useTranslation(); const { getAccessToken } = useStaffAuth(); const { config } = useClinicConfig();
  const [rows, setRows] = useState<ImportRow[]>([]); const [fileName, setFileName] = useState('');
  const [policy, setPolicy] = useState('skip'); const [createCategories, setCreateCategories] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null); const [result, setResult] = useState<Result | null>(null);
  const [dirty, setDirty] = useState(true); const [confirmed, setConfirmed] = useState(false); const [busy, setBusy] = useState(false); const [error, setError] = useState(''); const [discard, setDiscard] = useState(false);
  const generation = useRef(0); useEffect(() => () => { generation.current++; }, []);
  useUnsavedChanges(rows.length > 0 && !result);
  const change = () => { setDirty(true); setConfirmed(false); setError(''); };
  const request = async <T,>(path: string, body: unknown): Promise<T> => {
    const token = await getAccessToken();
    return apiRequest<T>(path, { method: 'POST', headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
  };
  const failure = (cause: unknown) => {
    if (cause instanceof ApiError && [401, 403].includes(cause.status)) {
      generation.current++; setRows([]); setPreview(null); setResult(null); setFileName(''); onDenied(cause); return;
    }
    if (cause instanceof ApiError && [404, 409, 422].includes(cause.status)) { setPreview(null); setDirty(true); setConfirmed(false); }
    setError(cause instanceof ApiError ? cause.message : t('Import request failed. Retry the same preview safely.'));
  };
  const selectFile = async (file?: File) => {
    if (!file) return;
    const current = ++generation.current; setBusy(true); setRows([]); setPreview(null); setResult(null); setFileName(''); change();
    try { const parsed = await readServiceWorkbook(file); if (current === generation.current) { setRows(parsed); setFileName(file.name); } }
    catch (cause) { if (current === generation.current) setError(t(cause instanceof Error ? cause.message : 'Choose a valid service workbook with an All Services sheet.')); }
    finally { if (current === generation.current) setBusy(false); }
  };
  const runPreview = async () => {
    const current = ++generation.current; setBusy(true); setError(''); setConfirmed(false);
    try {
      const data = await request<Preview>('/admin/service-imports/preview', { request_key: crypto.randomUUID(), file_name: fileName, rows, duplicate_policy: policy, create_categories: createCategories });
      if (current === generation.current) { setPreview(data); setDirty(false); }
    } catch (cause) { if (current === generation.current) failure(cause); }
    finally { if (current === generation.current) setBusy(false); }
  };
  const apply = async () => {
    if (!preview || dirty || !preview.can_import || !confirmed) return;
    const current = ++generation.current; setBusy(true); setError('');
    try {
      const data = await request<Result>('/admin/service-imports/apply', { preview_id: preview.preview_id, confirmation_hash: preview.confirmation_hash });
      if (current === generation.current) { setResult(data); setConfirmed(false); onImported(); }
    } catch (cause) { if (current === generation.current) failure(cause); }
    finally { if (current === generation.current) setBusy(false); }
  };
  const setPrice = (rowIndex: number, priceIndex: number, value: string) => {
    change(); setRows(current => current.map((row, index) => index !== rowIndex ? row : { ...row, prices: row.prices.map((price, i) => i !== priceIndex ? price : { ...price, price_cents: priceCents(value) }) }));
  };
  // Keep incomplete decimal input editable, rather than replacing it with an empty value on every key press.
  const [priceInputs, setPriceInputs] = useState<Record<string, string>>({});
  const exportReport = () => {
    if (!result) return;
    const csv = 'Row,Action,Service ID\r\n' + result.rows.map(row => `${row.row_number},${row.action},${row.service_id ?? ''}`).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' })); const anchor = document.createElement('a'); anchor.href = url; anchor.download = 'service-import-results.csv'; anchor.click(); URL.revokeObjectURL(url);
  };
  const close = () => { if (!busy) { if (rows.length && !result) setDiscard(true); else onClose(); } };
  return <>
    <Dialog open fullWidth maxWidth="xl" onClose={close} aria-labelledby="service-import-title">
      <DialogTitle id="service-import-title">{t('Import services for {{clinic}}', { clinic: config.name })}</DialogTitle>
      <DialogContent dividers><Stack spacing={2}>
        <Typography>{t('Upload a CSV file or an XLSX workbook with the All Services sheet. Limit: 250 services and 2 MB.')}</Typography>
        <Alert severity="info">{t('New services are inactive and unpublished. Prices are required; zero explicitly means free. Type and Initial Consultation are retained in the import record as descriptive metadata only.')}</Alert>
        {error && <Alert severity="error">{error}</Alert>}
        {!result && <>
          <Button component="label" variant="outlined" disabled={busy}>{t('Choose service workbook')}<input aria-label={t('Service workbook')} type="file" accept=".xlsx,.csv" hidden onChange={event => { setPriceInputs({}); void selectFile(event.target.files?.[0]); event.target.value = ''; }}/></Button>
          {fileName && <Typography>{fileName} · {t('{{count}} service rows', { count: rows.length })}</Typography>}
          <TextField id="service-import-duplicates" select label={t('Existing services')} value={policy} disabled={busy} onChange={event => { setPolicy(event.target.value); change(); }}>
            <MenuItem value="skip">{t('Skip existing services')}</MenuItem><MenuItem value="update_content">{t('Update names, descriptions and category only')}</MenuItem>
          </TextField>
          <Typography variant="body2">{t('Existing prices, durations, public URLs, booking rules, publication and assignments are preserved. Matching uses the service code or English name within this clinic.')}</Typography>
          <FormControlLabel control={<Checkbox checked={createCategories} disabled={busy} onChange={event => { setCreateCategories(event.target.checked); change(); }}/>} label={t('Create missing categories')}/>
          {preview && <Alert severity={dirty ? 'warning' : preview.can_import ? 'info' : 'warning'}>
            {dirty ? t('Changes need a new preview before importing.') : t('Create: {{create}} · Update: {{update}} · Skip: {{skip}} · Invalid: {{invalid}}', preview.counts)}
            {!dirty && preview.new_categories.length > 0 && <Typography>{t('Categories to create: {{categories}}', { categories: preview.new_categories.join(', ') })}</Typography>}
          </Alert>}
          {rows.length > 0 && <TableContainer><Table size="small" sx={{ minWidth: 900 }} aria-label={t('Service import rows')}>
            <TableHead><TableRow>{['Source row', 'Service', 'Category', 'Duration prices (CAD)', 'Import result'].map(label => <TableCell key={label}>{t(label)}</TableCell>)}</TableRow></TableHead>
            <TableBody>{rows.map((row, rowIndex) => {
              const planned = preview?.rows.find(item => item.row_number === row.row_number);
              const preserve = planned && ['skip', 'update'].includes(planned.action);
              return <TableRow key={row.row_number}>
                <TableCell>{row.row_number}</TableCell><TableCell><Typography>{row.name}</Typography><Typography variant="body2">{row.name_fr}</Typography><Box component="details"><Box component="summary">{t('Review service content')}</Box>
                  <Typography variant="body2">{row.public_summary}</Typography><Typography variant="body2">{row.public_summary_fr}</Typography><Typography variant="body2">{row.description}</Typography><Typography variant="body2">{row.description_fr}</Typography>
                  <Typography variant="caption">{t('Type: {{type}} · Initial consultation: {{initial}}', { type: row.service_type, initial: row.initial_consultation === null ? '?' : t(row.initial_consultation ? 'Yes' : 'No') })}</Typography>
                </Box></TableCell><TableCell>{row.category}</TableCell>
                <TableCell>{preserve ? <Typography variant="body2">{t('Existing prices and durations preserved')}</Typography> : <Stack gap={1}>{row.prices.map((price, priceIndex) => {
                  const key = `${row.row_number}-${price.minutes}`;
                  return <TextField key={key} size="small" disabled={busy} label={t('Row {{row}} · {{minutes}} min price (CAD)', { row: row.row_number, minutes: price.minutes })} value={priceInputs[key] ?? (price.price_cents === null ? '' : (price.price_cents / 100).toFixed(2))} onChange={event => { setPriceInputs(current => ({ ...current, [key]: event.target.value })); setPrice(rowIndex, priceIndex, event.target.value); }} slotProps={{ htmlInput: { inputMode: 'decimal' } }}/>;
                })}</Stack>}</TableCell>
                <TableCell>{planned && <><Typography>{t(actions[planned.action] ?? 'Invalid import row.')}</Typography>{planned.service_id && <Typography variant="body2">{t('Existing service #{{id}}: {{name}}', { id: planned.service_id, name: planned.existing_name })}</Typography>}{planned.errors.map(code => <Typography color="error" variant="body2" key={code}>{t(importErrors[code] ?? 'Invalid import row.')}</Typography>)}</>}</TableCell>
              </TableRow>;
            })}</TableBody>
          </Table></TableContainer>}
          <FormControlLabel control={<Checkbox checked={confirmed} disabled={busy || dirty || !preview?.can_import} onChange={event => setConfirmed(event.target.checked)}/>} label={t('I reviewed the rows, prices and category changes for this clinic.')}/>
        </>}
        {result && <><Alert severity="success">{t('Import complete. Created: {{create}} · Updated: {{update}} · Skipped: {{skip}}', result.counts)}</Alert><Typography>{t('Import reference: {{reference}}', { reference: result.preview_id })}</Typography><Button onClick={exportReport}>{t('Download import results')}</Button><Typography>{t('Review new services, practitioner and location assignments before activating or publishing them.')}</Typography></>}
      </Stack></DialogContent>
      <DialogActions><Button disabled={busy} onClick={close}>{t('Close')}</Button>{!result && <><Button disabled={busy || !rows.length} onClick={() => void runPreview()}>{t('Preview import')}</Button><Button variant="contained" disabled={busy || dirty || !confirmed || !preview?.can_import} onClick={() => void apply()}>{t('Import reviewed services')}</Button></>}</DialogActions>
    </Dialog>
    <Dialog open={discard} onClose={() => setDiscard(false)} aria-labelledby="discard-import-title"><DialogTitle id="discard-import-title">{t('Discard import changes?')}</DialogTitle><DialogActions><Button onClick={() => setDiscard(false)}>{t('Stay')}</Button><Button color="error" onClick={onClose}>{t('Discard')}</Button></DialogActions></Dialog>
  </>;
}
