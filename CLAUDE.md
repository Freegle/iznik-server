- true/false should always be TRUE/FALSE in PHP code.

## PHPUnit Test Refactoring Approach

Continue refactoring to move duplicate code within the phpunit tests into utility functions. Take the following approach:
1) Identify duplicate code patterns in tests.
2) Use an existing utility method (extend if necessary) or create a new one.
3) **BATCH REFACTORING**: Once you've decided on a change pattern:
   a) Identify ALL tests that use this pattern 
   b) Refactor ALL of them in sequence without pausing
   c) Test each refactored file immediately after changes
   d) If any test breaks, fix it before moving to the next file
4) After completing a batch of refactoring for one pattern, verify all affected tests still work.
5) Move on to identify the next duplicate pattern and repeat the batch process.
6) Once you run out of patterns, double-check that there is no remaining code which should use the new utility methods.

**Key Principles:**
- No user prompting needed once a refactoring pattern is identified
- Batch refactor all instances of the same pattern 
- Test immediately after each file change
- Fix any issues before proceeding to the next file

**Comprehensive Refactoring Approach:**
- When editing any test file, perform a complete utility method review:
  1. **Review ALL existing utility methods** in IznikTestCase.php and IznikAPITestCase.php
  2. **Scan the entire test file** for patterns that could use existing utilities
  3. **Apply ALL applicable utility methods** in the same editing session
  4. **Don't just fix the original pattern** - optimize the whole file while you're there
- This comprehensive approach maximizes efficiency and ensures consistent improvements
- Examples of utility methods to look for:
  - `createTestUser()` - User creation
  - `createTestUserAndLogin()` - User creation + login
  - `addLoginAndLogin()` - Add login to existing user
  - `createTestUserWithMembershipAndLogin()` - User + membership + login
  - `createTestGroup()` - Group creation
  - `createTestUserWithMembership()` - User creation + group membership

**Error Handling:**
- If a test fails after refactoring, determine if it's due to your changes:
  1. Revert your refactoring changes to the file
  2. Run the test again to see if it passes in the original state
  3. If the original test also fails, the issue is pre-existing:
     - Note the pre-existing issue for reference
     - Re-apply your refactoring changes (they're not the cause)
     - Continue with the refactoring process
  4. If the original test passes, then your refactoring introduced the bug - fix it
- Only spend time debugging tests that your refactoring actually broke
- Pre-existing test failures should not block refactoring progress

## Running Tests

You can run tests using these command patterns:

### Preferred Test Execution Method (Most Reliable)
```bash
# For include/ tests - Run full test class with teamcity output:
php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --filter chatRoomsTest --test-suffix chatRoomsTest.php /var/www/iznik/test/ut/php/include --teamcity

# For api/ tests - Run full API test class with teamcity output:
php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --filter communityEventAPITest --test-suffix communityEventTest.php /var/www/iznik/test/ut/php/api --teamcity
```

### Alternative Methods (Context Dependent)
```bash
# Run all tests in a specific test class by filtering from the directory:
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter sessionTest test/ut/php/api/

# Run all tests in a specific test file:
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml test/ut/php/api/sessionTest.php

# Run a specific test method:
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter sessionTest::testGoogle test/ut/php/api/

# Run tests with teamcity output format:
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter sessionTest test/ut/php/api/ --teamcity
```

### Directory-Based Execution
```bash
# API tests (use directory for class name mismatches):
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter sessionAPITest test/ut/php/api/

# Include tests (can use specific file):
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter chatRoomsTest test/ut/php/include/chatRoomsTest.php

# Run specific test method from include directory:
php composer/vendor/phpunit/phpunit/phpunit --configuration test/ut/php/phpunit.xml --filter chatRoomsTest::testBasic test/ut/php/include/chatRoomsTest.php
```

### Important Notes
- **USE THE PREFERRED METHOD FIRST** - The full absolute path method with `--test-suffix` is most reliable
- Use directory filtering (e.g., `test/ut/php/api/`) when there are class name/filename mismatches  
- The `--filter` parameter accepts class names, method names, or patterns
- Add `--teamcity` for CI-friendly output format
- API test classes often have naming mismatches (file: `communityEventTest.php`, class: `communityEventAPITest`)