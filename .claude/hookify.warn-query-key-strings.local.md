---
name: warn-query-key-strings
enabled: true
event: file
action: warn
conditions:
  - field: content
    operator: regex_match
    pattern: (invalidateQueries|refetchQueries|setQueryData|getQueryData|removeQueries|cancelQueries)\(\s*(\{\s*queryKey:\s*)?\[\s*['"]
  - field: file_path
    operator: regex_match
    pattern: client/src/.*\.(ts|tsx)$
---

**Use Query Key Factory — No String Literals in Cache Operations**

Do NOT use inline string literals for TanStack Query cache keys. A typo in a string key silently breaks cache invalidation, causing stale UI with no error.

**Use the `queryKeys` factory instead:**

```typescript
import { queryKeys, treeQueryKey } from '@/hooks/queryKeys';

// Correct — the editor's tree, whether page- or shared-block-rooted
queryClient.invalidateQueries({ queryKey: treeQueryKey(root) });
queryClient.invalidateQueries({ queryKey: queryKeys.sharedBlocks.all() });

// Wrong — typo risk, no type safety
queryClient.invalidateQueries({ queryKey: ['elementTree', pageId, zone] });
```

See `client/src/js/hooks/queryKeys.ts` for all available key factories. The editor's own tree goes through `treeQueryKey(root)`, never `queryKeys.elementTree` directly — the library editor renders `sharedBlocks.tree`. `queryKeys.elementTree.byPage()` directly is only for ANOTHER page's tree (the duplicate-to target in `useElementMutations.ts`).
