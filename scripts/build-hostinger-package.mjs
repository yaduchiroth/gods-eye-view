import { rm, mkdir, cp, stat } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(scriptDir, '..');
const distDir = path.join(repoRoot, 'dist');
const templateDir = path.join(repoRoot, 'deploy', 'hostinger');
const outDir = path.join(repoRoot, 'dist-hostinger');
const outAppDir = path.join(outDir, 'app');

function runBuild() {
  const result = spawnSync('npm', ['run', 'build'], {
    cwd: repoRoot,
    stdio: 'inherit',
    shell: process.platform === 'win32',
  });
  if (result.status !== 0) {
    process.exit(result.status ?? 1);
  }
}

async function ensurePrereqs() {
  await stat(templateDir);
}

async function writePackage() {
  await rm(outDir, { recursive: true, force: true });
  await mkdir(outAppDir, { recursive: true });
  await cp(distDir, outAppDir, { recursive: true });
  await cp(templateDir, outDir, { recursive: true });
}

async function main() {
  await ensurePrereqs();
  runBuild();
  await writePackage();
  console.log('\nHostinger package created at: dist-hostinger/');
  console.log('Upload everything inside dist-hostinger/ to your Hostinger public_html folder.');
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
