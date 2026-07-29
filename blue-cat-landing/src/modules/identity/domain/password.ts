import { hash, verify } from "@node-rs/argon2";

const passwordOptions = {
  algorithm: 2,
  memoryCost: 19_456,
  timeCost: 3,
  parallelism: 1,
  outputLen: 32,
} as const;

export async function hashPassword(password: string): Promise<string> {
  return hash(password, passwordOptions);
}

export async function verifyPassword(passwordHash: string, password: string): Promise<boolean> {
  try {
    return await verify(passwordHash, password);
  } catch {
    return false;
  }
}

export function isArgon2idHash(passwordHash: string): boolean {
  return passwordHash.startsWith("$argon2id$");
}
