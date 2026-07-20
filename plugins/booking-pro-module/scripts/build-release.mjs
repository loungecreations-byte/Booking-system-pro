import fs from "fs";
import path from "path";
import { fileURLToPath } from "url";
import { spawnSync } from "child_process";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const root = path.resolve(__dirname, "..");

function ensureDir(dir) {
  fs.mkdirSync(dir, { recursive: true });
}

function copyFile(src, dest) {
  if (!fs.existsSync(src)) {
    throw new Error(`Missing build artifact: ${src}`);
  }
  ensureDir(path.dirname(dest));
  fs.copyFileSync(src, dest);
}

function copyFilesByName(srcDir, destDir, predicate) {
  if (!fs.existsSync(srcDir)) {
    throw new Error(`Missing build directory: ${srcDir}`);
  }

  ensureDir(destDir);
  for (const entry of fs.readdirSync(srcDir, { withFileTypes: true })) {
    if (!entry.isFile()) {
      continue;
    }
    if (predicate && !predicate(entry.name)) {
      continue;
    }
    fs.copyFileSync(path.join(srcDir, entry.name), path.join(destDir, entry.name));
  }
}

function copyLatestFileByPattern(srcDir, destFile, pattern) {
  if (!fs.existsSync(srcDir)) {
    throw new Error(`Missing build directory: ${srcDir}`);
  }

  const matches = fs.readdirSync(srcDir, { withFileTypes: true })
    .filter((entry) => entry.isFile() && pattern.test(entry.name))
    .map((entry) => {
      const filePath = path.join(srcDir, entry.name);
      return {
        filePath,
        mtimeMs: fs.statSync(filePath).mtimeMs,
      };
    })
    .sort((a, b) => b.mtimeMs - a.mtimeMs);

  if (matches.length === 0) {
    throw new Error(`Missing build artifact matching ${pattern} in ${srcDir}`);
  }

  copyFile(matches[0].filePath, destFile);
}

function patchDayPlannerPolicy(filePath) {
  if (!fs.existsSync(filePath)) {
    return false;
  }

  const before = fs.readFileSync(filePath, "utf8");
  let after = before;

  after = after.replace(
    /const l=xn\(e,t,a\),[\s\S]*?b=h&&s&&l\.route_intent!==qt;/,
    'const l = xn(e, t, a);\n  const criticalPlannerConflictCount = Array.isArray(e == null ? void 0 : e.days)\n    ? e.days.reduce((count, day) => {\n        const conflicts = Array.isArray(day == null ? void 0 : day.conflicts) ? day.conflicts : [];\n        return count + conflicts.filter((conflict) => (conflict == null ? void 0 : conflict.tone) === "critical").length;\n      }, 0)\n    : 0;\n  const u = typeof (r == null ? void 0 : r.date) === "string" && r.date.trim() !== "";\n  const p = Array.isArray(t) && t.length > 0;\n  const k = p && t.some((K) => Boolean(K == null ? void 0 : K.startTime));\n  const h = u && i > 0 && p && k;\n  const y = typeof (n == null ? void 0 : n.reasonCode) === "string" && n.reasonCode.trim() !== "" ? n.reasonCode.trim() : l.reason_code;\n  const m = !!(n != null && n.message) || y === "availability_lookup_failed";\n  let S = "blocked";\n  if (h && !m && criticalPlannerConflictCount === 0) {\n    if (l.route_intent === zt) {\n      S = "direct";\n    } else if (l.route_intent === It) {\n      S = "request";\n    }\n  }\n  const g = h\n    ? y ||\n      (S === "request"\n        ? l.reason_code || "request_only_item_present"\n        : S === "blocked"\n        ? l.reason_code || "booking_blocked"\n        : null)\n    : "incomplete_plan";\n  const E = pr(g, l.route_intent, n == null ? void 0 : n.message);\n  const d = S === "direct" && h && s && !m && criticalPlannerConflictCount === 0;\n  const b = h && s && l.route_intent !== qt && criticalPlannerConflictCount === 0;'
  );
  after = after.replace(
    /conflicts\.filter\(\(conflict\)=>conflict\?\.tone==="critical"\|\|conflict\?\.type==="overlap"\|\|conflict\?\.type==="break"\)\.length/g,
    'conflicts.filter((conflict)=>conflict?.tone==="critical").length'
  );
  after = after.replace(/tone:"critical",type:"break"/g, 'tone:"warning",type:"break"');
  after = after.replace(/title:"Te weinig pauzeruimte"/g, 'title:"Korte aansluiting"');
  after = after.replace(/parseInt\(v,10\)\|\|1/g, 'parseInt(v,10)||10');
  after = after.replace(
    /function Ot\(e,\{allowFormFallback:t=!0\}=\{\}\)\{var a,n;const r=se\(\(a=e==null\?void 0:e\.plan\)==null\?void 0:a\.participants\);if\(r!==null\)return r;if\(t\)\{const i=se\(\(n=e==null\?void 0:e\.form\)==null\?void 0:n\.participants\);if\(i!==null\)return i\}return At\}/,
    'function Ot(e,{allowFormFallback:t=!0}={}){var a,n;const r=se((n=e==null?void 0:e.form)==null?void 0:n.participants);if(r!==null)return r;if(t){const i=se((a=e==null?void 0:e.plan)==null?void 0:a.participants);if(i!==null)return i}return At}'
  );

  if (after !== before) {
    fs.writeFileSync(filePath, after);
    return true;
  }

  return false;
}

function runViteBuild() {
  const viteBin = path.join(root, "node_modules", "vite", "bin", "vite.js");
  const result = spawnSync(process.execPath, [
    viteBin,
    "build",
    "--config",
    "vite.config.js",
    "--configLoader",
    "native",
  ], {
    cwd: root,
    stdio: "inherit",
    env: process.env,
  });

  return result;
}

function syncBuiltArtifacts() {
  const buildJsDir = path.join(root, "build", "js");
  const buildAssetsDir = path.join(root, "build", "assets");

  const dayPlannerDistDir = path.join(root, "assets", "js", "day-planner", "dist");
  const dayPlannerAssetDir = path.join(root, "assets", "js", "day-planner", "assets");
  const overviewDistDir = path.join(root, "modules", "product-overview", "assets", "js", "dist");
  const overviewAssetDir = path.join(root, "modules", "product-overview", "assets", "js", "assets");

  patchDayPlannerPolicy(path.join(buildJsDir, "dayPlanner.js"));

  copyFilesByName(buildJsDir, dayPlannerDistDir);
  copyFilesByName(buildAssetsDir, dayPlannerAssetDir, (name) => name.endsWith(".js"));

  copyFilesByName(buildJsDir, overviewDistDir, (name) =>
    name === "activityOverview.js" ||
    name === "activityOverview.css" ||
    name === "client-MGy1wL6B.js"
  );
  copyLatestFileByPattern(
    buildAssetsDir,
    path.join(overviewDistDir, "activityOverview.css"),
    /^activityOverview-[A-Za-z0-9_-]+\.css$/
  );
  copyFilesByName(buildAssetsDir, overviewAssetDir, (name) => name.endsWith(".js"));

  // Keep the shared day-planner fallback entry in sync with the build output.
  copyFile(
    path.join(buildJsDir, "dayPlanner.js"),
    path.join(root, "assets", "js", "day-planner", "dist", "dayPlanner.js")
  );
}

const buildResult = runViteBuild();

if (buildResult.status !== 0) {
  console.error("Vite build failed; release artifacts were not synced.");
  process.exit(buildResult.status || 1);
}

syncBuiltArtifacts();
