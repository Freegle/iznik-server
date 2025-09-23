#!/bin/bash
# Wrapper script to run PHPUnit tests with chat_notify stopped

echo "Stopping chat_notify background job..."
# Create abort file to signal background jobs to stop
touch /tmp/iznik.mail.abort

# Kill any existing chat_notify processes
pkill -f chat_notifyemail_user2user.php 2>/dev/null

echo "Running PHPUnit tests with coverage..."

# Handle path arguments - if they start with /var/www/iznik/, make them relative
TEST_PATH="$@"
if [[ "$TEST_PATH" == /var/www/iznik/* ]]; then
    # Remove the /var/www/iznik/ prefix to make it relative
    TEST_PATH="${TEST_PATH#/var/www/iznik/}"
    echo "Adjusted test path to: $TEST_PATH"
fi

# Debug: Show the full command being run
echo "Debug: Full test path argument: '$TEST_PATH'"
echo "Debug: Working directory: $(pwd)"

# Debug: Check if xdebug is available and configured
php -m | grep -i xdebug && echo "Debug: Xdebug extension is loaded" || echo "Debug: WARNING - Xdebug extension NOT loaded"
php -i | grep xdebug.mode && echo "Debug: Xdebug mode configuration found" || echo "Debug: WARNING - No xdebug.mode configuration"

# Pass all arguments to the PHPUnit test runner with coverage generation
# Enable xdebug coverage mode for this run - must export to work properly
cd /var/www/iznik
export XDEBUG_MODE=coverage
echo "Debug: Set XDEBUG_MODE=coverage"

# Debug: If we're running all tests, list what we should find
if [[ "$TEST_PATH" == "/var/www/iznik/test/ut/php/" ]] || [[ "$TEST_PATH" == "test/ut/php/" ]]; then
    echo "Debug: Running full test suite from: $TEST_PATH"
    echo "Debug: Counting test files in $TEST_PATH..."
    find $TEST_PATH -name "*Test.php" 2>/dev/null | wc -l | xargs echo "Debug: Found test files:"
    echo "Debug: First 5 test files found:"
    find $TEST_PATH -name "*Test.php" 2>/dev/null | head -5
fi

echo "Debug: Running PHPUnit command..."
echo "Debug: Command: php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --coverage-clover /tmp/phpunit-coverage.xml $TEST_PATH"

# Run PHPUnit with additional debug to see what tests it's actually running
# Remove --stop-on-failure to let all tests run
# Also remove --debug to reduce output verbosity
# Use TeamCity output format for better CI integration
echo "Debug: Running PHPUnit with command:"
echo "php /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --coverage-clover /tmp/phpunit-coverage.xml --teamcity $TEST_PATH"

# First, check if PHPUnit binary exists and is executable
if [ ! -f /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit ]; then
    echo "ERROR: PHPUnit binary not found at /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit"
    exit 1
fi

# Check PHP memory limit and other relevant settings
php -r "echo 'Debug: PHP memory_limit = ' . ini_get('memory_limit') . PHP_EOL;"
php -r "echo 'Debug: PHP max_execution_time = ' . ini_get('max_execution_time') . PHP_EOL;"

# Set unlimited memory and execution time for PHPUnit
export PHP_MEMORY_LIMIT=-1

# Run PHPUnit with error reporting and unlimited memory
# Use teamcity output format for better CI integration
php -d memory_limit=-1 -d max_execution_time=0 /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --configuration /var/www/iznik/test/ut/php/phpunit.xml --coverage-clover /tmp/phpunit-coverage.xml --teamcity $TEST_PATH 2>&1 | tee /tmp/phpunit-debug.log

# Get the exit code from the pipeline (PIPESTATUS[0] is the exit code of the first command in the pipe)
TEST_EXIT_CODE=${PIPESTATUS[0]}

echo "Debug: First 20 lines of PHPUnit debug output:"
head -20 /tmp/phpunit-debug.log

echo "Debug: PHPUnit execution completed with exit code: $TEST_EXIT_CODE"

# Debug: Check what was actually run
echo "Debug: Checking test execution results..."
if [ $TEST_EXIT_CODE -eq 0 ]; then
    echo "Debug: PHPUnit reports success (exit code 0)"
else
    echo "Debug: PHPUnit reports failure (exit code $TEST_EXIT_CODE)"

    # Show failure details when tests fail
    echo ""
    echo "============ TEST FAILURE DETAILS ============"

    # Look for TeamCity failure messages which are more structured
    echo "Extracting test failures from TeamCity output..."
    grep "##teamcity\[testFailed" /tmp/phpunit-debug.log 2>/dev/null | while read -r line; do
        # Extract test name and message from TeamCity format
        test_name=$(echo "$line" | sed -n "s/.*name='\([^']*\)'.*/\1/p")
        message=$(echo "$line" | sed -n "s/.*message='\([^']*\)'.*/\1/p")
        details=$(echo "$line" | sed -n "s/.*details='\([^']*\)'.*/\1/p")

        if [ -n "$test_name" ]; then
            echo ""
            echo "❌ FAILED: $test_name"
            [ -n "$message" ] && echo "   Message: $message"
            [ -n "$details" ] && echo "   Details: $details" | sed 's/|n/\n   /g'
        fi
    done || echo "No TeamCity failure messages found"

    # Look for assertion failures in non-TeamCity format
    echo ""
    echo "Searching for assertion failures..."
    grep -B 2 -A 5 "Failed asserting\|AssertionFailedError" /tmp/phpunit-debug.log 2>/dev/null | head -100 || echo "No assertion failures found"

    # Look for PHP errors or exceptions
    echo ""
    echo "Searching for PHP errors or exceptions..."
    grep -B 2 -A 5 "Fatal error\|PHP Warning\|PHP Notice\|Exception:" /tmp/phpunit-debug.log 2>/dev/null | head -50 || echo "No PHP errors found"

    # Show the final summary
    echo ""
    echo "Test summary:"
    tail -20 /tmp/phpunit-debug.log | grep -E "Tests:|OK \(|FAILURES!|Errors:|Skipped:" || echo "No test summary found"

    # Also show any skipped tests
    echo ""
    echo "Skipped tests:"
    grep "##teamcity\[testIgnored" /tmp/phpunit-debug.log 2>/dev/null | while read -r line; do
        test_name=$(echo "$line" | sed -n "s/.*name='\([^']*\)'.*/\1/p")
        message=$(echo "$line" | sed -n "s/.*message='\([^']*\)'.*/\1/p")
        [ -n "$test_name" ] && echo "  • $test_name: $message"
    done || echo "No skipped tests found"

    echo "=============================================="
    echo ""
fi

# Debug: List any PHPUnit output files
echo "Debug: Checking for PHPUnit output files in /tmp..."
ls -la /tmp/phpunit* 2>/dev/null || echo "Debug: No phpunit files found in /tmp"

# Always check if coverage file was created, regardless of test outcome
if [ ! -f /tmp/phpunit-coverage.xml ]; then
    echo "ERROR: Coverage file was not generated at /tmp/phpunit-coverage.xml"

    # Debug: Check if this might be a single test that doesn't generate coverage
    echo "Debug: Test path was: '$TEST_PATH'"
    if [[ "$TEST_PATH" == *"adminTest"* ]]; then
        echo "Debug: Running adminTest specifically - this appears to be a single test file"
    fi

    # If tests passed but no coverage, that's a failure
    if [ $TEST_EXIT_CODE -eq 0 ]; then
        echo "Tests passed but coverage generation failed - marking as failure"
        echo "Debug: This likely means only a subset of tests ran, or xdebug is not properly configured"
        TEST_EXIT_CODE=1
    fi
else
    echo "Coverage file generated successfully at /tmp/phpunit-coverage.xml"
    # Show file size for debugging
    ls -la /tmp/phpunit-coverage.xml
    # Also copy to output log location to ensure it persists
    cp /tmp/phpunit-coverage.xml /tmp/phpunit-coverage.xml.backup 2>/dev/null || true
fi

# Log the final exit code
echo "Final test exit code: $TEST_EXIT_CODE"

echo "Cleaning up and restarting chat_notify..."
# Remove abort file
rm -f /tmp/iznik.mail.abort

# Restart the chat_notify job in background
cd /var/www/iznik/scripts/cron && nohup php ./chat_notifyemail_user2user.php >> /tmp/iznik.chat_notifyemail_user2user.out 2>&1 &

echo "Tests completed with exit code: $TEST_EXIT_CODE"
exit $TEST_EXIT_CODE