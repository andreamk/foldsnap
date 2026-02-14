---
description: Analyze current changes, generate a commit message, and commit after user approval
argument-hint: [-y]
allowed-tools: Bash(git status:*), Bash(git diff:*), Bash(git add:*), Bash(git commit:*), Bash(git log:*), AskUserQuestion
disable-model-invocation: true
---

## Instructions

You are a Git commit assistant. Your task is to create a meaningful commit message for the current changes and execute the commit after user approval.

### Arguments

- `-y` (optional): Auto-approve and commit immediately without asking for confirmation

### Workflow

1. **Analyze changes**:
   - Run `git status` to see modified/new files
   - Run `git diff HEAD` to see the actual changes

2. **Generate commit message**:
   - **First line**: Main change summary (max 72 characters, imperative mood)
   - **Additional lines** (if needed): Provide context or list multiple changes
   - Use imperative mood: "Add", "Update", "Fix", "Remove"
   - Be specific and concise
   - Structure:
     ```
     Main summary of the most important change

     Additional context or secondary changes if needed
     Bullet points are ok for multiple related changes
     ```

3. **Ask for approval** (skip if `-y` flag is provided):
   - Show the proposed commit message
   - Use AskUserQuestion with options:
     - "Commit with this message"
     - "Let me write a custom message"

4. **Execute commit**:
   - If approved or auto-approved:
     - Stage all changes with `git add -A`
     - Commit with the message
     - Show the commit hash
   - If custom message requested:
     - Ask user to provide their message
     - Stage and commit with user's message
     - Show the commit hash

### Important Rules

- **Stage everything**: Use `git add -A` to include all changes
- **First line is key**: The most important change goes in the first line
- **Concise but complete**: Can be multi-line, but keep it concise

### Example Messages

**Simple single-line:**
```
Add folder tree sidebar component to media library
```

**Multi-line with context:**
```
Add drag-and-drop folder management for media files

Implement React-based folder tree with drag support
Add REST API endpoints for folder CRUD operations
Register custom taxonomy for media folders
```

**Fix with context:**
```
Fix media files disappearing after folder reassignment

Add taxonomy term validation before attachment update
Add test case for empty folder edge case
```
