---
description: Generate GitHub pull request description comparing current branch to target
argument-hint: [target-branch]
model: sonnet
disable-model-invocation: true
---

Generate a professional GitHub pull request description by analyzing code changes between current branch and ${1:-origin/master}.

## Workflow

1. **Launch pr-analyzer agent** to analyze all file changes and generate structured file-by-file summary
2. **Process the agent's output** to create a polished PR description

## Requirements for PR Description

Use the pr-analyzer agent's structured summary to generate a PR description with these sections:

### Summary
- 2-3 sentences explaining the main purpose of this PR
- Focus on the "why" and high-level "what"
- Mention the primary goal/problem being solved

### Key Changes
Organize changes by category (pick relevant ones):
- **Architecture & Design**: Major architectural changes, new patterns
- **New Features/Components**: New functionality added
- **React/Frontend**: UI components, state management, user interactions
- **REST API**: New or modified endpoints
- **Refactoring**: Code improvements without behavior changes
- **Security Improvements**: Security-related changes
- **Performance**: Performance optimizations
- **Testing**: New test coverage
- **Documentation**: New docs or significant doc updates

For each category:
- Be specific about WHAT changed (use file/class names from agent report)
- Explain WHY (extract rationale from agent analysis)
- Keep it concise but concrete

### Technical Impact
- **Backward Compatibility**: How is it maintained (or breaking changes if any)
- **Migration Requirements**: What needs to happen for existing systems
- **Dependencies**: Any new dependencies or requirement changes
- **Testing Coverage**: Summary of test additions

## Formatting Guidelines

**DO:**
- Use bullet points for readability
- Reference specific file paths when relevant (in parentheses or as context)
- Include concrete details from agent analysis
- Group related changes together
- Highlight significant patterns identified by agent

**DON'T:**
- Include code snippets or line numbers
- Describe implementation mechanics (HOW code works)
- List every single file - focus on significant changes
- Be overly verbose - keep it professional and concise
- Copy the agent's raw output - transform it into a polished description

## Output Format

Provide the final PR description inside triple backticks for easy copy-paste:

```markdown
## Summary
[Your summary here]

## Key Changes
[Organized by category]

## Technical Impact
[Impact details]
```

**Balance specificity with abstraction**: Mention concrete changes (what was added/removed/modified) but avoid implementation details. Focus on functional impact and architectural decisions.
