/** Patch avbridge dist/player.js: drop 10s CALIB-RESNAP (locks in video lag). */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../../..");
const target = [
  path.resolve(process.cwd(), "node_modules/avbridge/dist/player.js"),
  path.join(root, "tmp/avbridge-player-build/node_modules/avbridge/dist/player.js"),
].find((p) => fs.existsSync(p));
if (!target) {
  console.error("avbridge dist/player.js not found (install avbridge@2.13.0 under tmp/avbridge-player-build)");
  process.exit(1);
}

let src = fs.readFileSync(target, "utf8");
if (src.includes("CALIB-RESNAP") === false || src.includes("MediaWeb: no CALIB-RESNAP")) {
  console.log("Already patched or no CALIB-RESNAP:", target);
  process.exit(0);
}

// Match by unique log tag — formatting may differ slightly across builds.
const re = /\} else if \(wallNow2 - this\.lastCalibrationWall > 1e4\) \{[\s\S]*?CALIB-RESNAP[\s\S]*?\n      \}/;
if (!re.test(src)) {
  console.error("CALIB-RESNAP regex did not match in", target);
  process.exit(1);
}
src = src.replace(re, "} /* MediaWeb: no CALIB-RESNAP */");

const commentOld = `   * We measure the offset on the first painted frame and update it
   * periodically so the PTS comparison stays calibrated.`;
const commentNew = `   * Measured once on first paint (MediaWeb: no periodic resnap — it locked in lag).`;
if (src.includes(commentOld)) src = src.replace(commentOld, commentNew);

fs.writeFileSync(target, src);
console.log("Patched", target);
