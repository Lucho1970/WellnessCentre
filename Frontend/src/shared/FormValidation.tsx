import { cloneElement, createContext, forwardRef, useCallback, useContext, useEffect, useId, useLayoutEffect, useMemo, useRef, useState, type ComponentType, type PropsWithChildren, type ReactElement } from 'react';
import { Alert as MuiAlert, TextField as MuiTextField, Box, FormControl, FormHelperText, FormControlLabel as MuiFormControlLabel, type AlertProps, type TextFieldProps, type FormControlLabelProps } from '@mui/material';
import i18n from '../i18n';
import { validationFields } from './api';

type FieldMap = Record<string, string>;
type Validation = {
  fields: FieldMap;
  unplaced: [string, string][];
  capture: (cause: unknown) => void;
  clear: () => void;
  clearField: (name: string) => void;
  register: (name: string, element: HTMLElement) => () => void;
};
const noop = () => {};
const Context = createContext<Validation>({ fields: {}, unplaced: [], capture: noop, clear: noop, clearField: noop, register: () => noop });
const translate = (message: string) => i18n.exists(message) ? i18n.t(message) : message;
const visible = (node: HTMLElement) => node.isConnected && node.getClientRects().length > 0;
function reveal(node: HTMLElement) {
  node.scrollIntoView({ block: 'center', behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
  const input = node.querySelector<HTMLElement>('input:not([type="hidden"]):not(:disabled),textarea:not(:disabled),[role="combobox"],button:not(:disabled)');
  if (!input && !node.hasAttribute('tabindex')) node.tabIndex = -1;
  (input ?? node).focus({ preventScroll: true });
}

/** A separate scope for each editor, including its portal-rendered dialogs. */
export function FormValidation({ children }: PropsWithChildren) {
  const [failure, setFailure] = useState<{ fields: FieldMap; revision: number }>({ fields: {}, revision: 0 });
  const nodes = useRef(new Map<string, Set<HTMLElement>>());
  const fallback = useRef<HTMLDivElement>(null);
  const [registryVersion, registered] = useState(0);
  const register = useCallback((name: string, element: HTMLElement) => {
    const group = nodes.current.get(name) ?? new Set<HTMLElement>();
    group.add(element); nodes.current.set(name, group); registered(n => n + 1);
    return () => {
      group.delete(element);
      if (!group.size) {
        nodes.current.delete(name);
        // Closing a dialog or hiding a control must not leave orphaned errors.
        // StrictMode re-registers mounted controls immediately after cleanup.
        queueMicrotask(() => {
          if (nodes.current.has(name)) return;
          setFailure(previous => {
            if (!name) return Object.keys(previous.fields).length ? { ...previous, fields: {} } : previous;
            if (!(name in previous.fields)) return previous;
            const fields = { ...previous.fields }; delete fields[name]; return { ...previous, fields };
          });
        });
      }
      registered(n => n + 1);
    };
  }, []);
  const capture = useCallback((cause: unknown) => {
    if (cause instanceof Error && cause.name === 'AbortError') return;
    const fields = cause && typeof cause === 'object' && 'fields' in cause ? validationFields(cause.fields) : {};
    setFailure(previous => ({ fields, revision: previous.revision + 1 }));
  }, []);
  const clear = useCallback(() => setFailure(previous => Object.keys(previous.fields).length ? { ...previous, fields: {} } : previous), []);
  const clearField = useCallback((name: string) => setFailure(previous => {
    if (!(name in previous.fields)) return previous;
    const fields = { ...previous.fields }; delete fields[name]; return { ...previous, fields };
  }), []);
  const unplaced = useMemo(() => Object.entries(failure.fields).filter(([name]) => ![...(nodes.current.get(name) ?? [])].some(visible)), [failure.fields, registryVersion]);
  const value = useMemo(() => ({ fields: failure.fields, unplaced, capture, clear, clearField, register }), [failure.fields, unplaced, capture, clear, clearField, register]);
  const summary = [...(nodes.current.get('') ?? [])].filter(visible);
  useEffect(() => {
    if (!failure.revision) return;
    const frame = requestAnimationFrame(() => {
      const targets = [...new Set(Object.keys(failure.fields).flatMap(name => [...(nodes.current.get(name) ?? [])]))].filter(visible);
      targets.sort((a, b) => a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_PRECEDING ? 1 : -1);
      if (targets[0]) reveal(targets[0]);
      else if ([...(nodes.current.get('') ?? [])].some(visible)) reveal([...(nodes.current.get('') ?? [])].find(visible)!);
      else if (fallback.current && visible(fallback.current)) reveal(fallback.current);
    });
    return () => cancelAnimationFrame(frame);
  }, [failure.revision]);
  return <Context.Provider value={value}>{children}{unplaced.length > 0 && !summary.length && <MuiAlert ref={fallback} severity="error" tabIndex={-1} sx={{ mt: 2 }}>
    {unplaced.map(([name, message]) => <Box key={name}>{name}: {translate(message)}</Box>)}
  </MuiAlert>}</Context.Provider>;
}
export const useFormValidation = () => useContext(Context);
export function withFormValidation<P extends object>(Component: ComponentType<P>) {
  return function ValidatedForm(props: P) { return <FormValidation><Component {...props}/></FormValidation>; };
}

/** Bind a composite control such as an address, checkbox group or radio group. */
export function ValidationField({ name, children }: PropsWithChildren<{ name: string }>) {
  const validation = useFormValidation(), root = useRef<HTMLDivElement>(null), id = useId();
  useLayoutEffect(() => root.current ? validation.register(name, root.current) : undefined, [name, validation.register]);
  const message = validation.fields[name];
  return <Box ref={root} role="group" aria-invalid={Boolean(message)} aria-describedby={message ? id : undefined} onChangeCapture={() => validation.clearField(name)}>
    {children}{message && <FormHelperText id={id} error>{translate(message)}</FormHelperText>}
  </Box>;
}

/** Named controls bind API fields; MUI supplies accessible helper text links. */
export const TextField = forwardRef<HTMLDivElement, TextFieldProps>(function ValidatedTextField(props, ref) {
  const validation = useFormValidation();
  const root = useRef<HTMLDivElement>(null);
  const name = props.name ?? '';
  useLayoutEffect(() => name && root.current ? validation.register(name, root.current) : undefined, [name, validation.register]);
  const message = validation.fields[name];
  return <MuiTextField {...props} ref={node => { root.current = node; if (typeof ref === 'function') ref(node); else if (ref) ref.current = node; }}
    error={Boolean(message) || props.error} helperText={message ? translate(message) : props.helperText}
    onChange={event => { validation.clearField(name); props.onChange?.(event); }} />;
});

export function FormControlLabel(props: FormControlLabelProps & { name?: string }) {
  const validation = useFormValidation(), root = useRef<HTMLDivElement>(null), id = useId();
  const name = props.name ?? (props.control.props as { name?: string }).name ?? '';
  useLayoutEffect(() => name && root.current ? validation.register(name, root.current) : undefined, [name, validation.register]);
  const message = validation.fields[name];
  if (!name) return <MuiFormControlLabel {...props}/>;
  const control = props.control as ReactElement<{ onChange?: (...args: any[]) => void; inputProps?: Record<string, unknown> }>;
  return <FormControl ref={root} error={Boolean(message)} aria-describedby={message ? id : undefined}>
    <MuiFormControlLabel {...props} control={cloneElement(control, {
      inputProps: { ...control.props.inputProps, ...(message ? { 'aria-invalid': true, 'aria-describedby': id } : {}) },
      onChange: (...args: any[]) => { validation.clearField(name); control.props.onChange?.(...args); },
    })} onChange={(event, checked) => { validation.clearField(name); props.onChange?.(event, checked); }}/>
    {message && <FormHelperText id={id}>{translate(message)}</FormHelperText>}
  </FormControl>;
}

/** Reveal non-field failures too, without moving focus for success/info alerts. */
export function Alert(props: AlertProps) {
  const root = useRef<HTMLDivElement>(null), { fields, unplaced, register } = useFormValidation();
  const text = typeof props.children === 'string' ? props.children : undefined;
  useLayoutEffect(() => props.severity === 'error' && root.current ? register('', root.current) : undefined, [props.severity, register]);
  useEffect(() => {
    if (props.severity !== 'error' || Object.keys(fields).length) return;
    const frame = requestAnimationFrame(() => { if (root.current && visible(root.current)) reveal(root.current); });
    return () => cancelAnimationFrame(frame);
  }, [props.severity, text]);
  return <MuiAlert {...props} ref={root} tabIndex={props.severity === 'error' ? -1 : props.tabIndex}>
    {props.children}{props.severity === 'error' && unplaced.map(([name, message]) => <Box key={name}>{name}: {translate(message)}</Box>)}
  </MuiAlert>;
}
