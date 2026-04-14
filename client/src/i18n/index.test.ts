import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { t } from "./index";

describe("t()", () => {
  const mockSsT = vi.fn();

  beforeEach(() => {
    window.ss = {
      // biome-ignore lint/suspicious/noExplicitAny: test stub
      i18n: { _t: mockSsT, currentLocale: "en", addDictionary: vi.fn() } as any,
      // biome-ignore lint/suspicious/noExplicitAny: test stub
      config: {} as any,
    };
    mockSsT.mockReset();
  });

  afterEach(() => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss;
  });

  it("forwards key, fallback, and params to window.ss.i18n._t", () => {
    mockSsT.mockReturnValue("Hallo Erik");
    const result = t("WeDevelopGrid.Test.GREETING", "Hello {name}", { name: "Erik" });
    expect(mockSsT).toHaveBeenCalledWith("WeDevelopGrid.Test.GREETING", "Hello {name}", { name: "Erik" });
    expect(result).toBe("Hallo Erik");
  });

  it("returns fallback verbatim when ss.i18n is unavailable", () => {
    // biome-ignore lint/suspicious/noExplicitAny: test cleanup
    delete (window as any).ss;
    expect(t("WeDevelopGrid.Test.MISSING", "Fallback text")).toBe("Fallback text");
  });
});
