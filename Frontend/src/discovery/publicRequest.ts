import { apiRequest } from '../shared/api';
import type { DiscoveryRequest } from './LocationSearch';

export const publicDiscoveryRequest:DiscoveryRequest=(path,body)=>apiRequest(path,{method:body===undefined?'GET':'POST',headers:{'Content-Type':'application/json'},credentials:'omit',cache:'no-store',signal:AbortSignal.timeout(45000),...(body===undefined?{}:{body:JSON.stringify(body)})});
