// lingua/src/index.ts
// SUPER COMMENTS — IMPLEMENTATION ROADMAP
import { ipcMain } from 'electron';
const NS = 'lingua' as const;
export function activate() {
  ipcMain.handle(`${NS}:ping`, () => ({ ok: true, purpose: "Offline phonetics annotator: IPA + human‑friendly respelling." }));
}
export function deactivate() {
  ipcMain.removeHandler(`${NS}:ping`);
}