---
description: Create or update documentation following writing guide standards. Use when new features are added or existing behavior changes significantly.
argument-hint: <filename> <instructions...>
allowed-tools: Read, Write, Grep, Glob, Bash, Task
---

Create or update documentation in `docs/` following the writing guide.

**Usage:**
```bash
/write-docs 02_1_MEDIA_folder_management.md Analyze the folder management system and document it
/write-docs 03_1_API_rest_endpoints.md Document the REST API endpoints and their operations
/write-docs 01_2_ARCH_service_layer.md Document the service layer architecture
```

**Process:**

1. **Read Writing Guide**
   - Read `docs/99_1_APPENDIX_doc_writing_guide.md`
   - Apply all rules (concept-focused, verifiable, structure, tone)

2. **Analyze Codebase**
   - **CRITICAL**: Read actual code - never assume or guess
   - Use Grep/Glob to find relevant components
   - Read files, understand flows, extract concrete facts

3. **Write/Update Documentation**
   - Check if file exists (Read → Edit) or new (Write)
   - Follow writing guide structure and rules
   - Base all claims on code you actually read

4. **Update Documentation Index**
   - If creating, renaming, or deleting documentation files, update `docs/00_DOCS_INDEX.md`
   - Place entries in appropriate sections with descriptions
   - Keep numerical order within sections

**Critical Rule:**

**Always read code before documenting.** If you didn't verify it in the codebase, don't write it.
