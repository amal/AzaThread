CHANGELOG
=========

## Version 1.1 (work in progress)


09.04.2013
- **IMPROVED:** `Thread::forkThread` method visibility changed to protected (Issue #3, amal)
- **MINOR:** Small code style, comments and tests improvements (amal)
- **FIXED:** Remove dependency on Daemon::getTimeForLog (Issue #1, amal)

08.04.2013
- **MINOR:** Massive README and CHANGELOG improvements (amal)


## Version 1.0 (27.02.2013)
- **MINOR:** Refactoring and improvements (amal)
- **FEATURE:** Travis CI support (amal)
- **FEATURE:** Composer support (amal)


## Version 0.9 (01.08.2012)
- **FIXED:** `pool->getState` visibility fix (protected => public) (amal)

10.04.12
- **FIXED:** 4096 bytes results limit (Issue amal/AzaThread#5, amal)
- **FIXED:** Fixed results error with SIGCHLD (Issue amal/AzaThread#4, amal)
- **CHANGED:** Massive component rewrite. Namespaces and PSR-0. (amal)


## Version 0.1 (25.01.2012)
21.12.11
- **FEATURE:** Thread events with pool (amal)

13.12.11
- First public release
