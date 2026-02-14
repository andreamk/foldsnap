---
description: Generate release notes from a tag to current branch
argument-hint: <version>
allowed-tools: Bash(git rev-parse:*), Bash(git log:*), Bash(gh pr view:*), Bash(gh pr list:*), Bash(git branch:*)
disable-model-invocation: true
---

Generate a changelog comparing tag v$ARGUMENTS to the current branch HEAD.

## Steps

1. Verify tag `v$ARGUMENTS` exists: `git rev-parse v$ARGUMENTS`
   - If not found, output error and STOP

2. Get current branch name: `git branch --show-current`
   - Store this for use in the title

3. Extract PR numbers from merge commits between tag and current branch:
   ```bash
   git log v$ARGUMENTS..HEAD --oneline --merges | grep -oP '#\K[0-9]+' | sort -u
   ```
   This approach is more reliable than date-based GitHub CLI queries.

4. Build two separate lists:

   **List A - PR Details** (for each PR number from step 3):
   ```bash
   gh pr view <PR_NUMBER> --json number,title,body,mergedAt
   ```
   Run these calls in parallel (multiple Bash tool invocations) to minimize latency.

   **List B - All Commits** (complete commit history from tag to current branch):
   ```bash
   git log v$ARGUMENTS..HEAD --no-merges --format="%h %s"
   ```

5. Cross-reference the two lists:
   - **PR bodies** (List A): High-level context, "why", and user impact - PRIMARY SOURCE
   - **Commit messages** (List B): Granular technical details - VERIFICATION SOURCE

   Use this cross-reference to:
   - Verify PR body accurately reflects the actual commits
   - Fill gaps when PR body is empty or vague
   - Identify orphan commits not covered by any PR
   - Detect discrepancies between PR description and actual changes

6. Synthesize changelog from cross-referenced data:
   - Prefer PR body language for user-facing descriptions
   - Use commit messages to verify technical accuracy
   - If PR body is empty, derive description from associated commits

## Output Format

Output the changelog inside a markdown code block (triple backticks) so the user can easily copy the raw markdown.

```markdown
## Changelog from v$ARGUMENTS to <current-branch-name>

## Public Changes

<summary>
Write a concise summary in plain, non-technical language.
Focus ONLY on user-facing changes.
Describe user benefits: what problems are solved, what's improved, what's new.
Do NOT mention internal/developer changes in this summary.
</summary>

### Plugin:

[NEW] Completely new functionality that didn't exist before
[UPD] Enhancements to existing features, improvements, additions to existing functionality
[FIX] Corrections to broken or incorrect behavior

## Internal Changes

<summary>
Write a concise summary in plain, non-technical language accessible to anyone.
Explain the PURPOSE and IMPACT of internal changes (e.g., "improved code reliability", "better test coverage to catch bugs earlier").
The changelog entries below can be technical, but this summary should be understandable by non-developers.
</summary>

[NEW] New internal tools or testing infrastructure
[UPD] Refactors, architecture improvements, code quality enhancements
[FIX] Internal bug fixes, CI/CD corrections
```

**Note:**
- Omit the **Internal Changes** section completely if there are no internal/developer changes
- Not all tags need to be present in each section - only include the relevant changes
- **Always maintain order**: [NEW] first, then [UPD], then [FIX] within each section
- Each changelog line must start with `[NEW]`, `[UPD]`, or `[FIX]` tag

## Classification

**Tags:**
- **[NEW]**: Completely new functionality that didn't exist before
- **[UPD]**: Enhancements, improvements, additions to existing features, performance optimizations
- **[FIX]**: Bug fixes, corrections to broken or incorrect behavior

**Internal Changes:**
- **[NEW]**: New internal tools, testing infrastructure, development utilities
- **[UPD]**: Refactors, architecture improvements, code quality enhancements, performance optimizations
- **[FIX]**: Internal bug fixes, CI/CD corrections, test fixes

## Guidelines

- One concise line per change (max two sentences for complex changes)
- Start each line with appropriate tag: `[NEW]`, `[UPD]`, or `[FIX]`
- **Maintain order within each section**: all [NEW] entries first, then [UPD], then [FIX]
- High-level descriptions: WHAT changed and WHY, not HOW
- Synthesize from PR body when title is vague
- Group related PRs into single changelog entry when appropriate
- Classify changes correctly between Plugin and Internal Changes sections
- Skip PRs that are pure internal refactors (or add to Internal Changes)

**User-facing language**: Write for WordPress site owners, not developers. Describe the user benefit, not the implementation:
- "Added folder management sidebar to media library"
- "Improved drag-and-drop experience when moving files between folders"
- "Fixed an issue where folder counts were incorrect after bulk operations"
- ❌ `[FIX] Fixed taxonomy term count sync in REST API callback` (too technical)

**Internal Changes examples**:
- `[UPD] Refactored React state management for folder tree component`
- `[NEW] Added PHPUnit and Jest test coverage for folder operations`
- `[FIX] Fixed CI/CD pipeline failing on PHP 7.4`
