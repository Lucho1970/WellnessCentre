export type ImportRow = {
  row_number: number; category: string; name: string; name_fr: string; service_type: string;
  initial_consultation: boolean | null; durations: number[];
  public_summary: string; public_summary_fr: string; description: string; description_fr: string;
  service_code: string; prices: { minutes: number; price_cents: number | null }[];
};

export const importHeaders = ['Category', 'Service Name EN', 'Service Name FR', 'Type', 'Durations', 'Initial Consultation', 'Summary EN', 'Summary FR', 'Detailed Description EN', 'Detailed Description FR'];
const maxFile = 2 * 1024 * 1024, maxExpanded = 10 * 1024 * 1024;
const invalid = () => new Error('Choose a valid service workbook with an All Services sheet.');
const limited = () => new Error('The workbook exceeds the import limits: 2 MB, 250 services and 10 MB expanded.');

export function priceCents(value: string): number | null {
  if (!/^(?:0|[1-9]\d{0,5})(?:\.\d{1,2})?$/.test(value.trim())) return null;
  const [whole, fraction = ''] = value.trim().split('.');
  const amount = Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
  return amount <= 10000000 ? amount : null;
}

/** Read a values-only CSV or the named workbook sheet. Never execute spreadsheet content. */
export async function readServiceWorkbook(file: File): Promise<ImportRow[]> {
  if (!/\.(xlsx|csv)$/i.test(file.name)) throw invalid();
  if (file.size > maxFile) throw limited();
  if (/\.csv$/i.test(file.name)) {
    let text: string;
    try { text = new TextDecoder('utf-8', { fatal: true }).decode(await file.arrayBuffer()); } catch { throw new Error('Choose a valid UTF-8 CSV file.'); }
    return convertRecords(readCsv(text.replace(/^\uFEFF/, '')));
  }
  const { ZipReader, BlobReader } = await import('@zip.js/zip.js');
  const reader = new ZipReader(new BlobReader(file), { useWebWorkers: false });
  try {
    const entries = await reader.getEntries();
    if (entries.length > 1000) throw limited();
    const names = new Set<string>(); let expanded = 0;
    for (const entry of entries) {
      if (names.has(entry.filename) || entry.filename.includes('\\') || entry.filename.split('/').includes('..') || entry.encrypted || /vbaProject\.bin$/i.test(entry.filename)) throw invalid();
      names.add(entry.filename); expanded += entry.uncompressedSize;
      if (!Number.isSafeInteger(entry.uncompressedSize) || entry.uncompressedSize < 0 || expanded > maxExpanded) throw limited();
    }
    let consumed = 0;
    const xml = async (name: string): Promise<Document> => {
      const entry = entries.find(item => item.filename === name);
      if (!entry || entry.directory) throw invalid();
      const chunks: Uint8Array[] = []; let size = 0;
      // Enforce actual output limits too, rather than trusting ZIP size metadata.
      await entry.getData(new WritableStream<Uint8Array>({ write(chunk) {
        size += chunk.byteLength; consumed += chunk.byteLength;
        if (consumed > maxExpanded || size > entry.uncompressedSize) throw limited();
        chunks.push(chunk);
      } }), { checkSignature: true });
      const content = new Uint8Array(size); let position = 0;
      for (const chunk of chunks) { content.set(chunk, position); position += chunk.length; }
      const text = new TextDecoder('utf-8', { fatal: true }).decode(content);
      if (/<!DOCTYPE|<!ENTITY/i.test(text)) throw invalid();
      const document = new DOMParser().parseFromString(text, 'application/xml');
      if (document.getElementsByTagName('parsererror').length) throw invalid();
      return document;
    };
    const workbook = await xml('xl/workbook.xml');
    const sheets = [...workbook.getElementsByTagNameNS('*', 'sheet')].filter(sheet => sheet.getAttribute('name') === 'All Services');
    if (sheets.length !== 1) throw invalid();
    const relationship = sheets[0].getAttribute('r:id');
    const relations = await xml('xl/_rels/workbook.xml.rels');
    const linked = [...relations.getElementsByTagNameNS('*', 'Relationship')].find(item => item.getAttribute('Id') === relationship);
    if (!linked || linked.getAttribute('TargetMode') === 'External') throw invalid();
    const target = new URL(linked.getAttribute('Target') ?? '', 'https://xlsx.invalid/xl/');
    if (target.origin !== 'https://xlsx.invalid' || !target.pathname.startsWith('/xl/worksheets/') || target.search || target.hash) throw invalid();
    const sheet = await xml(target.pathname.slice(1));
    const strings = names.has('xl/sharedStrings.xml') ? [...(await xml('xl/sharedStrings.xml')).getElementsByTagNameNS('*', 'si')].map(item => item.textContent ?? '') : [];
    const records: { number: number; values: string[] }[] = [];
    for (const row of sheet.getElementsByTagNameNS('*', 'row')) {
      const number = Number(row.getAttribute('r')); const values: string[] = [];
      if (!Number.isSafeInteger(number) || number < 1 || number > 10000 || records.some(item => item.number === number)) throw invalid();
      for (const cell of row.getElementsByTagNameNS('*', 'c')) {
        if (cell.getElementsByTagNameNS('*', 'f').length) throw new Error('Formulas are not supported. Import a workbook containing values only.');
        const reference = cell.getAttribute('r')?.match(/^([A-Z]+)(\d+)$/);
        if (!reference || Number(reference[2]) !== number) throw invalid();
        let column = 0; for (const letter of reference[1]) column = column * 26 + letter.charCodeAt(0) - 64;
        if (column > 32 || values[column - 1] !== undefined) throw invalid();
        const raw = cell.getElementsByTagNameNS('*', 'v')[0]?.textContent ?? '';
        const type = cell.getAttribute('t');
        if (type === 'e') throw invalid();
        let value = type === 'inlineStr' ? cell.getElementsByTagNameNS('*', 'is')[0]?.textContent ?? '' : raw;
        if (type === 's') { if (!/^\d+$/.test(raw) || strings[Number(raw)] === undefined) throw invalid(); value = strings[Number(raw)]; }
        values[column - 1] = value.trim();
      }
      if (values.some(value => value !== '')) records.push({ number, values });
      if (records.length > 251) throw limited();
    }
    return convertRecords(records);
  } catch (error) {
    if (error instanceof Error && [invalid().message, limited().message, 'Formulas are not supported. Import a workbook containing values only.', 'The All Services column headings do not match the import format.'].includes(error.message)) throw error;
    throw invalid();
  } finally { await reader.close(); }
}


