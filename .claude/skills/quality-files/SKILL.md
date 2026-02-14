---
description: Run all quality agents on specific files or directories
argument-hint: [path1] [path2] [path3] ...
allowed-tools: Bash, Task, Read, Grep, Glob
model: opus
disable-model-invocation: true
---

Run comprehensive quality analysis with ALL specialized agents on specified files.

**Usage:**
- `/quality-files <file-path>` - Analyze specific file
- `/quality-files <file1> <file2> <file3>` - Analyze multiple files
- `/quality-files <directory-path>` - Analyze all PHP/JS files in directory
- `/quality-files <dir1> <dir2>` - Analyze multiple directories
- `/quality-files` - Analyze all files in current directory

**Examples:**
```bash
# Analyze specific file with all agents
/quality-files src/Core/Bootstrap.php

# Analyze multiple modules
/quality-files src/Core src/Api

# Analyze React components
/quality-files src/js/components

# Analyze current directory
/quality-files

# Analyze core source files
/quality-files src/Core
```

**Process:**

1. **Identify Target Files**
   - Files/directories provided → find all PHP and JS/JSX files (exclude vendor/, node_modules/)
   - No arguments → analyze current directory

2. **Invoke Agents in Parallel**
   - Use Task tool to launch all matching agents in a SINGLE message
   - Each agent gets the list of files for context
   - Agents run independently and in parallel
   - Multiple agents may analyze the same file (e.g., security + performance on queries) - this is intentional

3. **Agents**
   Based on file content analysis:
   - `quality-code-efficiency` → Always
   - `quality-maintainability` → Always
   - `quality-comments` → Always
   - `quality-docs` → Always
   - `quality-security` → Auth/query/input/nonce/REST files
   - `quality-performance` → Query/loop/I/O/React render files
   - `quality-patterns` → WordPress/Plugin/React pattern files
   - `quality-test-coverage` → Business logic files
   - `quality-test-rigor` → Test files
   - `quality-architecture` → Multiple files or cross-module

4. **Present Consolidated Report**
   - Summary of which agents ran
   - Key findings from each agent
   - Actionable recommendations
   - Overall quality score

**Important:**
- This skill analyzes code quality, agents don't modify code
- Results are advisory - you decide which recommendations to implement
- Use this for targeted analysis of specific code areas
- Use `/quality-check` instead for pre-PR full diff analysis
