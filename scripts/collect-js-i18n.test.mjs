// @vitest-environment node
import { mkdtempSync, mkdirSync, writeFileSync, readFileSync, rmSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { collect, emit } from "./collect-js-i18n.mjs";

let tmpDir;
let srcDir;
let langSrcEnPath;
let langSrcNlPath;
let langRuntimeEnPath;
let langRuntimeNlPath;

beforeEach(() => {
  tmpDir = mkdtempSync(join(tmpdir(), "i18n-collector-"));
  srcDir = join(tmpDir, "src");
  mkdirSync(srcDir);
  langSrcEnPath = join(tmpDir, "en.json");
  langSrcNlPath = join(tmpDir, "nl.json");
  langRuntimeEnPath = join(tmpDir, "en.js");
  langRuntimeNlPath = join(tmpDir, "nl.js");
  writeFileSync(langSrcEnPath, "{}\n");
  writeFileSync(langSrcNlPath, "{}\n");
});

afterEach(() => {
  rmSync(tmpDir, { recursive: true, force: true });
});

function writeSource(name, content) {
  writeFileSync(join(srcDir, name), content);
}

function run() {
  return collect({ srcRoot: srcDir, langSrcEnPath });
}

describe("collect()", () => {
  it("finds a single t() call with a static key and fallback", () => {
    writeSource(
      "Component.tsx",
      `import { t } from "@/i18n";\nexport const x = t("WeDevelopGrid.Foo.BAR", "Bar text");`,
    );
    const { errors, found } = run();
    expect(errors).toEqual([]);
    expect(found.size).toBe(1);
    expect(found.get("WeDevelopGrid.Foo.BAR").fallback).toBe("Bar text");
  });

  it("rejects keys not prefixed with WeDevelopGrid.", () => {
    writeSource(
      "Component.tsx",
      `import { t } from "@/i18n";\nexport const x = t("Wrong.Key", "fallback");`,
    );
    const { errors } = run();
    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/must start with "WeDevelopGrid\."/);
  });

  it("rejects dynamic key (template literal)", () => {
    writeSource(
      "Component.tsx",
      "import { t } from \"@/i18n\";\nconst v = 'x';\nexport const x = t(`WeDevelopGrid.Foo.${v}`, 'fallback');",
    );
    const { errors } = run();
    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/static string literal as first argument/);
  });

  it("rejects dynamic fallback (identifier)", () => {
    writeSource(
      "Component.tsx",
      `import { t } from "@/i18n";\nconst fb = "x";\nexport const x = t("WeDevelopGrid.Foo.BAR", fb);`,
    );
    const { errors } = run();
    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/static string literal as second argument/);
  });

  it("rejects duplicate key with conflicting fallback", () => {
    writeSource(
      "A.tsx",
      `import { t } from "@/i18n";\nexport const a = t("WeDevelopGrid.Foo.BAR", "first");`,
    );
    writeSource(
      "B.tsx",
      `import { t } from "@/i18n";\nexport const b = t("WeDevelopGrid.Foo.BAR", "second");`,
    );
    const { errors } = run();
    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/conflicting fallback/);
  });

  it("allows duplicate key with identical fallback", () => {
    writeSource(
      "A.tsx",
      `import { t } from "@/i18n";\nexport const a = t("WeDevelopGrid.Foo.BAR", "same");`,
    );
    writeSource(
      "B.tsx",
      `import { t } from "@/i18n";\nexport const b = t("WeDevelopGrid.Foo.BAR", "same");`,
    );
    const { errors, found } = run();
    expect(errors).toEqual([]);
    expect(found.size).toBe(1);
  });

  it("flags stale keys present in en.json but no longer in source", () => {
    writeFileSync(langSrcEnPath, JSON.stringify({ "WeDevelopGrid.Old.KEY": "stale" }));
    const { errors } = run();
    expect(errors).toHaveLength(1);
    expect(errors[0]).toMatch(/no longer exists in source/);
  });

  it("ignores t() calls when t is not imported from an i18n module", () => {
    writeSource(
      "Component.tsx",
      `function t(k: string, f: string) { return f; }\nexport const x = t("Whatever", "value");`,
    );
    const { errors, found } = run();
    expect(errors).toEqual([]);
    expect(found.size).toBe(0);
  });

  it("ignores files in the testing/ directory", () => {
    const testingDir = join(srcDir, "testing");
    mkdirSync(testingDir);
    writeFileSync(
      join(testingDir, "helpers.ts"),
      `import { t } from "@/i18n";\nexport const x = t("WeDevelopGrid.Test.X", "x");`,
    );
    const { errors, found } = run();
    expect(errors).toEqual([]);
    expect(found.size).toBe(0);
  });

  it("ignores .test.tsx files", () => {
    writeSource(
      "Component.test.tsx",
      `import { t } from "@/i18n";\nexport const x = t("WeDevelopGrid.Test.X", "x");`,
    );
    const { errors, found } = run();
    expect(errors).toEqual([]);
    expect(found.size).toBe(0);
  });
});

describe("emit()", () => {
  it("writes en.json sorted alphabetically with trailing newline", () => {
    writeFileSync(langSrcEnPath, "{}");
    writeFileSync(langSrcNlPath, "{}");
    const found = new Map([
      ["WeDevelopGrid.Z.KEY", { fallback: "z", location: "x:1" }],
      ["WeDevelopGrid.A.KEY", { fallback: "a", location: "x:2" }],
    ]);
    emit(found, { langSrcEnPath, langSrcNlPath, langRuntimeEnPath, langRuntimeNlPath });
    const written = readFileSync(langSrcEnPath, "utf8");
    expect(written.endsWith("\n")).toBe(true);
    const parsed = JSON.parse(written);
    expect(Object.keys(parsed)).toEqual(["WeDevelopGrid.A.KEY", "WeDevelopGrid.Z.KEY"]);
  });

  it("preserves existing nl.json values verbatim", () => {
    writeFileSync(langSrcEnPath, "{}");
    writeFileSync(langSrcNlPath, JSON.stringify({ "WeDevelopGrid.A.KEY": "Nederlands" }));
    const found = new Map([["WeDevelopGrid.A.KEY", { fallback: "english", location: "x:1" }]]);
    emit(found, { langSrcEnPath, langSrcNlPath, langRuntimeEnPath, langRuntimeNlPath });
    const nl = JSON.parse(readFileSync(langSrcNlPath, "utf8"));
    expect(nl["WeDevelopGrid.A.KEY"]).toBe("Nederlands");
  });

  it("emits runtime bundles that call window.ss.i18n.addDictionary", () => {
    writeFileSync(langSrcEnPath, "{}");
    writeFileSync(langSrcNlPath, "{}");
    const found = new Map([["WeDevelopGrid.A.KEY", { fallback: "a", location: "x:1" }]]);
    emit(found, { langSrcEnPath, langSrcNlPath, langRuntimeEnPath, langRuntimeNlPath });
    const enBundle = readFileSync(langRuntimeEnPath, "utf8");
    expect(enBundle).toContain('window.ss.i18n.addDictionary("en"');
    expect(enBundle).toContain('"WeDevelopGrid.A.KEY":"a"');
  });
});
