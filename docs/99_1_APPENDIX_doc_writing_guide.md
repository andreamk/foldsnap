# Documentation Writing Guide

This guide defines standards for writing and maintaining technical documentation in `docs/`.

## Purpose

Documentation should:
- **Explain concepts and architecture**, not list code details
- **Be verifiable** against the codebase
- **Stay accurate** as code evolves
- **Help developers understand** how the system works

## File Naming Convention

```
{sequence}_{category}_{topic}.md

Examples:
01_1_ARCH_overview.md
01_4_ARCH_folder-management.md      # Main document
01_4_1_ARCH_folder-operations.md    # Subsection (optional)
02_3_PLUGIN_admin-pages.md
99_1_APPENDIX_doc_writing_guide.md
```

**Categories:**
- `ARCH` - Architecture and core concepts
- `PLUGIN` - Plugin structure and WordPress integration
- `UI` - User interface (admin pages, templates)
- `MEDIA` - Media library integration and folder system
- `SECURITY` - Security patterns and best practices
- `DEV` - Development practices and utilities
- `OPS` - Operations (testing, building, deployment)
- `APPENDIX` - Meta-documentation (guides, tools, practices)

**Sequence numbers:**
- Major category: `01_*`, `02_*`, etc.
- Files within category: `01_1`, `01_2`, `01_3`, etc.
- Appendix: `99_*` range

**Subsections (optional third level):**

When a topic becomes too complex or the document grows too large, consider splitting it into subsections:

```
01_4_ARCH_folder-management.md      ← Main document (overview)
01_4_1_ARCH_folder-operations.md    ← Detailed subsection
01_4_2_ARCH_folder-storage.md       ← Detailed subsection
```

**When to use subsections:**
- Main document exceeds ~300 lines or covers multiple distinct workflows
- Topic has clear, independent sub-topics
- Readers benefit from focused, detailed documents vs one large reference

**Structure:**
- **Main document** (`01_4`): High-level overview, common concepts, links to subsections
- **Subsections** (`01_4_1`, `01_4_2`): Deep dive into specific workflows, edge cases, detailed examples

**Key principle:** Subsections maintain the same category and relate to the parent topic. Use sparingly - most topics fit in a single document.

## Writing Style

### Tone

- **Clear and direct** - Avoid ambiguity, verbosity, and repetition
- **Concise** - Respect reader's time
- **Technical but accessible** - Assume PHP/WordPress knowledge, explain domain concepts
- **Factual** - State what is, not opinions about quality

### Formatting

**Emphasis:**
- **Bold** for important terms, component names
- `Code formatting` for class names, method names, file paths, namespaces
- _Italics_ sparingly, for subtle emphasis

**Lists:**
- Use bullet points for unordered information
- Use numbered lists for sequential steps
- Keep list items parallel in structure

**Code blocks:**
- Always specify language: ` ```php `, ` ```bash `
- Include context (what the code does)
- Keep examples short and focused

**Tables:**
Use for structured data (configurations, comparisons):

```markdown
| Class | Namespace | Purpose |
|-------|-----------|---------|
| `Bootstrap` | `FoldSnap\Core` | Plugin initialization |
```

## Writing Verifiable Documentation

### Good: Concept-Focused Claims

Write about **what** the system does and **how** it works conceptually:

```markdown
- "Folders are stored as custom taxonomy terms"
- "Each media item can belong to multiple folders"
- "The plugin hooks into WordPress media library to add folder interface"
```

**Why:** These claims describe architecture, behavior, and patterns that remain true even if class names or file paths change.

### Bad: Implementation-Specific Details

Avoid brittle references that break easily:

```markdown
- "The Folder class is located at src/Folder/Manager.php"
- "Line 245 of MediaHandler.php handles folder assignment"
- "The method is called assignFolder(int $attachmentId, array $folderIds)"
```

**Why:** File paths, line numbers, and exact signatures change frequently and make documentation fragile.

### When to Include Implementation Details

**Include specific references when:**
- Referring to a **core architectural component**
- Documenting a **public API or pattern** others must use
- Showing a **code example** that illustrates the concept

**Key principle:** Reference implementation details when they help understanding, but focus on the concept and purpose.

### Verifiable vs Non-Verifiable Claims

Documentation should be written so that its accuracy can be **automatically verified** against the codebase.

**Good verifiable claims:**
- State relationships between components
- Describe data flow sequences
- List system components and their purposes
- Explain behavioral rules and constraints
- Reference core architectural patterns

**Non-verifiable (avoid):**
```markdown
The folder system is fast and efficient. It uses best practices
and follows industry standards. The code is well-organized.
```

Why this fails: "Fast", "efficient", "best practices", "well-organized" are subjective opinions with no concrete claims to verify.

## Common Mistakes

### Mistake #1: Too Much Code

**RED FLAG: Code block exceeds 10 lines?** You're documenting implementation, not concepts.

**Rule**: 3-5 lines max. Show the pattern, not the implementation.

## What to Document

### Do Document

- **Architecture and patterns** - How the system is structured
- **Core components** - Folder management, media integration, React UI
- **Data flows** - How folders are created, assigned, and managed
- **Public APIs** - Interfaces other developers use
- **Business logic** - Why certain decisions are made
- **Integration points** - WordPress hooks and media library integration
- **Configuration** - Important settings and their impact
- **Non-obvious behavior** - Things that aren't self-evident
- **Behavioral rules** - What the system enforces

### Don't Document

- **Implementation details** - Specific method internals (use code comments)
- **Obvious patterns** - Standard WordPress/PHP conventions
- **Temporary code** - TODOs, experimental features
- **Auto-generated content** - Build artifacts, vendor code
- **Private helper methods** - Internal utilities
- **Exact parameter signatures** - Unless it's a public API contract

## Standard Documentation Structure

Follow this flexible pattern (general -> specific -> connected):

**1. Title & Overview** (Required)
- Clear title + 2-3 sentence overview

**2. Main Concepts** (Required)
- Key principles/components readers need first

**3. Detailed Description** (Required)
- In-depth content with topic-specific sections:
  - **Architecture:** Components, Data Flow, Lifecycle, Configuration
  - **Plugin:** Bootstrap, Hooks, Integration
  - **Media Library:** Folder operations, UI integration, React components
  - **Security:** Validation, Sanitization, Capabilities

**4. Related Documentation & References** (Optional)
- Internal links: `[Folder Management](01_2_ARCH_folder-management.md)`
- External links: WordPress Codex, PHP docs, specifications

**Key principle:** Adapt freely - these guide structure, not dictate it. The goal is clarity, not conformity.
