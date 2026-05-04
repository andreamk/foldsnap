---
description: Run automated quality agents based on code changes
argument-hint: [branch-name]
allowed-tools: Bash, Task, Read, Grep, Glob
model: opus
disable-model-invocation: true
---

Perform automated quality assurance by analyzing code changes and invoking appropriate specialized agents.

**Usage:**
- `/quality-check` - Compare current branch vs main (DEFAULT)
- `/quality-check <branch-name>` - Compare current branch vs specified branch

**Examples:**
```bash
# Before creating a PR to main (most common)
/quality-check

# Compare to custom branch
/quality-check develop
```

**Process:**

1. **Gather Context and Build Summary**
   - Determine target branch: argument provided or "main" (default)
   - Run these commands to collect raw context:
     - `git diff --name-status $TARGET_BRANCH...HEAD` (changed files list)
     - `git log $TARGET_BRANCH...HEAD` (full commit messages, NOT --oneline)
   - Note: Uses three-dot syntax (...) to compare from common ancestor (uses local branch state)
   - Write a **discursive context summary** (3-10 sentences) covering:
     - What the branch does overall (purpose, scope)
     - Key areas touched and why (based on commit messages)
     - Notable patterns (refactoring, new features, bug fixes, etc.)
   - Pass the context summary, the target branch, and the list of modified files to each sub-agent.

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

3. **Invoke Quality Agents in Parallel**
   - Use Task tool to launch all matching agents in a SINGLE message
   - Every agent receives the same three inputs: the context summary, the target branch, and the complete changed files list from step 1
   - The file list must be passed as-is with no filtering, grouping, or per-agent selection — all agents get all files
   - Each agent decides independently which files to analyze based on its own scope
   - Agents run independently and in parallel
   - Multiple agents may analyze the same file (e.g., security + performance on queries) - this is intentional

4. **Consolidate Raw Reports**
   - Collect all agent outputs into a single consolidated report
   - Group findings by agent
   - Do NOT filter or editorialize at this stage — preserve all raw findings exactly as reported

5. **Invoke Validation Agent**
   - Launch the **quality-validation** agent with:
     - The full consolidated report from step 4
   - The validation agent will:
     - Read the actual code referenced by each finding
     - Verify accuracy, proportionality, and project conventions
     - Classify each as: CONFIRMED, UNCERTAIN, or INVALID
   - Wait for the validation agent to complete before proceeding

6. **Assemble Final Report**
   - The validation agent returns only a verdicts table (CONFIRMED/UNCERTAIN/INVALID per finding)
   - Use the verdicts to build the final report from the original agent outputs:
     - **Confirmed Findings** — Include the FULL original agent content for each CONFIRMED finding
     - **Uncertain Findings** — Include the FULL original agent content + the validator's reason for uncertainty
     - **Invalid Findings** — Show only a summary table (finding + reason for removal). Do NOT include the original content.
   - Add validation statistics: total findings, confirmed, uncertain, removed (with percentage)
   - Add overall quality score
   - Cross-reference confirmed findings between agents (e.g., security + performance on same code)

**Decision Rule:**
- **Default to YES**: When uncertain if a quality agent should run, invoke it (prefer false positives over false negatives)
- **Run Quality Agents in Parallel**: Always invoke all matching quality agents in a single message for efficiency
- **Validation is Sequential**: The validation agent MUST run after all quality agents complete
- **Avoid over-engineering**: Prefer simple, proportional fixes over complex abstractions

**Important:**
- This skill should be run AFTER completing code changes, not during
- Agents analyze code quality, they don't modify code
- Results are advisory - you decide which recommendations to implement
