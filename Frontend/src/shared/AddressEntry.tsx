import { useEffect, useRef, useState } from 'react';
import { Alert, Box, Grid, Link, Stack, TextField, Typography } from '@mui/material';
import { importLibrary, setOptions } from '@googlemaps/js-api-loader';
import { useTranslation } from 'react-i18next';

export type AddressValue = {
  address_line1: string;
  address_line2: string;
  city: string;
  province: string;
  postal_code: string;
  country: string;
  instructions?: string;
};

type Props = {
  value: AddressValue;
  onChange: (value: AddressValue) => void;
  disabled?: boolean;
  required?: boolean;
  showInstructions?: boolean;
};

const browserKey = import.meta.env.VITE_GOOGLE_MAPS_BROWSER_API_KEY?.trim() ?? '';
let placesPromise: Promise<google.maps.PlacesLibrary> | null = null;

function loadPlaces() {
  if (!browserKey) return null;
  if (!placesPromise) {
    setOptions({ key: browserKey, v: 'weekly', region: 'CA', authReferrerPolicy: 'origin' });
    placesPromise = importLibrary('places');
  }
  return placesPromise;
}

function component(components: google.maps.places.AddressComponent[], type: string, short = false) {
  const match = components.find(item => item.types.includes(type));
  return (short ? match?.shortText : match?.longText)?.trim() ?? '';
}

export function GoogleMapsAttribution() {
  const { t } = useTranslation();
  return <Typography component="div" sx={{ color: '#5e5e5e', fontFamily: 'Roboto, sans-serif', fontSize: 12, fontStyle: 'normal', fontWeight: 400, lineHeight: 1.5 }}>
    <span>{t('Address suggestions and validation by')}{' '}</span><span translate="no">Google Maps</span>{' · '}
    <Link color="inherit" href="https://policies.google.com/privacy" target="_blank" rel="noreferrer">{t('Google privacy')}</Link>{' · '}
    <Link color="inherit" href="https://cloud.google.com/maps-platform/terms" target="_blank" rel="noreferrer">{t('Google Maps terms')}</Link>
  </Typography>;
}

export function AddressEntry({ value, onChange, disabled = false, required = false, showInstructions = false }: Props) {
  const { t, i18n } = useTranslation();
  const host = useRef<HTMLDivElement | null>(null);
  const autocomplete = useRef<google.maps.places.PlaceAutocompleteElement | null>(null);
  const valueRef = useRef(value);
  const changeRef = useRef(onChange);
  const [googleError, setGoogleError] = useState('');
  valueRef.current = value;
  changeRef.current = onChange;

  useEffect(() => {
    let active = true;
    const container = host.current;
    const library = loadPlaces();
    if (!container || !library) return;
    setGoogleError('');
    void library.then(({ PlaceAutocompleteElement }) => {
      if (!active || !host.current) return;
      const input = new PlaceAutocompleteElement({
        includedRegionCodes: ['ca'],
        requestedLanguage: i18n.resolvedLanguage?.startsWith('fr') ? 'fr' : 'en',
        requestedRegion: 'ca',
      });
      input.placeholder = t('Start typing a Canadian address');
      input.description = t('Choose an address suggestion to fill the address fields. You can also enter the fields manually.');
      input.maxlength = 190;
      input.disabled = disabled;
      const selected = async (event: google.maps.places.PlacePredictionSelectEvent) => {
        try {
          setGoogleError('');
          const place = event.placePrediction.toPlace();
          await place.fetchFields({ fields: ['addressComponents', 'formattedAddress'] });
          if (!active) return;
          const parts = place.addressComponents ?? [];
          const street = [component(parts, 'street_number'), component(parts, 'route')].filter(Boolean).join(' ');
          const city = component(parts, 'locality') || component(parts, 'postal_town') || component(parts, 'sublocality_level_1') || component(parts, 'administrative_area_level_2');
          const next: AddressValue = {
            ...valueRef.current,
            address_line1: street || place.formattedAddress?.split(',')[0]?.trim() || '',
            address_line2: component(parts, 'subpremise'),
            city,
            province: component(parts, 'administrative_area_level_1') || component(parts, 'administrative_area_level_1', true),
            postal_code: [component(parts, 'postal_code'), component(parts, 'postal_code_suffix')].filter(Boolean).join('-'),
            country: component(parts, 'country') || 'Canada',
          };
          changeRef.current(next);
        } catch {
          if (active) setGoogleError(t('Google could not fill this address. Enter it manually or try another suggestion.'));
        }
      };
      const failed = () => setGoogleError(t('Address suggestions are temporarily unavailable. Enter the address manually.'));
      input.addEventListener('gmp-select', selected);
      input.addEventListener('gmp-error', failed);
      host.current.replaceChildren(input);
      autocomplete.current = input;
    }).catch(() => { if (active) setGoogleError(t('Address suggestions are temporarily unavailable. Enter the address manually.')); });
    return () => {
      active = false;
      autocomplete.current = null;
      container.replaceChildren();
    };
  }, [i18n.resolvedLanguage, t]);

  useEffect(() => { if (autocomplete.current) autocomplete.current.disabled = disabled; }, [disabled]);

  const update = (field: keyof AddressValue, text: string) => onChange({ ...value, [field]: text });
  return <Stack spacing={2}>
    {browserKey ? <>
      <Box><Typography component="label" display="block" mb={0.75} fontWeight={600}>{t('Find address with Google Maps')}</Typography><Box ref={host} sx={{ minHeight: 52, '& gmp-place-autocomplete': { width: '100%', colorScheme: 'light' } }} /></Box>
      <GoogleMapsAttribution />
      {googleError && <Alert severity="warning">{googleError}</Alert>}
    </> : <Alert severity="info">{t('Address suggestions are not configured. Enter the address manually.')}</Alert>}
    <Grid container spacing={2}>
      <Grid size={{ xs: 12, md: 8 }}><TextField fullWidth required={required} disabled={disabled} label={t('Street address')} value={value.address_line1} inputProps={{ maxLength: 190 }} onChange={event => update('address_line1', event.target.value)} /></Grid>
      <Grid size={{ xs: 12, md: 4 }}><TextField fullWidth disabled={disabled} label={t('Unit (optional)')} value={value.address_line2} inputProps={{ maxLength: 190 }} onChange={event => update('address_line2', event.target.value)} /></Grid>
      <Grid size={{ xs: 12, md: 4 }}><TextField fullWidth required={required} disabled={disabled} label={t('City')} value={value.city} inputProps={{ maxLength: 100 }} onChange={event => update('city', event.target.value)} /></Grid>
      <Grid size={{ xs: 12, md: 4 }}><TextField fullWidth required={required} disabled={disabled} label={t('Province / region')} value={value.province} inputProps={{ maxLength: 80 }} onChange={event => update('province', event.target.value)} /></Grid>
      <Grid size={{ xs: 12, md: 4 }}><TextField fullWidth required={required} disabled={disabled} label={t('Postal code')} value={value.postal_code} inputProps={{ maxLength: 20 }} onChange={event => update('postal_code', event.target.value)} /></Grid>
      <Grid size={12}><TextField fullWidth required={required} disabled={disabled} label={t('Country')} value={value.country} inputProps={{ maxLength: 80 }} onChange={event => update('country', event.target.value)} /></Grid>
      {showInstructions && <Grid size={12}><TextField fullWidth disabled={disabled} multiline minRows={2} label={t('Access instructions (optional)')} value={value.instructions ?? ''} inputProps={{ maxLength: 500 }} onChange={event => update('instructions', event.target.value)} /></Grid>}
    </Grid>
  </Stack>;
}
