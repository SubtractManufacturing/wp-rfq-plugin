import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import AdmZip from "adm-zip";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const pluginSourcePath = path.join(root, "rfq-intake");
const pluginEntryPath = path.join(pluginSourcePath, "rfq-intake.php");
const distPath = path.join(root, "dist");
const stagedPluginPath = path.join(distPath, ".staging", "rfq-intake");
const npmCli = process.env.npm_execpath;

assert.ok(npmCli, "Run this command through npm so npm_execpath is available");

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {
    cwd: root,
    env: process.env,
    stdio: "inherit",
    shell: process.platform === "win32",
    ...options,
  });

  if (result.error) {
    throw result.error;
  }

  assert.equal(
    result.status,
    0,
    `${command} ${args.join(" ")} exited with status ${result.status}`,
  );
}

function readPluginVersion() {
  const pluginSource = fs.readFileSync(pluginEntryPath, "utf8");
  const match = pluginSource.match(/^\s*\*\s*Version:\s*(\S+)\s*$/m);

  assert.ok(match, "rfq-intake.php must contain a Version header");
  assert.match(
    match[1],
    /^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/,
    "plugin Version header must be semver",
  );

  return match[1];
}

function copyPluginSource() {
  fs.cpSync(pluginSourcePath, stagedPluginPath, {
    recursive: true,
    filter(source) {
      return path.basename(source) !== ".gitkeep";
    },
  });
}

function assertPackageInputs() {
  for (const requiredFile of [
    path.join(stagedPluginPath, "rfq-intake.php"),
    path.join(stagedPluginPath, "build", "rfq-form.js"),
    path.join(stagedPluginPath, "build", "rfq-form.css"),
    path.join(stagedPluginPath, "vendor", "autoload.php"),
  ]) {
    assert.ok(
      fs.statSync(requiredFile).isFile(),
      `required package file is missing: ${path.relative(root, requiredFile)}`,
    );
  }
}

const version = readPluginVersion();

fs.rmSync(distPath, { recursive: true, force: true });
fs.mkdirSync(stagedPluginPath, { recursive: true });

run(
  process.execPath,
  [
    npmCli,
    "--prefix",
    "frontend",
    "ci",
    "--include=optional",
    `--os=${process.platform}`,
    `--cpu=${process.arch}`,
  ],
  {
    shell: false,
  },
);
try {
  run(process.execPath, [npmCli, "--prefix", "frontend", "run", "build"], {
    shell: false,
  });
} finally {
  fs.mkdirSync(path.join(pluginSourcePath, "build"), { recursive: true });
  fs.writeFileSync(path.join(pluginSourcePath, "build", ".gitkeep"), "");
}

copyPluginSource();

run(
  "composer",
  [
    "install",
    "--no-dev",
    "--no-interaction",
    "--no-progress",
    "--prefer-dist",
    "--optimize-autoloader",
  ],
  {
    env: {
      ...process.env,
      COMPOSER_VENDOR_DIR: path.join(stagedPluginPath, "vendor"),
    },
  },
);

assertPackageInputs();

const zipPath = path.join(distPath, `rfq-intake-${version}.zip`);
const zip = new AdmZip();

zip.addLocalFolder(stagedPluginPath, "rfq-intake");
zip.writeZip(zipPath);

fs.rmSync(path.join(distPath, ".staging"), { recursive: true, force: true });

console.log(`Plugin package created: ${path.relative(root, zipPath)}`);
