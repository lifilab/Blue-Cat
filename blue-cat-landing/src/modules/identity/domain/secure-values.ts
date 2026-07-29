import { createCipheriv, createDecipheriv, createHash, createHmac, randomBytes } from "node:crypto";

const forbiddenSecrets = new Set([
  "replace-with-at-least-32-random-characters",
  "replace-with-32-byte-base64-key",
  "change-me",
]);

export function normalizeEmail(email: string): string {
  return email.trim().normalize("NFKC").toLowerCase();
}

export function randomToken(bytes = 32): string {
  return randomBytes(bytes).toString("base64url");
}

export function hashToken(value: string): string {
  return createHmac("sha256", requiredSecret("IDENTITY_HASH_PEPPER", 32))
    .update(value, "utf8")
    .digest("hex");
}

export function privacyHash(value: string): string {
  const pepper = requiredSecret("IDENTITY_HASH_PEPPER", 32);
  return createHash("sha256").update(`${pepper}|${value}`, "utf8").digest("hex");
}

export function encryptPrivatePayload(payload: unknown): string {
  const key = identityDataKey();
  const iv = randomBytes(12);
  const cipher = createCipheriv("aes-256-gcm", key, iv);
  const plaintext = Buffer.from(JSON.stringify(payload), "utf8");
  const ciphertext = Buffer.concat([cipher.update(plaintext), cipher.final()]);
  const tag = cipher.getAuthTag();
  return `v1.${iv.toString("base64url")}.${tag.toString("base64url")}.${ciphertext.toString("base64url")}`;
}

export function decryptPrivatePayload<T>(envelope: string): T {
  const [version, ivText, tagText, ciphertextText] = envelope.split(".");
  if (version !== "v1" || !ivText || !tagText || !ciphertextText) throw new Error("INVALID_ENCRYPTED_PAYLOAD");
  const decipher = createDecipheriv("aes-256-gcm", identityDataKey(), Buffer.from(ivText, "base64url"));
  decipher.setAuthTag(Buffer.from(tagText, "base64url"));
  const plaintext = Buffer.concat([
    decipher.update(Buffer.from(ciphertextText, "base64url")),
    decipher.final(),
  ]);
  return JSON.parse(plaintext.toString("utf8")) as T;
}

export function requiredSecret(name: string, minimumLength = 32): string {
  const value = process.env[name]?.trim() ?? "";
  if (value.length < minimumLength || forbiddenSecrets.has(value.toLowerCase())) {
    throw new Error(`INVALID_SECRET_${name}`);
  }
  return value;
}

function identityDataKey(): Buffer {
  const encoded = requiredSecret("IDENTITY_DATA_KEY", 32);
  const key = Buffer.from(encoded, "base64");
  if (key.length !== 32) throw new Error("INVALID_SECRET_IDENTITY_DATA_KEY");
  return key;
}
