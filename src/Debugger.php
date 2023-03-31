<?php
/**
 * Created by PhpStorm
 * Date 2023/3/28 15:18
 */

namespace Chance\SwowDebug;

use Swow\Buffer;
use Swow\Coroutine;
use Swow\Socket;
use Swow\Stream\VarStream;
use function Swow\Debug\registerExtendedStatementHandler;

class Debugger
{
    protected static self $instance;
    protected string $clientHost;
    protected int $clientPort;
    protected string $mainIdeKey;
    protected Socket $mainConn;
    protected Socket $debugConn;
    protected array $breakPoints = [];
    protected bool $daemon = false;
    protected bool $singleStep = false;
    protected array $unexecutedCommands = [];

    final protected static function getInstance(): static
    {
        return self::$instance ?? (self::$instance = new static());
    }

    public static function setClientHost(string $clientHost): void
    {
        self::getInstance()->clientHost = $clientHost;
    }

    public static function setClientPort(int $clientPort): void
    {
        self::getInstance()->clientPort = $clientPort;
    }

    public static function setMainIdeKey(string $mainIdeKey): void
    {
        self::getInstance()->mainIdeKey = $mainIdeKey;
    }

    protected function checkBreakPointHandler(): void
    {
        $this->breakPointHandler ??= registerExtendedStatementHandler([$this, 'breakPointHandler']);
    }

    protected static function breakPointHandler(): void
    {
        $t = static::getInstance();
        if ($t->singleStep) {
            $t->singleStep = false;
            $t->debugConnect();
            return;
        }

        $coroutine = Coroutine::getCurrent();
        $file = $coroutine->getExecutedFilename(2);
        $line = $coroutine->getExecutedLineno(2);
        $fullPosition = "$file:$line";
        if (in_array($fullPosition, $t->breakPoints)) {
            $t->debugConnect();
        }
    }

    public static function setBreakPoint(string $point): bool|int|string
    {
        $t = self::getInstance();
        $t->checkBreakPointHandler();
        if (!in_array($point, $t->breakPoints)) {
            $t->breakPoints[] = $point;
        }
        return array_search($point, $t->breakPoints);
    }

    public static function removeBreakPoint($key): void
    {
        unset(self::getInstance()->breakPoints[$key]);
    }

    protected static function getCoroutineTraceDiffLevel(Coroutine $coroutine, string $name): int
    {
        static $diffLevelCache = [];
        if (isset($diffLevelCache[$name])) {
            return $diffLevelCache[$name];
        }

        $trace = $coroutine->getTrace();
        $diffLevel = 0;
        foreach ($trace as $index => $frame) {
            $class = $frame['class'] ?? '';
            if (
                is_a($class, self::class, true)
                || is_a($class, Dbgp::class, true)
            ) {
                $diffLevel = $index;
            }
        }
        /* Debugger::breakPointHandler() or something like it are not the Debugger frame,
         * but we do not need to -1 here because index is start with 0. */
        if ($coroutine === Coroutine::getCurrent()) {
            $diffLevel -= 1;
        }

        return $diffLevelCache[$name] = $diffLevel;
    }

    public static function getCurrentCoroutineTrace(): array
    {
        $coroutine = Coroutine::getCurrent();
        $level = self::getCoroutineTraceDiffLevel($coroutine, __FUNCTION__);
        return array_filter($coroutine->getTrace($level), function ($t) {
            return $t['function'] !== '{closure}';
        });
    }

    public static function getDefinedVars($d): array
    {
        $coroutine = Coroutine::getCurrent();
        $level = self::getCoroutineTraceDiffLevel($coroutine, __FUNCTION__) + 1 + $d;
        return $coroutine->getDefinedVars($level);
    }

    public static function runOnTTY(): static
    {
        return static::getInstance()->run();
    }

    public function run(): static
    {
        $this->mainConnect();

        return $this;
    }

    protected function mainConnect(): void
    {
        if (!isset($this->mainConn)) {
            $this->mainConn = new VarStream();
            $this->mainConn->connect($this->clientHost, $this->clientPort);
            $this->mainConn->send(Dbgp::init($this->mainIdeKey));
        }

        while (true) {
            $buffer = new Buffer(Buffer::COMMON_SIZE);
            $length = $this->mainConn->recv($buffer);
            if ($length === 0) {
                continue;
            }
            $command = $buffer->toString();
            if (!$this->processingCommands($this->mainConn, $command)) {
                break;
            }
        }
    }

    protected function debugConnect(): void
    {
        if (!isset($this->debugConn)) {
            $this->debugConn = new VarStream();
            $this->debugConn->connect($this->clientHost, $this->clientPort);
            $this->debugConn->send(Dbgp::init($this->mainIdeKey));
        }

        if ($this->unexecutedCommands) {
            [$command, $args] = $this->unexecutedCommands;
            $this->unexecutedCommands = [];
            if ($message = Dbgp::{$command}($command, ...$args)) {
                $this->debugConn->send($message);
            }
        }

        while (true) {
            $buffer = new Buffer(Buffer::COMMON_SIZE);
            $length = $this->debugConn->recv($buffer);
            if ($length === 0) {
                continue;
            }
            $command = $buffer->toString();
            if (!$this->processingCommands($this->debugConn, $command)) {
                break;
            }
        }
    }

    protected function processingCommands(Socket $conn, string $commands): bool
    {
        foreach (array_filter(explode("\x00", $commands)) as $command) {
            [$command, $args] = $this->parseCommand($command);
            if ($this->mainConn === $conn && $command === 'stack_get') {
                if (!$this->daemon) {
                    Coroutine::run(function () {
                        $this->mainConnect();
                    });
                    return false;
                }
                continue;
            }
            if (isset($this->debugConn) && $this->debugConn === $conn && in_array($command, ['step_over', 'run'])) {
                $this->unexecutedCommands = [$command, $args];
                if ($command === 'step_over') {
                    $this->singleStep = true;
                }
                return false;
            }
            if (method_exists(Dbgp::class, $command)) {
                if ($message = Dbgp::{$command}($command, ...$args)) {
                    $conn->send($message);
                }
            }
        }
        return true;
    }

    protected function parseCommand(string $command): array
    {
        $tokens = explode(' ', $command);
        $command = array_shift($tokens);

        $args = [];
        $name = '';
        foreach ($tokens as $token) {
            if (str_starts_with($token, '-')) {
                $name = substr($token, 1);
                $args[$name] = true;
            } else {
                $args[$name] = $token;
            }
        }

        return [$command, array_values($args)];
    }
}