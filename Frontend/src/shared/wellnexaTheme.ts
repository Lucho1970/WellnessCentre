import type { ThemeOptions } from '@mui/material';

export const wellnexaPalette = {
  turquoise: '#3cc7c4', violet: '#7a4dff', deepTeal: '#0f4c5c', softGold: '#f4d57e',
  mintGlow: '#a8f2d6', cloudWhite: '#f8f9fa', graphite: '#2e2e2e',
} as const;
export const wellnexaPortalPreset = { primary_color: wellnexaPalette.deepTeal, secondary_color: wellnexaPalette.violet, font_family: 'Inter' } as const;

export function isWellnexaPalette(primary?: string, secondary?: string) {
  return primary?.toLowerCase() === wellnexaPalette.deepTeal && secondary?.toLowerCase() === wellnexaPalette.violet;
}

export const wellnexaThemeOptions: ThemeOptions = {
  palette: {
    primary: { main: wellnexaPalette.deepTeal, light: wellnexaPalette.turquoise, contrastText: wellnexaPalette.cloudWhite },
    secondary: { main: wellnexaPalette.violet, contrastText: wellnexaPalette.cloudWhite },
    background: { default: wellnexaPalette.cloudWhite, paper: '#ffffff' },
    text: { primary: wellnexaPalette.graphite, secondary: wellnexaPalette.deepTeal },
    divider: '#cbdcde',
  },
  components: {
    MuiButton: { styleOverrides: { root: { '&:focus-visible': { outline: `3px solid ${wellnexaPalette.violet}`, outlineOffset: 3 } } } },
    MuiPaper: { styleOverrides: { outlined: { borderColor: '#cbdcde' } } },
    MuiChip: { styleOverrides: { colorPrimary: { backgroundColor: wellnexaPalette.mintGlow, color: wellnexaPalette.deepTeal } } },
    MuiTabs: { styleOverrides: { indicator: { backgroundColor: wellnexaPalette.turquoise, height: 3 } } },
    MuiTableHead: { styleOverrides: { root: { backgroundColor: wellnexaPalette.mintGlow } } },
    MuiTooltip: { styleOverrides: { tooltip: { backgroundColor: wellnexaPalette.deepTeal, color: wellnexaPalette.cloudWhite } } },
    MuiAlert: { styleOverrides: { standardInfo: { backgroundColor: wellnexaPalette.mintGlow, color: wellnexaPalette.deepTeal }, standardWarning: { backgroundColor: wellnexaPalette.softGold, color: wellnexaPalette.graphite } } },
  },
};
