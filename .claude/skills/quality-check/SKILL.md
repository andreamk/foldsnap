---
description: Run automated quality agents based on code changes
argument-hint: [branch-name]
allowed-tools: Bash, Task, Read, Grep, Glob
model: opus
disable-model-invocation: true
---

Perform automated quality assurance by analyzing code changes and invoking appropriate specialized agents.

**Usage:**
- `/quality-check` - Compare current branch vs master (DEFAULT)
- `/quality-check <branch-name>` - Compare current branch vs specified branch

**Examples:**
```bash
# Before creating a PR to master (most common)
/quality-check

# Compare to custom branch
/quality-check develop
```

**Process:**

1. **Identify Changed Files**
   - Determine target branch: argument provided or "master" (default)
   - Get changed files: `git diff --name-status $TARGET_BRANCH...HEAD`
   - Note: Uses three-dot syntax (...) to compare from common ancestor (uses local branch state)

2. **Apply Decision Tree** (determine which agents to invoke)

   **Skip ALL agents when changes are ONLY:**
   - Config/settings changes (no logic)
   - Typo/comment/PHPDoc fixes
   - Pure renames (no behavior change)
   - Translation updates (POT files)
   - Asset changes (CSS/images without logic changes)
   - NOTE: JS/JSX files in `template/js/` are React source code, NOT asset changes — always evaluate them

   **Invoke agents based on these criteria:**

   **quality-security** - when touching:
   - Authentication/authorization code (capabilities, user permissions)
   - Database queries (ANY query modifications, especially wpdb operations)
   - User input handling (sanitization, validation)
   - File uploads/downloads
   - AJAX endpoints and REST API endpoints
   - Nonce generation/verification
   - WordPress hooks that handle user data
   - Media file operations

   **quality-code-efficiency** - when:
   - Adding new business logic (PHP or JS/React)
   - Modifying existing methods with logic
   - Writing verbose code that could be more concise
   - NOTE: Detects both duplication AND verbosity (framework-agnostic patterns)

   **quality-performance** - when touching:
   - Database queries (ANY modifications, wpdb operations)
   - Loops over collections (especially media file scanning)
   - File I/O operations
   - External API calls
   - Caching logic
   - React component rendering (unnecessary re-renders)

   **quality-patterns** - when touching:
   - Files in src/ with WordPress plugin code (Controllers, Models, Services, etc.)
   - Bootstrap/initialization files
   - WordPress hooks and filters implementation
   - REST API route registration and handlers
   - React components using WordPress packages
   - NOTE: Skip config-only changes or simple hook registrations without logic

   **quality-architecture** - when:
   - Creating new classes/services
   - Modifying 2+ related files
   - Cross-namespace changes (e.g., Core + Admin + Api)
   - Refactoring that changes structure

   **quality-test-coverage** - when:
   - Adding new business logic (methods with logic)
   - Modifying existing business logic behavior
   - Adding new AJAX/REST endpoints
   - Adding database operations (wpdb queries, entity persistence)
   - Fixing bugs (needs regression test)
   - NOTE: Skip config-only changes, typos, comments, simple getters/setters

   **quality-test-rigor** - when:
   - Adding new tests (PHP or JS)
   - Modifying existing tests
   - NOTE: Validates test quality and rigor, not coverage

   **quality-maintainability** - ALWAYS
   - Detects technical debt markers, magic values, complexity issues, and code hygiene problems

   **quality-comments** - ALWAYS
   - Validates that code comments are meaningful and not redundant

   **quality-docs** - ALWAYS
   - Analyzes code changes to determine if documentation updates are needed

3. **Invoke Agents in Parallel**
   - Use Task tool to launch all matching agents in a SINGLE message
   - Each agent gets the list of modified files for context
   - Agents run independently and in parallel
   - Multiple agents may analyze the same file (e.g., security + performance on queries) - this is intentional

4. **Present Consolidated Report**
   - Analyze and consolidate all agent outputs
   - Summary of which agents ran
   - Key findings from each agent (prioritized by severity)
   - Cross-reference findings between agents (e.g., security + performance on same code)
   - Actionable recommendations
   - Overall quality score

**Decision Rule:**
- **Default to YES**: When uncertain if an agent should run, invoke it (prefer false positives over false negatives)
- **Run in Parallel**: Always invoke all matching agents in a single message for efficiency
- **Avoid over-engineering**: Prefer simple, proportional fixes over complex abstractions

**Important:**
- This skill should be run AFTER completing code changes, not during
- Agents analyze code quality, they don't modify code
- Results are advisory - you decide which recommendations to implement
