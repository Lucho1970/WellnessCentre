import { TextReader, Uint8ArrayWriter, ZipWriter } from '@zip.js/zip.js';

export const serviceHeaders = ['Category', 'Service Name EN', 'Service Name FR', 'Type', 'Durations', 'Initial Consultation', 'Summary EN', 'Summary FR', 'Detailed Description EN', 'Detailed Description FR'];
export const serviceValues = ['Synthetic category', 'Imported massage', 'Massage importé', 'Treatment', '30,60', 'No', 'English summary', 'Résumé français', 'English description', 'Description française'];

export function serviceCsv(headers = [...serviceHeaders, 'Price'], values = [...serviceValues, '70,110']): Buffer {
  const quote = (value: string) => `"${value.replaceAll('"', '""')}"`;
  return Buffer.from('\uFEFF' + [headers, values].map(row => row.map(quote).join(',')).join('\r\n') + '\r\n');
}

/** Synthetic values-only XLSX: no patient data or dependency on a private workbook. */
export async function serviceWorkbook(options: { headers?: string[]; values?: string[]; formula?: boolean; sheetName?: string; external?: boolean; extra?: string; rowCount?: number; oversized?: boolean } = {}): Promise<Buffer> {
  const escape = (value: string) => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('"', '&quot;');
  const row = (values: string[], number: number) => `<row r="${number}">${values.map((value, i) => `<c r="${String.fromCharCode(65 + i)}${number}" t="inlineStr">${options.formula && number === 2 && i === 1 ? '<f>1+1</f>' : ''}<is><t>${escape(value)}</t></is></c>`).join('')}</row>`;
  const writer = new ZipWriter(new Uint8ArrayWriter(), { useWebWorkers: false, level: 0 });
  const files: Record<string, string> = {
    'xl/workbook.xml': `<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="${escape(options.sheetName ?? 'All Services')}" sheetId="1" r:id="rId1"/></sheets></workbook>`,
    'xl/_rels/workbook.xml.rels': `<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="${options.external ? 'https://invalid.example/sheet.xml' : 'worksheets/sheet1.xml'}"${options.external ? ' TargetMode="External"' : ''}/></Relationships>`,
    'xl/worksheets/sheet1.xml': `<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${row(options.headers ?? serviceHeaders, 1)}${Array.from({ length: options.rowCount ?? 1 }, (_, i) => row(options.values ?? serviceValues, i + 2)).join('')}</sheetData></worksheet>`,
  };
  if (options.extra) files[options.extra] = 'Untrusted extra entry';
  for (const [name, text] of Object.entries(files)) await writer.add(name, new TextReader(text));
  const bytes = Buffer.from(await writer.close());
  // Simulate a ZIP that advertises excessive expansion without allocating the expanded bytes.
  if (options.oversized) bytes.writeUInt32LE(11 * 1024 * 1024, bytes.indexOf(Buffer.from([0x50, 0x4b, 0x01, 0x02])) + 24);
  return bytes;
}
