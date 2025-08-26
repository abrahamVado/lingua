import { contextBridge, ipcRenderer } from 'electron';
const NS = 'lingua' as const;
const api = { ping: () => ipcRenderer.invoke(`${NS}:ping`) };
contextBridge.exposeInMainWorld(NS, api);