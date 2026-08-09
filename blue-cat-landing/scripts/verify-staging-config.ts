import { validateStagingEnvironment } from "../src/config/runtime-environment";

const result = validateStagingEnvironment();
if (!result.ok) {
  console.error(JSON.stringify({ status: "invalid", errors: result.errors }, null, 2));
  process.exitCode = 1;
} else {
  console.info(JSON.stringify({ status: "ready", environment: "staging" }));
}
