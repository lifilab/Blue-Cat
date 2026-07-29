import { mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { randomUUID } from "node:crypto";
import type { ValidatedEvidence } from "../domain/payment-report";

export interface StoredEvidence {
  storageKey: string;
  absolutePath: string;
}

function evidenceRoot(): string {
  const configured = process.env.PAYMENT_EVIDENCE_DIR?.trim();
  if (!configured) return path.join(/*turbopackIgnore: true*/ process.cwd(), "storage", "private", "payment-evidence");
  if (path.isAbsolute(configured)) return path.normalize(configured);
  if (!/^[a-zA-Z0-9._-]+$/.test(configured)) throw new Error("EVIDENCE_DIR_INVALID");
  return path.join(/*turbopackIgnore: true*/ process.cwd(), "storage", "private", configured);
}

function safePath(storageKey: string): string {
  if (!/^[a-f0-9-]{36}\.(pdf|png|jpg)$/.test(storageKey)) throw new Error("INVALID_STORAGE_KEY");
  const root = evidenceRoot();
  const target = path.resolve(/*turbopackIgnore: true*/ root, storageKey);
  if (path.dirname(target) !== root) throw new Error("INVALID_STORAGE_KEY");
  return target;
}

export async function storeEvidence(evidence: ValidatedEvidence): Promise<StoredEvidence> {
  const root = evidenceRoot();
  await mkdir(/*turbopackIgnore: true*/ root, { recursive: true, mode: 0o700 });
  const storageKey = `${randomUUID()}.${evidence.extension}`;
  const absolutePath = safePath(storageKey);
  await writeFile(/*turbopackIgnore: true*/ absolutePath, evidence.bytes, { flag: "wx", mode: 0o600 });
  return { storageKey, absolutePath };
}

export async function deleteEvidence(storageKey: string): Promise<void> {
  await rm(/*turbopackIgnore: true*/ safePath(storageKey), { force: true });
}

export async function readEvidence(storageKey: string): Promise<Buffer> {
  return readFile(/*turbopackIgnore: true*/ safePath(storageKey));
}
