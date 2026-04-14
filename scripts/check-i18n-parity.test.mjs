// @vitest-environment node
import { mkdtempSync, writeFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { check, loadJson, loadYaml } from "./check-i18n-parity.mjs";

let tmpDir;

beforeEach(() => {
  tmpDir = mkdtempSync(join(tmpdir(), "i18n-parity-"));
});

afterEach(() => {
  rmSync(tmpDir, { recursive: true, force: true });
});

function writeFile(name, content) {
  const path = join(tmpDir, name);
  writeFileSync(path, content);
  return path;
}

describe("loadYaml()", () => {
  it("strips the top-level locale key and flattens nested keys", () => {
    const path = writeFile(
      "en.yml",
      "en:\n  Foo:\n    BAR: 'bar value'\n    BAZ: 'baz value'\n",
    );
    expect(loadYaml(path)).toEqual({
      "Foo.BAR": "bar value",
      "Foo.BAZ": "baz value",
    });
  });

  it("returns empty object for empty YAML", () => {
    const path = writeFile("en.yml", "en:\n");
    expect(loadYaml(path)).toEqual({});
  });
});

describe("loadJson()", () => {
  it("parses a flat key→string map", () => {
    const path = writeFile("en.json", JSON.stringify({ "WeDevelopGrid.A": "value" }));
    expect(loadJson(path)).toEqual({ "WeDevelopGrid.A": "value" });
  });

  it("returns empty object for empty file", () => {
    const path = writeFile("en.json", "");
    expect(loadJson(path)).toEqual({});
  });
});

describe("check()", () => {
  it("returns no errors when EN and NL are identical and non-empty", () => {
    const errors = check("test", { A: "english" }, { A: "nederlands" });
    expect(errors).toEqual([]);
  });

  it("reports keys missing in NL", () => {
    const errors = check("test", { A: "english", B: "english" }, { A: "nederlands" });
    expect(errors.some((e) => /missing 1 key/.test(e))).toBe(true);
    expect(errors.some((e) => /  - B/.test(e))).toBe(true);
  });

  it("reports orphan keys in NL not present in EN", () => {
    const errors = check("test", { A: "english" }, { A: "nederlands", B: "orphan" });
    expect(errors.some((e) => /orphan 1 key|1 orphan key/.test(e))).toBe(true);
    expect(errors.some((e) => /  - B/.test(e))).toBe(true);
  });

  it("reports empty NL values", () => {
    const errors = check("test", { A: "english" }, { A: "" });
    expect(errors.some((e) => /empty value/.test(e))).toBe(true);
    expect(errors.some((e) => /  - A/.test(e))).toBe(true);
  });

  it("reports whitespace-only NL values as empty", () => {
    const errors = check("test", { A: "english" }, { A: "   " });
    expect(errors.some((e) => /empty value/.test(e))).toBe(true);
  });

  it("reports all three failure modes in a single run", () => {
    const errors = check(
      "test",
      { A: "english", B: "english" },
      { A: "", C: "orphan" },
    );
    expect(errors.some((e) => /missing 1 key/.test(e))).toBe(true);
    expect(errors.some((e) => /1 orphan key/.test(e))).toBe(true);
    expect(errors.some((e) => /empty value/.test(e))).toBe(true);
  });
});
