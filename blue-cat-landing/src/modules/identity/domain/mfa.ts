import { generateSecret, generateURI, verify } from "otplib";
import { decryptPrivatePayload, encryptPrivatePayload, hashToken, randomToken } from "./secure-values";

export interface MfaEnrollment {
  secret: string;
  encryptedSecret: string;
  recoveryCodes: string[];
  recoveryHashes: string[];
  uri: string;
}

export function createMfaEnrollment(email: string): MfaEnrollment {
  const secret = generateSecret();
  const recoveryCodes = Array.from({ length: 8 }, () => `${randomToken(6).slice(0, 4)}-${randomToken(6).slice(0, 4)}`.toUpperCase());
  return {
    secret,
    encryptedSecret: encryptPrivatePayload({ secret }),
    recoveryCodes,
    recoveryHashes: recoveryCodes.map(hashToken),
    uri: generateURI({ issuer: "Blue Cat", label: email, secret }),
  };
}

export async function verifyMfaCode(encryptedSecret: string, token: string): Promise<boolean> {
  try {
    const { secret } = decryptPrivatePayload<{ secret: string }>(encryptedSecret);
    const result = await verify({ secret, token });
    return result.valid;
  } catch {
    return false;
  }
}