type SourceRecord = { number: number; values: string[] };

/** RFC 4180 values only: quoted commas/newlines and escaped quotes; never evaluate cells. */
function readCsv(text: string): SourceRecord[] {
  const records: SourceRecord[] = [];
  let values: string[] = [], value = '', state: 'start' | 'plain' | 'quoted' | 'closed' = 'start', number = 1;
  const malformed = () => new Error('Choose a valid UTF-8 CSV file.');
  const field = () => { values.push(value.trim()); value = ''; state = 'start'; if (values.length > 32) throw malformed(); };
  const record = () => {
    field();
    if (values.some(v => v !== '')) records.push({ number, values });
    if (records.length > 251 || number > 10000) throw limited();
    values = []; number++;
  };
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (state === 'quoted') {
      if (char === '"') { if (text[i + 1] === '"') { value += '"'; i++; } else state = 'closed'; }
      else value += char;
    } else if (char === ',') field();
    else if (char === '\r' || char === '\n') { record(); if (char === '\r' && text[i + 1] === '\n') i++; }
    else if (state === 'closed') throw malformed();
    else if (char === '"') { if (state !== 'start') throw malformed(); state = 'quoted'; }
    else { value += char; state = 'plain'; }
  }
  if (state === 'quoted') throw malformed();
  if (value !== '' || values.length || state === 'closed') record();
  return records;
}

function convertRecords(records: SourceRecord[]): ImportRow[] {
  if (records.length < 2 || records[0].number !== 1) throw invalid();
  const headers = records.shift()!.values.map(value => value?.trim());
  if ((headers.includes('Price') && headers.includes('Prices CAD')) || headers.some(header => ![...importHeaders, 'Service Code', 'Prices CAD', 'Price'].includes(header)) || new Set(headers).size !== headers.length || importHeaders.some(header => !headers.includes(header))) throw new Error('The All Services column headings do not match the import format.');
  return records.map(record => {
    if (record.values.some((value, index) => value !== '' && !headers[index])) throw invalid();
    const get = (name: string) => record.values[headers.indexOf(name)] ?? '';
    const durationText = get('Durations');
    const durations = /^\d+(?:\s*,\s*\d+)*$/.test(durationText) ? durationText.split(',').map(Number) : [];
    const initial = get('Initial Consultation').toLowerCase();
    const prices = headers.includes('Price') ? (get('Price') === '' ? [] : get('Price').split(',').map((value, index) => ({ minutes: durations[index], price_cents: priceCents(value) }))) : get('Prices CAD').split(',').filter(Boolean).map(pair => { const parts = pair.trim().split(':'); return { minutes: /^\d+$/.test(parts[0]) ? Number(parts[0]) : NaN, price_cents: parts.length === 2 ? priceCents(parts[1]) : null }; });
    const validPrices = prices.length === durations.length && new Set(prices.map(p => p.minutes)).size === prices.length && prices.every(p => durations.includes(p.minutes));
    return {
      row_number: record.number, category: get('Category'), name: get('Service Name EN'), name_fr: get('Service Name FR'), service_type: get('Type'), initial_consultation: initial === 'yes' ? true : initial === 'no' ? false : null,
      durations, public_summary: get('Summary EN'), public_summary_fr: get('Summary FR'), description: get('Detailed Description EN'), description_fr: get('Detailed Description FR'), service_code: get('Service Code'),
      prices: durations.map(minutes => ({ minutes, price_cents: validPrices ? prices.find(p => p.minutes === minutes)?.price_cents ?? null : null })),
    };
  });
}
