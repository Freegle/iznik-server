- true/false should always be TRUE/FALSE in PHP code.

## PHPUnit Test Refactoring Approach

Continue refactoring to move duplicate code within the phpunit tests into utility functions. Take the following approach:
1) Identify duplicate code in tests.
2) Use an existing utility method (extend if necessary) or create a new one.
3) Identify a test to change and make the changes. Take care when using parameters to utility functions to make sure they match the behaviour of the existing test.
4) Run the test to confirm it works.
5) Make changes and re-run the test - if it breaks then the changes you have just made are the problem. Review your changes vs the reference copy of the code.
6) Repeat 5) until your changes work.
7) Then go to 3) and identify another test.
8) Once you run out of tests double-check that there is no remaining code which should use the new utility method.
9) Go back to 1) and repeat until you've changed a reasonable amount of code.

You can run tests using a command like this:
"[sshConfig://IZNIK Docker]:/usr/bin/php" /var/www/iznik/composer/vendor/phpunit/phpunit/phpunit --