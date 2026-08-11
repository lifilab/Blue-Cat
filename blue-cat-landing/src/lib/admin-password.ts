import { randomBytes, scrypt as scryptCallback, timingSafeEqual } from "node:crypto";
import { promisify } from "node:util";

const scrypt = promisify(scryptCallback);

export async function hashAdminPassword(password: string): Promise<string> {
  if (password.length < 12 || password.length > 200) throw new Error("ADMIN_PASSWORD_LENGTH");
  const salt = randomBytes(16);
  const derived = await scrypt(password, salt, 64) as Buffer;
  return `scrypt$${salt.toString("base64url")}$${derived.toString("base64url")}`;
}

export async function verifyAdminPassword(password: string, encoded: string): Promise<boolean> {
  if (password.length > 200) return false;
  const [algorithm, saltEncoded, hashEncoded, extra] = encoded.split("$");
  if (algorithm !== "scrypt" || !saltEncoded || !hashEncoded || extra) return false;
  try {
    const salt = Buffer.from(saltEncoded, "base64url");
    const expected = Buffer.from(hashEncoded, "base64url");
    if (salt.length !== 16 || expected.length !== 64) return false;
    const actual = await scrypt(password, salt, expected.length) as Buffer;
    return timingSafeEqual(expected, actual);
  } catch {
    return false;
  }
}
