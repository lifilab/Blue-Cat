# Diseño de licenciamiento

## Estrategia

Se recomienda Ed25519. El servicio de licencias mantiene la clave privada; Blue Cat distribuye únicamente la clave pública. El payload versionado incluye identificador, edición, vigencia de actualizaciones y alcance contratado. La firma invalida cualquier modificación no autorizada.

La activación será híbrida: online preferida y desafío-respuesta offline controlado. La aplicación debe tolerar interrupciones breves y no confundir expiración de actualizaciones con expiración de uso.

## Pendiente de implementar

Emisión tras aprobación, rotación de claves, revocación por fraude, fingerprints no invasivos, límites de activación, desactivación, checksum y firma del instalador, grants temporales y auditoría. Ninguna clave privada debe entrar al repositorio, frontend o instalador.
