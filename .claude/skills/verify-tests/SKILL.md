---
description: Verify tests follow best practices from test writing guide. Use after writing or modifying test files.
argument-hint: [path1] [path2] [path3] ...
allowed-tools: Bash, Task, Read, Grep, Glob
---

Verify that test files follow best practices defined in `docs/99_2_APPENDIX_write_tests_guide.md`.

**Usage:**
```bash
/verify-tests                                  # Verify all tests
/verify-tests tests/Feature/                   # Verify directory
/verify-tests tests/Unit/BootstrapTests.php    # Verify specific file
```

**Process:**

1. **Identify Target Files**
   - Paths provided → Verify specified test files/directories
   - No argument → Verify all files in `tests/` (excluding vendor)

2. **Invoke quality-test-rigor Agent**
   - Checks adherence to test writing guide
   - Validates test quality and pragmatism
   - Reports violations with specific examples

3. **Present Report**
   - Tests following best practices
   - Violations found (with line numbers)
   - Recommendations for improvements

**What Gets Verified:**
Adherence to practices in test writing guide (realistic scenarios, precision, pragmatism, code duplication, naming conventions)

**Not Verified:**
Test correctness or code coverage (use `/test-coverage` for coverage analysis)

**Example Output:**
```
tests/Unit/ - All tests follow best practices
tests/Feature/FolderTests.php:42 - Testing impossible scenario (negative folder depth)
tests/Feature/MediaAssignTests.php:156 - Duplicated setup code (15 lines), extract to TestsUtils helper
```
