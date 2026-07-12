# Protección requerida para master

Aplicar en GitHub: Settings → Branches → Add branch protection rule.

- Branch name pattern: `master`.
- Require a pull request before merging.
- Require status checks before merging.
- Status check obligatorio: `baseline`.
- Require conversation resolution before merging.
- Block force pushes.
- Block branch deletion.
- Include administrators cuando exista al menos un segundo mantenedor.

La automatización no aplica esta política por sí sola; requiere permisos administrativos del repositorio mediante GitHub CLI/API.
