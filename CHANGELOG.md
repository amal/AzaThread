CHANGELOG
=========

## Version 1.1 (work in progress)


26.05.2013
- **MINOR:** `Thread::cleanAll()` method added (amal)
- **IMPROVED:** Better waiting for dead child process with `pcntl_waitpid` (amal)
- **IMPROVED:** The child is now explicitly notify a parent before death (amal)
- **MINOR:** Support for the updated AzaLibEvent API (amal)
- **CHANGED:** Confirmation mode (eventLocking) removed for support for the newest libevent (amal)

25.05.2013
- **FEATURE:** Support for the newest libevent has been added (amal)
- **MINOR:** Added test for huge events data (amal)

24.05.2013
- **IMPROVED:** Separate detailed documentation in two languages (amal)

16.05.2013
- **MINOR:** Small improvements and fixes (amal)
- **IMPROVED:** IPC optimizations (8-10% speedup) (amal)
- **FEATURE:** Simple API for closure thread - `SimpleThread` class (amal)
- **FEATURE:** New `onCleanup` hook (amal)
- **FEATURE:** Thread now can be configured externally (amal)

13.05.2013
- **FEATURE:** Threads statistics collection + API for accessing it via pool (Issue #4, amal)
- **MINOR:** Additional tests (empty argument/result, thread hooks) (amal)

12.05.2013
- **FIXED:** Inconsistent behaviour with `multitask=false` removed (Issue #6, amal)

11.05.2013
- **IMPROVED:** Full code coverage (amal)
- **IMPROVED:** Speedup in IPC. Overall, IPC accelerated by 15-50% compared with the v1.0 release (amal)
- **FIXED:** Job identifier added to discard orphaned results (amal)
- **MINOR:** Small typo fix (amal)

09.05.2013
- **MINOR:** Error callbacks for buffer events (amal)

05.05.2013
- **FIXED:** Discarding of duplicate job packets. This could occur when the worker dies (amal)

04.05.2013
- **FIXED:** Thread can no longer start new job while result of previous is not fetched (amal)
- **MINOR:** More tests for sync mode, better groups of tests (amal)
- **FIXED:** Confirmed bug fix for resources damage with child death (Issue #2, amal)

03.05.2013
- **IMPROVED:** Thread pool structure API improvements (amal)

28.04.2013
- **CHANGED:** Debug flag moved to the end of arguments list in pool and thread constructors (amal)

27.04.2013
- **FIXED:** Master pipe read event cleanup after worker death added (cause can be damaged) (amal)
- **IMPROVED:** New tests, better feature and code coverage (amal)
- **CHANGED:** Main processing errors handling now works without exceptions (amal)
- **FIXED:** Removed incorrect event loop cleanup in some cases (amal)

26.04.2013
- **CHANGED:** More thoughtful public getters instead of public/protected properties (amal)
- **MINOR:** Better PhpDocs and some code reorganization (amal)
- **IMPROVED:** Added cleanup for all other (redundant) threads and pools in child after forking (amal)

25.04.2013
- **FIXED:** Signal handler in master process with many different threads now works normally (amal)
- **FIXED:** Confirmation mode (eventLocking) for worker events fixed (amal)
- **MINOR:** Debugging improvements. PID update for every call, more data in logs (amal)

21.04.2013
- **MINOR:** Many new tests, better feature and code coverage (amal)
- **MINOR:** Many small optimizations, improvements and code cleanup (amal)
- **FEATURE:** Optional arguments mapping (amal)
- **FIXED:** Support for several different threads used at one time not in pool (amal)
- **FIXED:** Worker interval to check parent process did not restarted after first iteration (amal)
- **FIXED:** Worker job waiting timeout missed the first iteration (amal)
- **FIXED:** Tags stripping for debug messages could corrupt them (`strip_tags` replaced with regex) (amal)

17.04.2013
- **MINOR:** Better event listeners cleanup (amal)
- **MINOR:** `@return $this` in PhpDocs (amal)

14.04.2013
- **FIXED:** Fixed issue with exceptions in events triggering (amal)

13.04.2013
- **FIXED:** `Thread::onFork` hook is now properly called in child after forking (Issue #7, amal)
- **MINOR:** Small thread improvements with code cleanup and refactoring (amal)
- **MINOR:** Unit tests structure improvements (amal)

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
