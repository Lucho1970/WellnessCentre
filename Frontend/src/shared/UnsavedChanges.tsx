import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type PropsWithChildren } from 'react';
import { Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useBlocker } from 'react-router-dom';

type DirtyRegistration = (id: symbol, dirty: boolean) => void;
const UnsavedChangesContext = createContext<DirtyRegistration | null>(null);

export function UnsavedChangesProvider({ children }: PropsWithChildren) {
  const { t } = useTranslation();
  const [dirtyForms, setDirtyForms] = useState<Set<symbol>>(() => new Set());
  const hasUnsavedChanges = dirtyForms.size > 0;
  const blocker = useBlocker(hasUnsavedChanges);

  const register = useCallback<DirtyRegistration>((id, dirty) => {
    setDirtyForms((current) => {
      if (current.has(id) === dirty) return current;
      const next = new Set(current);
      if (dirty) next.add(id); else next.delete(id);
      return next;
    });
  }, []);

  useEffect(() => {
    const warnBeforeUnload = (event: BeforeUnloadEvent) => {
      if (!hasUnsavedChanges) return;
      event.preventDefault();
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', warnBeforeUnload);
    return () => window.removeEventListener('beforeunload', warnBeforeUnload);
  }, [hasUnsavedChanges]);

  const contextValue = useMemo(() => register, [register]);
  return <UnsavedChangesContext.Provider value={contextValue}>
    {children}
    <Dialog open={blocker.state === 'blocked'} onClose={() => blocker.state === 'blocked' && blocker.reset()} aria-labelledby="unsaved-changes-title">
      <DialogTitle id="unsaved-changes-title">{t('Leave this page?')}</DialogTitle>
      <DialogContent><DialogContentText>{t('You have unsaved changes. If you leave now, the information you entered will be lost.')}</DialogContentText></DialogContent>
      <DialogActions>
        <Button onClick={() => blocker.state === 'blocked' && blocker.reset()} autoFocus>{t('Stay')}</Button>
        <Button color="error" variant="contained" onClick={() => blocker.state === 'blocked' && blocker.proceed()}>{t('Leave without saving')}</Button>
      </DialogActions>
    </Dialog>
  </UnsavedChangesContext.Provider>;
}

export function useUnsavedChanges(dirty: boolean) {
  const register = useContext(UnsavedChangesContext);
  const id = useRef(Symbol('unsaved-form'));

  useEffect(() => {
    if (!register) throw new Error('useUnsavedChanges must be used inside UnsavedChangesProvider.');
    register(id.current, dirty);
  }, [dirty, register]);

  useEffect(() => () => { register?.(id.current, false); }, [register]);
}

export function useUnsavedForm() {
  const [dirty, setDirty] = useState(false);
  useUnsavedChanges(dirty);
  return {
    dirty,
    markDirty: useCallback(() => setDirty(true), []),
    markClean: useCallback(() => setDirty(false), []),
  };
}
