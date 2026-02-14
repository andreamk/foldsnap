---
description: Write tests following best practices from test writing guide. Use when new business logic, endpoints, or features need test coverage.
argument-hint: <description of what to test>
allowed-tools: Bash, Task, Read, Grep, Glob, Edit, Write
---

Write tests based on actual code behavior, following practices from `docs/99_2_APPENDIX_write_tests_guide.md`

**Usage:**
```bash
/write-tests "test folder creation and hierarchy management"
/write-tests "test REST API endpoint permissions for different user capabilities"
/write-tests "test media file assignment to folders"
```

**MANDATORY PROCESS (SEQUENTIAL):**

1. **Analyze Target Code**
   - Search codebase for implementation
   - Read code and understand exact behavior
   - **NO guessing - only verified behavior**

2. **Find Similar Tests**
   - Search tests/ for related functionality
   - Read 2-3 existing test files
   - Document patterns: helpers, assertions, structure
   - **Reuse existing patterns from tests/TestsUtils/**

3. **Write FIRST Test Only**
   - Most important scenario (happy path or critical case)
   - **Stop after writing ONE test**

4. **Run and Validate FIRST Test**
   - Execute: `composer phpunit --filter=test_method_name` (PHP 8.0+)
   - OR: `composer phpunit-74 --filter=test_method_name` (PHP 7.4)
   - **If fails: STOP and DEBUG - see "When Tests Fail" below**
   - If passes: proceed to step 5

5. **Write Remaining Tests**
   - Follow same structure as validated first test
   - Run full file when done

**Critical Rules:**
- Follow all practices from `docs/99_2_APPENDIX_write_tests_guide.md`
- WordPress capabilities: test BOTH allowed AND denied cases
- **NEVER write 20 tests at once** - causes cascading errors
- PHPUnit syntax (extends TestCase, NOT Pest)
- Use TestsUtils helpers for common operations

**When Tests Fail:**
1. STOP - read error message completely
2. Search documentation (WebSearch "WordPress testing [feature]" or check WordPress Codex)
3. Find working examples in existing tests (tests/Unit/, tests/Feature/)
4. Check tests/TestsUtils/ for available helpers
5. Make ONE fix based on docs/examples
6. NEVER try random syntax without understanding error
