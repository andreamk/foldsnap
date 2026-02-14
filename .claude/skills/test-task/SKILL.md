---
description: Generate manual test task for QA/Support team based on PR changes
argument-hint: [target-branch]
model: sonnet
disable-model-invocation: true
---

Generate a focused manual test task for QA/Support team by analyzing code changes between current branch and ${1:-origin/master}.

## Workflow

1. **Launch pr-analyzer agent** to analyze all file changes and generate structured file-by-file summary
2. **Check automated test coverage** to identify what's already tested
3. **Generate simple test task** focusing only on what needs manual verification

## Step 1: Analyze Changes

Use the **pr-analyzer agent** to get a structured summary of all changes. The agent will:
- Analyze each changed file
- Identify the type of change (new feature, refactoring, bugfix, UI change, etc.)
- Explain the rationale and impact

## Step 2: Check Automated Test Coverage

After receiving the pr-analyzer output, check what automated tests exist:

```bash
git diff --name-only ${1:-origin/master}...HEAD | grep -E "^tests/.*Tests\.php$"
```

Review the test files to understand what's already covered by automated tests.

## Step 3: Identify Manual Test Needs

From the pr-analyzer output, identify what needs manual testing:

**SKIP manual testing for:**
- Logic already covered by automated unit tests
- Internal refactoring with no UI/behavior change
- Code quality improvements (phpcs, phpstan fixes)
- Documentation-only changes

**REQUIRE manual testing for:**
- UI changes (folder tree, media grid, drag-and-drop interactions)
- End-to-end workflows (folder creation, media assignment, bulk operations)
- User-facing messages or notifications
- Features that depend on external factors (user actions, hook triggers)
- Scenarios that can't be unit tested (browser behavior, AJAX interactions, React component interactions)

## Step 4: Identify Special Scenarios and Testing Mechanisms

From the analysis, look for scenarios that need special setup to test.

**CRITICAL: Before suggesting "How to Trigger" for any test scenario:**

1. **Re-read the pr-analyzer output** for any testing mechanisms the developer may have added (constants, parameters, filters, debug modes, etc.)
2. **Check if the code provides a simpler way** to trigger the condition than external environment changes
3. **Always prefer code-provided mechanisms** over complex external setup

> **Rationale:** If a developer adds code to handle a specific scenario (like a specific feature), they likely also added a way to trigger/test that scenario. Using these built-in mechanisms is simpler and more reliable than complex environment setup.

**Only if no code-provided mechanism exists**, fall back to environment setup:

| Code Pattern | Test Scenario | Fallback Trigger |
|--------------|---------------|------------------|
| Folder tree changes | UI rendering | Navigate to Media Library and verify sidebar |
| Drag-and-drop changes | Interaction | Drag files between folders |
| REST API changes | Data operations | Create/move/delete folders via UI |
| Bulk operations | Multi-file handling | Select multiple files and assign to folder |

Include **how to trigger** these scenarios in the test task, prioritizing simplicity.

## Step 5: Generate Test Task

Use this EXACT format (keep it concise - under 60 lines for simple PRs):

```markdown
# Pull Request: [Brief Title from pr-analyzer summary]

**Test On:** WP last version, PHP 8.4

---

## Summary

[2-3 sentences from pr-analyzer: What changed and why. Written for non-technical reader.]

---

## Preconditions

1. WordPress with FoldSnap installed and activated
2. Debug logging enabled (`WP_DEBUG_LOG = true`)
[Add others only if specifically needed for this PR]

---

## Steps to Test

### Test 1: [Primary Feature/Change]

1. [Specific action with exact menu path]
2. [Next action]
3. [Continue...]

**Expected Result:**
- [What should happen - be specific]

---

### Test 2: [Special Scenario - only if applicable]

**How to trigger:** [Explain setup needed]

1. [Steps...]

**Expected Result:**
- [What should happen]

---

## Negative Checks

- No PHP errors in `debug.log` related to [changed components from analysis]
- No JavaScript console errors related to FoldSnap
- [Other specific things that should NOT happen]

---

## Cleanup

- [Only if test creates persistent changes]

---

**Important:** After testing, verify `debug.log` has no unexpected errors/warnings.
```

## Guidelines

**KEEP IT SIMPLE:**
- 1-3 test scenarios maximum
- Each test should take < 5 minutes
- Write for someone who knows WordPress admin but not code
- Don't test what automated tests already cover

**BE SPECIFIC:**
- Use exact menu paths: "Navigate to **Media → Library** in WordPress admin"
- Describe what to look for: "A folder tree sidebar should appear on the left"
- State expected results clearly: "Files should move to the selected folder"

**FROM PR-ANALYZER OUTPUT, EXTRACT:**
- Main feature/change for Test 1
- UI changes that need visual verification
- Special scenarios that need specific setup
- Components changed (for Negative Checks)

**DON'T INCLUDE:**
- Multiple variations of the same test
- Edge cases covered by unit tests
- Technical implementation details
- Overly detailed steps for basic WordPress operations

## Output

Provide the test task inside triple backticks for easy copy-paste.
