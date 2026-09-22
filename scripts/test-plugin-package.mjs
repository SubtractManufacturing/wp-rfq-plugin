import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import AdmZip from "adm-zip";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const npmCli = process.env.npm_execpath;

assert.ok(npmCli, "npm_execpath is required to run the package command");

const packageResult = spawnSync(
  process.execPath,
  [npmCli, "run", "package:plugin"],
  {
    cwd: root,
    encoding: "utf8",
    stdio: "inherit",
  },
);

assert.equal(packageResult.status, 0, "plugin package command must succeed");

const pluginSource = fs.readFileSync(
  path.join(root, "rfq-intake", "rfq-intake.php"),
  "utf8",
);
const versionMatch = pluginSource.match(/^\s*\*\s*Version:\s*(\S+)\s*$/m);

assert.ok(versionMatch, "plugin header must contain a Version field");

const version = versionMatch[1];
const expectedZipName = `rfq-intake-${version}.zip`;
const distPath = path.join(root, "dist");
const zipFiles = fs
  .readdirSync(distPath)
  .filter((file) => file.endsWith(".zip"));

assert.deepEqual(
  zipFiles,
  [expectedZipName],
  "dist must contain one versioned zip",
);

const zip = new AdmZip(path.join(distPath, expectedZipName));
const entries = zip.getEntries();
const entryNames = entries.map((entry) => entry.entryName);

assert.ok(entryNames.length > 0, "plugin package must not be empty");
assert.ok(
  entryNames.every(
    (entryName) =>
      entryName === "rfq-intake/" || entryName.startsWith("rfq-intake/"),
  ),
  "the zip must contain one rfq-intake top-level directory",
);

for (const requiredFile of [
  "rfq-intake/rfq-intake.php",
  "rfq-intake/build/rfq-form.js",
  "rfq-intake/build/rfq-form.css",
  "rfq-intake/includes/class-rfq-update-checker.php",
  "rfq-intake/includes/class-rfq-upgrade-manager.php",
  "rfq-intake/vendor/autoload.php",
  "rfq-intake/vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php",
]) {
  assert.ok(entryNames.includes(requiredFile), `missing ${requiredFile}`);
}

for (const forbiddenPath of [
  "rfq-intake/CHANGELOG.md",
  "rfq-intake/Planning/",
  "rfq-intake/frontend/",
  "rfq-intake/tests/",
  "rfq-intake/vendor/phpstan/",
  "rfq-intake/vendor/phpunit/",
  "rfq-intake/vendor/squizlabs/",
]) {
  assert.ok(
    !entryNames.some(
      (entryName) =>
        entryName === forbiddenPath || entryName.startsWith(forbiddenPath),
    ),
    `package must omit ${forbiddenPath}`,
  );
}

const packagedPlugin = zip
  .readAsText("rfq-intake/rfq-intake.php")
  .match(/^\s*\*\s*Version:\s*(\S+)\s*$/m);

assert.equal(
  packagedPlugin?.[1],
  version,
  "zip name and packaged plugin header versions must match",
);

assert.match(
  zip.readAsText("rfq-intake/rfq-intake.php"),
  /^\s*\*\s*Update URI:\s*https:\/\/github\.com\/SubtractManufacturing\/wp-rfq-plugin\s*$/m,
  "packaged plugin must declare its external Update URI",
);

const packagedUpdater = zip.readAsText(
  "rfq-intake/includes/class-rfq-update-checker.php",
);
assert.match(
  packagedUpdater,
  /SubtractManufacturing\/wp-rfq-plugin/,
  "updater must target the public release repository",
);
assert.match(
  packagedUpdater,
  /REQUIRE_RELEASE_ASSETS/,
  "updater must require a matching release asset",
);
assert.doesNotMatch(
  packagedUpdater,
  /setAuthentication\s*\(/,
  "public update checks must not configure a GitHub token",
);

const releaseWorkflow = fs.readFileSync(
  path.join(root, ".github", "workflows", "release.yml"),
  "utf8",
);
assert.doesNotMatch(
  releaseWorkflow,
  /gh release upload[^\n]*--clobber/,
  "published Plugin Package assets must not be overwritten",
);
assert.match(
  releaseWorkflow,
  /gh release edit "\$TAG_NAME" --draft=false/,
  "release workflow must publish only after attaching the package",
);

const releasePleaseConfig = JSON.parse(
  fs.readFileSync(path.join(root, "release-please-config.json"), "utf8"),
);
assert.equal(
  releasePleaseConfig.draft,
  true,
  "Release Please must create a mutable draft before package upload",
);

console.log(`Plugin package verified: dist/${expectedZipName}`);
