import { useState } from 'react';
import { Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';

type SignInChoice = { label: string; description: string; href: string };

export function GuestSignInChooser({ clientHref, staffHref, practitionerHref }: {
  clientHref: string;
  staffHref: string;
  practitionerHref?: string;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const choices: SignInChoice[] = [
    { label: t('Client'), description: t('Book and manage your appointments'), href: clientHref },
    ...(practitionerHref ? [{ label: t('Practitioner'), description: t('Use your personal practitioner account'), href: practitionerHref }] : []),
    { label: t('Clinic staff'), description: t('Use your organization Microsoft account'), href: staffHref },
  ];

  return <>
    <Button variant="contained" size="small" onClick={() => setOpen(true)}>{t('Log in')}</Button>
    <Dialog open={open} onClose={() => setOpen(false)} aria-labelledby="guest-sign-in-title" fullWidth maxWidth="xs">
      <DialogTitle id="guest-sign-in-title">{t('Who is signing in?')}</DialogTitle>
      <DialogContent>
        <Stack gap={1.5} sx={{ pt: 1 }}>
          {choices.map((choice, index) => <Button key={choice.href} autoFocus={index === 0} href={choice.href} variant="outlined" fullWidth sx={{ justifyContent: 'flex-start', textAlign: 'left', py: 1.5 }}>
            <Box>
              <Typography component="span" display="block" fontWeight={700}>{choice.label}</Typography>
              <Typography component="span" display="block" variant="body2" sx={{ textTransform: 'none' }}>{choice.description}</Typography>
            </Box>
          </Button>)}
        </Stack>
      </DialogContent>
      <DialogActions><Button onClick={() => setOpen(false)}>{t('Cancel')}</Button></DialogActions>
    </Dialog>
  </>;
}
