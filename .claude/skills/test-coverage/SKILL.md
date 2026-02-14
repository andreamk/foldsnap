---
description: Analyze test coverage for specific files or directories
argument-hint: [path1] [path2] [path3] ...
allowed-tools: Bash, Task, Read, Grep, Glob
disable-model-invocation: true
---

Analyze test coverage for specified code to identify missing or inadequate tests.

**Usage:**
- `/test-coverage <file-path>` - Analyze specific file
- `/test-coverage <file1> <file2> <file3>` - Analyze multiple files
- `/test-coverage <directory-path>` - Analyze all PHP files in directory
- `/test-coverage <dir1> <dir2>` - Analyze multiple directories
- `/test-coverage` - Analyze all files in current directory

**Examples:**
```bash
# Check coverage for specific file
/test-coverage src/Core/Bootstrap.php

# Check coverage for multiple files
/test-coverage src/Core/Bootstrap.php src/Api/FolderEndpoint.php

# Check coverage for entire module
/test-coverage src/Core

# Check coverage for multiple modules
/test-coverage src/Core src/Api

# Check coverage for current directory
/test-coverage
```

**Process:**

1. **Determine Target Files**
   - Parse all arguments (space-separated paths)
   - For each path:
     - If file: add to analysis list
     - If directory: find all `*.php` files in directory (exclude tests/, vendor/, node_modules/)
   - If no arguments: analyze all `*.php` files in current directory (exclude tests/, vendor/, node_modules/)
   - Deduplicate final file list

2. **Invoke quality-test-coverage Agent**
   - Use Task tool to launch quality-test-coverage agent
   - Pass the list of target files for analysis
   - Agent will:
     - Identify testable business logic
     - Search for corresponding tests in tests/ directory
     - Report coverage gaps with priorities
     - Suggest specific test cases to add

3. **Present Coverage Report**
   - Summary of analyzed files
   - Coverage status (COVERED | GAPS | MISSING)
   - Prioritized list of missing tests
   - Suggested test file locations and structure

**What Gets Analyzed:**
- Public methods with business logic
- AJAX/REST endpoints
- Database operations (wpdb queries, entity persistence)
- Folder management logic and taxonomy operations
- Media file assignment and organization
- Admin page controllers and data processing

**What Gets Skipped:**
- Config/settings files (no testable logic)
- Translation files (POT)
- Template files (template/ directory)
- Simple getters/setters
- PHPDoc/comments
- Build artifacts
- React components (use `npm test` for JS coverage)

**Important:**
- This is an analysis tool, it doesn't modify code
- Results are advisory - you decide which tests to add
- Focus on business-critical code first (Critical/High priority)
- Reference: [docs/99_2_APPENDIX_write_tests_guide.md](docs/99_2_APPENDIX_write_tests_guide.md)
