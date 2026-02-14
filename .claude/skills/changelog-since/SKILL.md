---
description: Generate changelog from a date with optional author filter
argument-hint: <YYYY-MM-DD> [author]
allowed-tools: Bash(git log:*), Bash(gh pr view:*), Bash(gh pr list:*), Bash(git branch:*)
disable-model-invocation: true
---

Generate a changelog from date $1 to current branch HEAD, optionally filtered by author $2.

## Steps

1. Parse arguments:
   - `$1` = date (required, format YYYY-MM-DD)
   - `$2` = author filter (optional, GitHub username)

2. Get current branch name: `git branch --show-current`
   - Store this for use in the title

3. Extract PR numbers from merge commits since the date:
   ```bash
   git log --since="$1" HEAD --oneline --merges | grep -oP '#\K[0-9]+' | sort -u
   ```

4. Build two separate lists:

   **List A - PR Details** (for each PR number from step 3):
   ```bash
   gh pr view <PR_NUMBER> --json number,title,body,mergedAt,author
   ```
   Run these calls in parallel (multiple Bash tool invocations) to minimize latency.

   If author filter `$2` is provided, only include PRs where `author.login` matches `$2`.

   **List B - All Commits** (complete commit history since the date):
   ```bash
   git log --since="$1" HEAD --no-merges --format="%h %s"
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
## Changelog from $1 to <current-branch-name>

### Plugin:

[NEW] Completely new functionality that didn't exist before
[UPD] Enhancements to existing features, improvements, additions to existing functionality
[FIX] Corrections to broken or incorrect behavior

### Developer Notes:

[NEW] New internal tools or testing infrastructure
[UPD] Refactors, architecture improvements, code quality enhancements
[FIX] Internal bug fixes, CI/CD corrections
```

**Note:**
- Omit the **Developer Notes** section if there are no internal changes
- Not all tags need to be present in each section - only include the relevant changes
- **Always maintain order**: [NEW] first, then [UPD], then [FIX] within each section
- Each changelog line must start with `[NEW]`, `[UPD]`, or `[FIX]` tag

## Classification

**Tags:**
- **[NEW]**: Completely new functionality that didn't exist before
- **[UPD]**: Enhancements, improvements, additions to existing features, performance optimizations
- **[FIX]**: Bug fixes, corrections to broken or incorrect behavior

**Developer Notes:**
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
- Classify changes correctly between Plugin and Developer Notes sections
- Skip PRs that are pure internal refactors (or add to Developer Notes)

**User-facing language**: Use WordPress/plugin terminology:
- "Added folder drag-and-drop support in media library"
- "Improved folder tree navigation performance"
- "Fixed media files not displaying after folder move"
- ❌ `[FIX] Fixed REST API endpoint returning wrong taxonomy term` (too technical)

**Developer Notes examples**:
- `[UPD] Refactored React component tree for better state management`
- `[NEW] Added Jest test coverage for folder operations`
- `[FIX] Fixed CI/CD pipeline failing on PHP 7.4`
