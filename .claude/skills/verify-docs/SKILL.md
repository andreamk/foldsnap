---
description: Verify documentation accuracy against current codebase. Use after modifying documentation files or when documentation may be outdated.
argument-hint: [PREFIX|filename]
allowed-tools: Bash, Task, Read, Glob
---

Verify that documentation in `docs/` accurately reflects the current codebase state.

**Usage:**
```bash
/verify-docs                      # Verify all documentation
/verify-docs ARCH                 # Verify all ARCH_* files
/verify-docs MEDIA                # Verify all MEDIA_* files
/verify-docs API                  # Verify all API_* files
/verify-docs 02_1_MEDIA_folder_management.md  # Verify specific file
```

**Available Prefixes:**
- `ARCH` - Architecture and core systems
- `MEDIA` - Media library integration and folder management
- `API` - REST API endpoints and data operations
- `ADMIN` - Admin pages and UI components
- `SECURITY` - Security practices and patterns
- `APPENDIX` - Testing and documentation guides

**Process:**

1. **Identify Target Files**
   - Prefix provided → Find all matching `*_{PREFIX}_*.md` files
   - Filename provided → Verify single file
   - No argument → All `.md` files in docs/

2. **Invoke quality-docs Agent**
   - Checks content accuracy (concepts match code)
   - Checks writing quality (follows writing guide)

3. **Present Unified Report**
   - Content accuracy status (verified concepts, discrepancies)
   - Writing style status (rule violations, good practices)
   - Prioritized recommendations

**What Gets Verified:**
- **Content Accuracy**: Architectural patterns, data flows, component interactions, behavioral claims
- **Writing Quality**: Concept-focused style, verifiable claims, proper structure, clear tone

**Example Output:**
```
ARCH files verified - all concepts accurate
MEDIA files have minor drift - folder tree refactored but docs still reference old component
API files have critical issues - endpoint documented does not match implementation
```
