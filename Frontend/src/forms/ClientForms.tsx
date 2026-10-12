import { FormTasks } from './FormTasks';
import { useFormRequest } from './api';
export function ClientForms({ clientId, onLockedChange }: { clientId: number; onLockedChange?: (locked: boolean) => void }) { const request = useFormRequest(); return <FormTasks key={clientId} request={request} clientId={clientId} onLockedChange={onLockedChange}/>; }
