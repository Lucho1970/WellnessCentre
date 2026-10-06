import { Stack, TextField, MenuItem } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { getCountries, getCountryCallingCode, parsePhoneNumberFromString, type CountryCode } from 'libphonenumber-js/max';
import type { Question } from './FormTasks';

export type PhoneAnswer = { number: string; country: CountryCode };
export type FormAnswer = string | boolean | PhoneAnswer;
export function formToday() {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Toronto', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
  return ['year', 'month', 'day'].map(type => parts.find(part => part.type === type)?.value).join('-');
}
export function typedAnswer(question: Question, value: FormAnswer | undefined): string | boolean | undefined {
  if (value === undefined || value === '' || (typeof value === 'object' && value.number.trim() === '')) {
    if (question.required) throw new Error('Check the form questions and required answers.');
    return undefined;
  }
  if (question.type === 'phone') {
    const raw = typeof value === 'object' ? value.number : String(value);
    if (raw.length > 40 || !/^[+\d\s().-]+$/.test(raw)) throw new Error('Enter a valid phone number for the selected country.');
    const phone = parsePhoneNumberFromString(raw, { defaultCountry: typeof value === 'object' ? value.country : 'CA', extract: false });
    if (!phone?.isValid() || phone.ext) throw new Error('Enter a valid phone number for the selected country.');
    return phone.number;
  }
  if (question.type === 'date') {
    const raw = String(value), date = new Date(`${raw}T00:00:00Z`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(raw) || raw.startsWith('0000') || !Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== raw || (question.no_future && raw > formToday())) throw new Error('Enter a valid date within the allowed range.');
    return raw;
  }
  if (question.type === 'email') {
    const raw = String(value).trim();
    if (raw.length > 254 || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) throw new Error('Enter a valid email address.');
    return raw;
  }
  return value as string | boolean;
}

export function TypedFormField({ question, label, value, disabled, onChange }: { question: Question; label: string; value: FormAnswer | undefined; disabled: boolean; onChange: (value: FormAnswer) => void }) {
  const { t, i18n } = useTranslation();
  if (question.type === 'phone') {
    const number = typeof value === 'object' ? value.number : typeof value === 'string' ? value : '';
    const country = typeof value === 'object' ? value.country : parsePhoneNumberFromString(number)?.country ?? 'CA';
    const names = new Intl.DisplayNames([i18n.language], { type: 'region' });
    let invalid = false;
    if (!disabled && number) { try { typedAnswer(question, { number, country }); } catch { invalid = true; } }
    return <Stack spacing={1}>
      {!disabled && <TextField select fullWidth label={t('Country for {{question}}', { question: label })} value={country} onChange={event => onChange({ number, country: event.target.value as CountryCode })}>{getCountries().map(code => <MenuItem key={code} value={code}>{names.of(code)} (+{getCountryCallingCode(code)})</MenuItem>)}</TextField>}
      <TextField fullWidth type="tel" label={label} required={question.required} disabled={disabled} value={number} error={invalid} helperText={t(invalid ? 'Enter a valid phone number for the selected country.' : 'Enter a national number or include + and the country calling code. No extensions.')} inputProps={{ maxLength: 40 }} onChange={event => onChange({ number: event.target.value, country })}/>
    </Stack>;
  }
  return <TextField fullWidth type={question.type === 'date' ? 'date' : 'email'} label={label} required={question.required} disabled={disabled} value={typeof value === 'string' ? value : ''} InputLabelProps={{ shrink: true }} inputProps={question.type === 'date' ? { min: '0001-01-01', max: question.no_future ? formToday() : '9999-12-31' } : { maxLength: 254 }} onChange={event => onChange(event.target.value)}/>;
}
