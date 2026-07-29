import { createHash } from "node:crypto";
import type { ValidatedEvidence } from "./payment-report";

export const MAX_EVIDENCE_BYTES = 5 * 1024 * 1024;

const signatures = [
  { mimeType: "application/pdf" as const, extension: "pdf" as const, matches: (bytes: Buffer) => bytes.subarray(0, 5).toString("ascii") === "%PDF-" },
  { mimeType: "image/png" as const, extension: "png" as const, matches: (bytes: Buffer) => bytes.length >= 8 && bytes.subarray(0, 8).equals(Buffer.from([0x89,0x50,0x4e,0x47,0x0d,0x0a,0x1a,0x0a])) },
  { mimeType: "image/jpeg" as const, extension: "jpg" as const, matches: (bytes: Buffer) => bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff },
] as const;

export function validateEvidence(bytes: Buffer, originalName: string, declaredMime: string): ValidatedEvidence {
  if (bytes.length === 0) throw new Error("EVIDENCE_EMPTY");
  if (bytes.length > MAX_EVIDENCE_BYTES) throw new Error("EVIDENCE_TOO_LARGE");
  const signature = signatures.find((candidate) => candidate.matches(bytes));
  if (!signature) throw new Error("EVIDENCE_TYPE_NOT_ALLOWED");
  const normalizedDeclared = declaredMime.toLowerCase() === "image/jpg" ? "image/jpeg" : declaredMime.toLowerCase();
  if (normalizedDeclared && normalizedDeclared !== "application/octet-stream" && normalizedDeclared !== signature.mimeType) throw new Error("EVIDENCE_MIME_MISMATCH");
  if (signature.extension === "pdf") {
    const pdfText = bytes.toString("latin1");
    const tail = bytes.subarray(Math.max(0, bytes.length - 2048)).toString("latin1");
    if (!tail.includes("%%EOF") || /\/(JavaScript|JS|EmbeddedFile|Launch|OpenAction)\b/i.test(pdfText)) throw new Error("EVIDENCE_TYPE_NOT_ALLOWED");
  }
  if (signature.extension === "jpg" && !(bytes[bytes.length - 2] === 0xff && bytes[bytes.length - 1] === 0xd9)) throw new Error("EVIDENCE_TYPE_NOT_ALLOWED");
  if (signature.extension === "png" && !bytes.subarray(Math.max(0, bytes.length - 32)).includes(Buffer.from("IEND"))) throw new Error("EVIDENCE_TYPE_NOT_ALLOWED");
  void originalName;
  const safeOriginalName = `comprobante.${signature.extension}`;
  return {
    bytes,
    originalName: safeOriginalName,
    mimeType: signature.mimeType,
    extension: signature.extension,
    sizeBytes: bytes.length,
    sha256: createHash("sha256").update(bytes).digest("hex"),
  };
}
