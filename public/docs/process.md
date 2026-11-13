# Process

> Safe command execution without shell expansion, exit code validation, stdin support, configurable timeouts.

Canonical: https://foundry.insightdevhub.ro/docs/process/  
Namespace: `Foundry\Process` · File: `Process.php` · Version 1.1 · PHP 8.3+

Executes external commands with verified exit codes, separated stdout/stderr, and timeout enforcement.

Natively, shell_exec() returns null on both error and empty output, exec() ignores exit codes unless explicitly captured, and arguments require manual escaping (posing command injection risks). Here, commands are supplied as an argument list: PHP launches them directly without invoking a shell, so arguments are never shell-interpreted (no escaping required, immune to injection).

Output is directed to temporary files rather than pipes: on Windows, stream_select() fails on pipes, and a saturated pipe deadlocks the process.

Error messages expose only the program name, omitting arguments (which may contain sensitive credentials). run() does include the last 1000 bytes of stderr when the exit code is not 0, to explain the failure; for programs that may print secrets or personal data on stderr, use runUnchecked() and handle the output yourself.

On timeout the process receives SIGTERM, then SIGKILL after 2 seconds if it is still running. Processes it started itself (e.g. through "sh -c") are not stopped and may keep running.

## Usage

```php
require_once 'Process.php';
use Foundry\Process;

$result = Process::run(['git', 'log', '-1', '--format=%H'], cwd: __DIR__);
echo $result['stdout'];

$result = Process::run(['mysqldump', '--result-file='.$file, 'database'], timeout: 600);

$result = Process::runUnchecked(['ping', '-n', '1', 'server']);  // does not throw on non-zero exit
if ($result['exitCode'] !== 0) { ... }
```

## Installation

```php
require_once __DIR__.'/lib/Utilities/Process.php';

use Foundry\Process;
```

## Methods

### run()

```php
public static function run(array $command, ?string $cwd = null, ?array $env = null, ?string $input = null, float $timeout = 60.0): array
```

Executes the command and throws an exception if it terminates with a non-zero exit code.

Parameters:
- `$command` (`list<string>`, required): The program and its arguments, e.g. ['git', 'status'].
- `$cwd` (`string|null`, default `null`): The working directory; null = current working directory.
- `$env` (`array<string, string>|null`, default `null`): Environment variables to merge over the current environment; null = inherit.
- `$input` (`string|null`, default `null`): Text sent to stdin.
- `$timeout` (`float`, default `60.0`): Maximum execution time in seconds.

Returns `array{exitCode: int, stdout: string, stderr: string}`.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: If the process fails to start, times out, or exits with code != 0.

### runUnchecked()

```php
public static function runUnchecked(array $command, ?string $cwd = null, ?array $env = null, ?string $input = null, float $timeout = 60.0): array
```

Same as run(), but does NOT throw an exception for a non-zero exit code. Startup failures and timeouts still throw exceptions.

Parameters:
- `$command` (`list<string>`, required): The program and its arguments.
- `$cwd` (`?string`, default `null`)
- `$env` (`array<string, string>|null`, default `null`)
- `$input` (`?string`, default `null`)
- `$timeout` (`float`, default `60.0`)

Returns `array{exitCode: int, stdout: string, stderr: string}`.

Throws:
- `InvalidArgumentException`: On invalid parameters.
- `RuntimeException`: If the process fails to start or times out.

